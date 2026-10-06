<?php
declare(strict_types=1);

/**
 * AI Filter — scores incoming articles with an LLM (Cloudflare Workers AI),
 * marks the low-relevance ones as read and labels the exceptional ones.
 *
 * Hooks into `freshrss_user_maintenance`, which FreshRSS fires once per user at
 * the start of every batch feed refresh, and:
 *   1. lists unread articles newer than a stored watermark (oldest first),
 *   2. handles each one according to its category mode: scored with the
 *      prompts, passed through untouched, or checked for advertising only,
 *   3. scores them in batches with Cloudflare Workers AI,
 *   4. marks low-scoring articles as read and labels exceptional ones,
 *   5. logs every decision to a per-user log shown on the configure page.
 *
 * State (watermark, last run) and the log live in the per-user extension
 * directory (data/users/<user>/extensions/AiFilter/), so nothing touches the
 * FreshRSS core and the extension survives FreshRSS upgrades.
 */
final class AiFilterExtension extends Minz_Extension {

	private const STATE_FILE = 'state.json';
	private const LOG_FILE = 'filter.log';
	private const LOCK_FILE = 'run.lock';
	private const LOG_MAX_LINES = 2000;
	private const LOCK_TTL = 900;	// seconds before a leftover lock is considered stale
	private const CF_TIMEOUT = 60;	// seconds per Cloudflare API call
	private const MODES = ['score', 'pass', 'ads'];

	/** Feedback from the last configure-page action ("Run now", "Reset prompts"), for display in the view. */
	public string $noticeMessage = '';

	/** @var array<string,mixed>|null */
	private ?array $conf = null;

	private ?int $topTagId = null;

	private float $startedAt = 0.0;

	// ============================================================
	// PROMPTS (defaults, editable per user on the configure page)
	// ============================================================

	public const UNIVERSAL_RULES = <<<'RULES'
Score each article from 0 to 10 for how worthwhile it is for the reader to read. Use the full range — do not cluster on round numbers.

Scale:
- 9-10: exceptional, a standout piece in its category
- 7-8: clearly worth reading
- 5-6: borderline — some merit, some weaknesses
- 3-4: weak, mostly low-value
- 0-2: reject — disqualified or worthless

Procedure:
1. Start at 5 (baseline, borderline).
2. If the article matches ANY hard disqualifier below, score 0-3 regardless of other signals.
3. Otherwise, apply the general signals below, then the category-specific signals, then the reader's stated interests if a reader description is given. Weigh the strength of each signal.
4. Return the final 0-10 score with a one-line reason.

Never invert a signal: every "raises score" list is a bonus list, and the absence of a bonus is not a penalty. Only explicit "lowers score" signals and the hard disqualifiers may reduce a score. Never invent preferences the reader has not stated. If a reader description is given, matching its interests earns a bonus and matching what it rules out costs points — but an article that is simply unrelated to the stated interests is judged on its general merits, not penalized.

The language an article is written in is not a quality signal and must not affect the score, unless the reader description says the reader cannot read that language.

Hard disqualifiers (cap at 3, override category):
- no real content: paywalled or truncated text, a subscription prompt, an empty or near-empty post
- advertising, sponsored, or PR content presented as editorial
- clickbait: the headline promises something the text does not deliver

General signals:

Lowers score:
- filler: padding, vague observations, statements of the obvious
- a rehash of widely reported news that adds nothing new
- aggregation without original perspective: link lists, roundups, or listicles with no real commentary
- outrage-driven framing whose main purpose is to provoke rather than inform

Raises score:
- original reporting, research, data, or first-hand expertise
- a clear, well-supported argument or a non-obvious perspective
- specific, practical information the reader can act on
- exceptional craft: unusually clear, creative, or insightful writing
RULES;

	public const UNIVERSAL_OUTPUT = <<<'OUT'
For each article below (labeled "Article 1", "Article 2", ...), score independently using the procedure above. Do not compare articles to each other.

Respond ONLY in JSON, no other text:
{"scores": [{"id": 1, "score": 7, "reason": "one line"}, {"id": 2, "score": 4.5, "reason": "one line"}]}
OUT;

	/**
	 * Stand-alone prompt for the "ads only" category mode. Deliberately does NOT
	 * include the reader profile or the universal rules: in this mode nothing but
	 * advertising may be removed, so quality/relevance signals must not reach the
	 * model at all. The 0-10 scale is reused so the normal keep threshold applies
	 * unchanged: advertising lands at 0-2, everything else at 10.
	 */
	public const ADS_ONLY_PROMPT = <<<'PROMPT'
You are an advertising filter, nothing else. You are NOT judging quality, relevance, topic, depth, or how interesting an item is. The reader has already decided to read everything from these sources; your only job is to remove advertising.

Score 10 for any normal editorial item — news, analysis, essays, newsletters, announcements, reviews, personal posts, digests, anything at all. Language, topic, length, and quality are irrelevant here.

Score 0-2 ONLY when the item itself is an advertisement:
- sponsored or paid placement, native advertising, or anything carrying an ad marker in any language ("Sponsored", "Advertisement", "Promoted", "Paid partnership", "#ad", "Anzeige", "Publicité", "Реклама", or the equivalent)
- an affiliate or referral promotion whose whole purpose is to sell a product or service
- a promo-code, discount, giveaway, or paid course/webinar pitch
- a pitch for the author's own paid product that carries no other content

NOT advertising — score 10:
- a newsletter or digest that merely contains ad slots alongside its editorial content
- a review, analysis, or news story that happens to discuss a product or company
- a company's own news, funding, or launch coverage written editorially
- a link to free material, or a passing mention of a paid product inside a substantive text

When in doubt, score 10. Removing real content is far worse than letting an advertisement through.
PROMPT;

	public const DEFAULT_PROMPT = <<<'PROMPT'
Category: general (no category-specific guidance).

Raises score:
- Original, specific, well-argued content

Lowers score:
- Vague or low-effort content
PROMPT;

	// ============================================================
	// SETTINGS
	// ============================================================

	public const DEFAULTS = [
		'cf_account_id' => '',
		'cf_api_token' => '',
		'cf_model' => '@cf/qwen/qwen3-30b-a3b-fp8',
		'relevance_threshold' => 6.0,
		'exceptional_threshold' => 8.5,	// score strictly greater than this gets the label
		'exceptional_label' => 'Top',
		'batch_size' => 30,	// articles processed per run
		'cf_batch_size' => 5,	// articles per Cloudflare call
		'interval_minutes' => 5,	// minimum time between automatic runs (deduplicates back-to-back triggers)
		'max_run_seconds' => 120,	// stop starting new work after this much time in one run
		'backlog_days' => 3,	// never score articles older than this (guards against import floods)
		'cron_only' => true,	// run during scheduled batch refreshes only, not refreshes started in the browser
		'fetch_full' => true,	// fetch the article page when the RSS summary is short
		'fetch_timeout' => 5,	// per-fetch seconds
		'fetch_max_bytes' => 500000,
		'fetch_summary_cutoff' => 1500,	// only fetch if stripped RSS summary is shorter than this
		'fetch_text_clip' => 3000,	// chars of article text sent to the LLM
		'fetch_skip_hosts' => [
			't.me', 'telegram.me',
			'youtube.com', 'www.youtube.com', 'youtu.be',
			'twitter.com', 'x.com',
			'instagram.com', 'www.instagram.com',
		],
		'cat_modes' => [],	// category name => 'score' | 'pass' | 'ads' (absent = 'score')
		'reader_profile' => '',	// free-text description of the reader, prepended to scoring prompts
		'universal_rules' => self::UNIVERSAL_RULES,
		'default_prompt' => self::DEFAULT_PROMPT,
		'cat_prompts' => [],	// category name => prompt (absent = default prompt)
	];

	// ============================================================
	// EXTENSION LIFECYCLE
	// ============================================================

	public function init(): void {
		parent::init();
		$this->registerHook('freshrss_user_maintenance', [$this, 'maintenanceHook']);
	}

	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		if (!Minz_Request::isPost()) {
			return;
		}

		$action = Minz_Request::paramString('aif_action');

		if ($action === 'run_now') {
			try {
				$this->noticeMessage = $this->runFilter(true);
			} catch (\Throwable $e) {
				$this->noticeMessage = 'ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
				try {
					$this->log('ERROR', 'run now failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
				} catch (\Throwable) {
					// nothing left to do
				}
			}
			return;
		}

		if ($action === 'reset_prompts') {
			// Drop stored overrides of the prompts that have built-in defaults (which
			// improve with extension updates). Category prompts and the reader profile
			// have no defaults to return to, so they are kept.
			$stored = $this->getUserConfiguration();
			unset($stored['universal_rules'], $stored['default_prompt']);
			$this->setUserConfiguration($stored);
			$this->conf = null;
			$this->noticeMessage = 'Universal rules and default prompt reset to the built-in defaults.';
			$this->log('INFO', 'universal rules and default prompt reset to built-in defaults');
			return;
		}

		if (Minz_Request::paramBoolean('aif_clear_log')) {
			$this->removeFile(self::LOG_FILE);
		}

		$token = trim(Minz_Request::paramString('aif_cf_api_token', true));
		$conf = [
			'cf_account_id' => trim(Minz_Request::paramString('aif_cf_account_id', true)),
			// The token is never echoed back into the form, so an empty field keeps the stored one.
			'cf_api_token' => $token !== '' ? $token : $this->confString('cf_api_token'),
			'cf_model' => trim(Minz_Request::paramString('aif_cf_model', true)) ?: self::DEFAULTS['cf_model'],
			'relevance_threshold' => (float)Minz_Request::paramString('aif_relevance_threshold'),
			'exceptional_threshold' => (float)Minz_Request::paramString('aif_exceptional_threshold'),
			'exceptional_label' => trim(Minz_Request::paramString('aif_exceptional_label', true)) ?: self::DEFAULTS['exceptional_label'],
			'batch_size' => max(1, Minz_Request::paramInt('aif_batch_size') ?: self::DEFAULTS['batch_size']),
			'cf_batch_size' => max(1, Minz_Request::paramInt('aif_cf_batch_size') ?: self::DEFAULTS['cf_batch_size']),
			'interval_minutes' => max(0, Minz_Request::paramInt('aif_interval_minutes')),
			'max_run_seconds' => max(30, Minz_Request::paramInt('aif_max_run_seconds') ?: self::DEFAULTS['max_run_seconds']),
			'backlog_days' => max(1, Minz_Request::paramInt('aif_backlog_days') ?: self::DEFAULTS['backlog_days']),
			'cron_only' => Minz_Request::paramBoolean('aif_cron_only'),
			'fetch_full' => Minz_Request::paramBoolean('aif_fetch_full'),
			'fetch_timeout' => max(1, Minz_Request::paramInt('aif_fetch_timeout') ?: self::DEFAULTS['fetch_timeout']),
			'fetch_max_bytes' => self::DEFAULTS['fetch_max_bytes'],
			'fetch_summary_cutoff' => max(0, Minz_Request::paramInt('aif_fetch_summary_cutoff')),
			'fetch_text_clip' => max(500, Minz_Request::paramInt('aif_fetch_text_clip') ?: self::DEFAULTS['fetch_text_clip']),
			'fetch_skip_hosts' => array_values(array_filter(array_map('trim', Minz_Request::paramTextToArray('aif_fetch_skip_hosts', true)))),
			'cat_modes' => $this->readCatModesParam(),
			'reader_profile' => trim(str_replace("\r\n", "\n", Minz_Request::paramString('aif_reader_profile', true))),
			'universal_rules' => trim(Minz_Request::paramString('aif_universal_rules', true)) ?: self::UNIVERSAL_RULES,
			'default_prompt' => trim(Minz_Request::paramString('aif_default_prompt', true)) ?: self::DEFAULT_PROMPT,
			'cat_prompts' => $this->readCatPromptsParam(),
		];
		if ($conf['relevance_threshold'] <= 0) {
			$conf['relevance_threshold'] = self::DEFAULTS['relevance_threshold'];
		}
		if ($conf['exceptional_threshold'] <= 0) {
			$conf['exceptional_threshold'] = self::DEFAULTS['exceptional_threshold'];
		}

		// Store a prompt only when it actually differs from the built-in default,
		// so improvements to the defaults keep reaching users who never edited them.
		foreach (['universal_rules' => self::UNIVERSAL_RULES, 'default_prompt' => self::DEFAULT_PROMPT] as $key => $default) {
			$conf[$key] = str_replace("\r\n", "\n", (string)$conf[$key]);
			if (trim($conf[$key]) === trim($default)) {
				unset($conf[$key]);
			}
		}

		$oldModel = $this->confString('cf_model');
		$this->setUserConfiguration($conf);
		$this->conf = null;	// force re-merge on next access
		if ($oldModel !== '' && $oldModel !== $conf['cf_model']) {
			$this->log('INFO', "model changed: $oldModel -> {$conf['cf_model']}");
		}
	}

	/**
	 * Form fields for categories are keyed by the URL-encoded category name, so
	 * names containing brackets survive PHP's form-array parsing.
	 */
	public static function catFieldKey(string $catName): string {
		return rawurlencode($catName);
	}

	/** @return array<string,'score'|'pass'|'ads'> */
	private function readCatModesParam(): array {
		$out = [];
		foreach (Minz_Request::paramArray('aif_cat_mode', true) as $key => $mode) {
			$name = trim(rawurldecode((string)$key));
			if ($name !== '' && in_array($mode, self::MODES, true)) {
				$out[$name] = $mode;
			}
		}
		return $out;
	}

	/** @return array<string,string> */
	private function readCatPromptsParam(): array {
		$out = [];
		foreach (Minz_Request::paramArray('aif_cat_prompt', true) as $key => $prompt) {
			if (!is_string($prompt)) {
				continue;
			}
			$prompt = trim(str_replace("\r\n", "\n", $prompt));
			$name = trim(rawurldecode((string)$key));
			if ($name !== '' && $prompt !== '') {	// empty prompt = use the default prompt
				$out[$name] = $prompt;
			}
		}
		return $out;
	}

	// ============================================================
	// CONFIG ACCESS (used by extension.php and configure.phtml)
	// ============================================================

	/** @return array<string,mixed> */
	public function conf(): array {
		if ($this->conf === null) {
			$this->conf = array_merge(self::DEFAULTS, $this->getUserConfiguration());
		}
		return $this->conf;
	}

	public function confString(string $key): string {
		$v = $this->conf()[$key] ?? '';
		return is_scalar($v) ? (string)$v : '';
	}

	/** The stored prompt for a category, or '' when the category uses the default prompt. */
	public function categoryPrompt(string $catName): string {
		$catPrompts = $this->conf()['cat_prompts'];
		$prompt = is_array($catPrompts) ? ($catPrompts[$catName] ?? '') : '';
		return is_string($prompt) ? trim($prompt) : '';
	}

	/**
	 * How a category is handled: 'score' (default), 'pass' (everything through,
	 * unscored) or 'ads' (only advertising removed).
	 * @return 'score'|'pass'|'ads'
	 */
	public function catMode(string $catName): string {
		$modes = $this->conf()['cat_modes'];
		$mode = is_array($modes) ? ($modes[$catName] ?? null) : null;
		if (in_array($mode, self::MODES, true)) {
			return $mode;
		}
		// Early configurations listed pass-through categories in a separate key.
		$legacyPass = $this->conf()['pass_categories'] ?? null;
		if (is_array($legacyPass) && in_array($catName, $legacyPass, true)) {
			return 'pass';
		}
		return 'score';
	}

	/** @return array{last_run:int,last_id:string} */
	public function loadState(): array {
		$raw = $this->getFile(self::STATE_FILE);
		$data = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($data)) {
			$data = [];
		}
		return [
			'last_run' => (int)($data['last_run'] ?? 0),
			'last_id' => (string)($data['last_id'] ?? ''),
		];
	}

	/** @param array{last_run:int,last_id:string} $state */
	private function saveState(array $state): void {
		$this->saveFile(self::STATE_FILE, json_encode($state) ?: '{}');
	}

	/** @return list<string> most recent log lines, newest first */
	public function logTail(int $maxLines = 200): array {
		$raw = $this->getFile(self::LOG_FILE);
		if ($raw === null || $raw === '') {
			return [];
		}
		$lines = preg_split('/\R/u', trim($raw)) ?: [];
		return array_reverse(array_slice($lines, -$maxLines));
	}

	// ============================================================
	// LOGGING
	// ============================================================

	private function log(string $type, string $message): void {
		$path = $this->userPath();
		if (!file_exists($path)) {
			mkdir($path, 0770, true);
		}
		$line = date('Y-m-d H:i') . ' ' . str_pad($type, 5) . ' ' . $message . PHP_EOL;
		file_put_contents($path . '/' . self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
	}

	private function trimLog(): void {
		$raw = $this->getFile(self::LOG_FILE);
		if ($raw === null) {
			return;
		}
		$lines = preg_split('/\R/u', trim($raw)) ?: [];
		if (count($lines) > self::LOG_MAX_LINES) {
			$this->saveFile(self::LOG_FILE, implode("\n", array_slice($lines, -self::LOG_MAX_LINES)) . "\n");
		}
	}

	private function userPath(): string {
		return $this->getExtensionUserPath();
	}

	// ============================================================
	// RUN CONTROL
	// ============================================================

	/** Hook entry point: must never break the surrounding actualize request. */
	public function maintenanceHook(): void {
		try {
			$this->runFilter(false);
		} catch (\Throwable $e) {
			try {
				$this->log('ERROR', 'unhandled: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
			} catch (\Throwable) {
				// nothing left to do
			}
		}
	}

	/**
	 * True when the hook fired during a scheduled batch refresh: the CLI cron
	 * (Docker image, app/actualize_script.php, cli/actualize-user.php) or a web
	 * cron calling the feed#actualize URL. False for refreshes started from the
	 * web interface, whose request would otherwise wait for the LLM calls.
	 */
	private function isScheduledRefresh(): bool {
		return PHP_SAPI === 'cli'
			|| (Minz_Request::controllerName() === 'feed' && Minz_Request::actionName() === 'actualize');
	}

	private function acquireLock(): bool {
		$path = $this->userPath();
		if (!file_exists($path)) {
			mkdir($path, 0770, true);
		}
		$lock = $path . '/' . self::LOCK_FILE;
		if (file_exists($lock) && (time() - (@filemtime($lock) ?: 0)) > self::LOCK_TTL) {
			@unlink($lock);
		}
		$handle = @fopen($lock, 'x');
		if ($handle === false) {
			return false;
		}
		fclose($handle);
		return true;
	}

	private function releaseLock(): void {
		@unlink($this->userPath() . '/' . self::LOCK_FILE);
	}

	/**
	 * Main entry point. Returns a short human-readable status message.
	 * @param bool $force true to ignore the interval/trigger guards (the "Run now" button)
	 */
	public function runFilter(bool $force = false): string {
		$conf = $this->conf();

		if ($this->confString('cf_account_id') === '' || $this->confString('cf_api_token') === '') {
			return 'Cloudflare credentials are not configured — nothing done.';
		}
		if (!$force && !empty($conf['cron_only']) && !$this->isScheduledRefresh()) {
			return 'Skipped: refresh started in the browser (the filter runs during scheduled refreshes, see settings).';
		}

		$state = $this->loadState();
		if (!$force && time() - $state['last_run'] < ((int)$conf['interval_minutes']) * 60) {
			return 'Skipped: ran recently.';
		}
		if (!$this->acquireLock()) {
			return 'Skipped: another run is in progress.';
		}

		try {
			@set_time_limit(max(300, (int)$conf['max_run_seconds'] + 180));
			$this->startedAt = microtime(true);
			$this->topTagId = null;

			$state['last_run'] = time();

			if ($state['last_id'] === '') {
				// First run: start scoring from now, do not touch existing articles.
				$state['last_id'] = uTimeString();
				$this->saveState($state);
				$this->log('INFO', 'watermark initialised — articles arriving from now on will be scored');
				return 'Initialised. Articles arriving from now on will be scored.';
			}

			$this->saveState($state);	// persist last_run early so failures don't retry on every poll

			$summary = $this->processPending($state);
			$this->trimLog();
			return $summary;
		} finally {
			$this->releaseLock();
		}
	}

	/** @param array<string,mixed> $conf */
	private function timeLeft(array $conf): bool {
		return (microtime(true) - $this->startedAt) < (float)$conf['max_run_seconds'];
	}

	// ============================================================
	// MAIN PIPELINE
	// ============================================================

	/**
	 * Resume after the last fully processed article; never look further back
	 * than the backlog window (guards against OPML-import floods).
	 * @param array{last_run:int,last_id:string} $state
	 */
	private function pendingIdMin(array $state): string {
		$conf = $this->conf();
		$idMin = (string)((int)$state['last_id'] + 1);
		$horizon = (string)((time() - ((int)$conf['backlog_days']) * 86400) * 1000000);
		if ((int)$horizon > (int)$idMin) {
			$idMin = $horizon;
		}
		return $idMin;
	}

	/** Number of articles currently awaiting scoring (capped at 500), or null before the first run. */
	public function pendingCount(): ?int {
		$state = $this->loadState();
		if ($state['last_id'] === '') {
			return null;
		}
		$ids = FreshRSS_Factory::createEntryDao()->listIdsWhere('A', 0, FreshRSS_Entry::STATE_NOT_READ, null,
			id_min: $this->pendingIdMin($state), limit: 501);
		return $ids === null ? null : count($ids);
	}

	/** @param array{last_run:int,last_id:string} $state */
	private function processPending(array $state): string {
		$conf = $this->conf();
		$entryDAO = FreshRSS_Factory::createEntryDao();

		$entries = [];
		foreach ($entryDAO->listWhere('A', 0, FreshRSS_Entry::STATE_NOT_READ, null,
			id_min: $this->pendingIdMin($state), sort: 'id', order: 'ASC', limit: (int)$conf['batch_size']) as $entry) {
			if ($entry instanceof FreshRSS_Entry) {
				$entries[] = $entry;
			}
		}

		if (count($entries) === 0) {
			$this->log('INFO', 'no new items');
			return 'No new articles to score.';
		}

		$this->log('INFO', '---- run start, ' . count($entries) . ' new items ----');

		$stats = ['pass' => 0, 'top' => 0, 'kept' => 0, 'removed' => 0, 'error' => 0];
		$batch = [];	// list of work items sharing one prompt
		$batchPrompt = '';
		$doneUpTo = '';	// id of the last article for which everything before it is fully handled
		$prevId = '';
		$aborted = false;

		foreach ($entries as $entry) {
			$id = $entry->id();
			// Decode twice: feed titles are often double-encoded (&amp;nbsp; etc.).
			$title = html_entity_decode(html_entity_decode(strip_tags($entry->title()),
				ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$title = $this->oneLine($title);
			$title = $title === '' ? '(no title)' : $title;
			$url = htmlspecialchars_decode($entry->link(), ENT_QUOTES);

			$feed = $entry->feed();
			$category = $feed !== null ? $feed->category() : null;
			$catName = $category !== null ? trim($category->name()) : '';

			$mode = $catName === '' ? 'score' : $this->catMode($catName);

			// Trusted categories: pass through unscored.
			if ($mode === 'pass') {
				$stats['pass']++;
				$this->log('PASS', "[$catName] $title | $url");
				$prevId = $id;
				if (count($batch) === 0) {
					$doneUpTo = $id;
				}
				continue;
			}

			$prompt = $this->buildPrompt($catName, $mode);

			// Flush the current batch when the prompt changes or the batch is full.
			if (count($batch) > 0 && ($prompt !== $batchPrompt || count($batch) >= (int)$conf['cf_batch_size'])) {
				if (!$this->timeLeft($conf)) {
					$this->log('INFO', 'time budget reached, deferring the rest to the next run');
					$aborted = true;
					break;
				}
				if (!$this->flushBatch($batchPrompt, $batch, $entryDAO, $stats)) {
					$aborted = true;
					break;
				}
				$doneUpTo = $prevId;
				$batch = [];
			}

			$batchPrompt = $prompt;
			$batch[] = [
				'id' => $id,
				'title' => $title,
				'url' => $url,
				'cat' => $catName === '' ? 'uncategorized' : $catName,
				'mode' => $mode,
				'text' => $this->articleText($entry, $url),
			];
			$prevId = $id;
		}

		if (!$aborted && count($batch) > 0) {
			if ($this->timeLeft($conf) && $this->flushBatch($batchPrompt, $batch, $entryDAO, $stats)) {
				$doneUpTo = $prevId;
			} else {
				$this->log('INFO', 'time budget reached, deferring the rest to the next run');
			}
		}
		if (!$aborted && count($batch) === 0 && $prevId !== '') {
			$doneUpTo = $prevId;
		}

		if ($doneUpTo !== '') {
			$state['last_id'] = $doneUpTo;
			$this->saveState($state);
		}

		$summary = "passed={$stats['pass']}, top={$stats['top']}, kept={$stats['kept']}, " .
			"removed={$stats['removed']}, unscored={$stats['error']}";
		$this->log('INFO', "---- run end: $summary ----");
		return 'Run finished: ' . $summary;
	}

	/**
	 * Assemble the system prompt: reader profile (if any) + universal rules +
	 * category prompt (or the default prompt) + the fixed output format.
	 * @param 'score'|'pass'|'ads' $mode
	 */
	private function buildPrompt(string $catName, string $mode = 'score'): string {
		// Ads mode deliberately skips the reader profile and the universal rules:
		// their disqualifiers and relevance signals would remove far more than advertising.
		if ($mode === 'ads') {
			return self::ADS_ONLY_PROMPT . "\n\n" . self::UNIVERSAL_OUTPUT;
		}

		$catPrompt = $catName !== '' ? $this->categoryPrompt($catName) : '';
		if ($catPrompt === '') {
			$catPrompt = $this->confString('default_prompt');
		}

		$profile = trim($this->confString('reader_profile'));
		$prefix = $profile !== '' ? "About the reader:\n$profile\n\n" : '';

		return $prefix . $this->confString('universal_rules') . "\n\n" . $catPrompt . "\n\n" . self::UNIVERSAL_OUTPUT;
	}

	/**
	 * Score one batch with Cloudflare and apply the decisions.
	 * @param list<array{id:string,title:string,url:string,cat:string,mode:string,text:string}> $batch
	 * @param array{pass:int,top:int,kept:int,removed:int,error:int} $stats
	 * @return bool false to abort the whole run (quota exhausted)
	 */
	private function flushBatch(string $prompt, array $batch, FreshRSS_EntryDAO $entryDAO, array &$stats): bool {
		$conf = $this->conf();
		$scored = $this->scoreBatch($prompt, $batch);

		if ($scored['abort']) {
			$this->log('INFO', 'quota exhausted, aborting run');
			return false;
		}

		if (!$scored['ok']) {
			// Cloudflare failed: keep the articles unread and unscored rather than
			// hiding them, and move on (no retry loop on poison batches).
			foreach ($batch as $item) {
				$stats['error']++;
				$this->log('ERROR', "unscored (kept) [{$item['cat']}] {$item['title']} | {$item['url']}");
			}
			return true;
		}

		$skipIds = [];
		foreach ($batch as $i => $item) {
			$r = $scored['results'][$i] ?? ['score' => 5.0, 'reason' => 'missing in batch response'];
			$score = $r['score'];
			$reason = mb_substr($this->oneLine($r['reason']), 0, 400);

			$adsMode = $item['mode'] === 'ads';

			if ($score >= (float)$conf['relevance_threshold']) {
				if ($adsMode) {
					// Survived the ad filter — not a quality verdict, so no Top label.
					$stats['pass']++;
					$this->log('PASS', "[{$item['cat']}] {$item['title']} | {$item['url']}");
				} elseif ($score > (float)$conf['exceptional_threshold']) {
					$stats['kept']++;
					$this->labelTop($item['id']);
					$stats['top']++;
					$this->log('TOP', "[$score/10] [{$item['cat']}] {$item['title']} | $reason | {$item['url']}");
				} else {
					$stats['kept']++;
					$this->log('KEEP', "[$score/10] [{$item['cat']}] {$item['title']} | $reason | {$item['url']}");
				}
			} else {
				$skipIds[] = $item['id'];
				$stats['removed']++;
				$this->log('SKIP', "[$score/10] [{$item['cat']}] {$item['title']} | $reason | {$item['url']}");
			}
		}

		if (count($skipIds) > 0) {
			$entryDAO->markRead($skipIds, true);
		}
		return true;
	}

	private function labelTop(string $entryId): void {
		$label = $this->confString('exceptional_label');
		if ($label === '') {
			return;
		}
		if ($this->topTagId === null) {
			$tagDAO = FreshRSS_Factory::createTagDao();
			$tag = $tagDAO->searchByName($label);
			if ($tag === null) {
				// Fails (leaving no label) when a category already uses this name.
				$tagDAO->addTag(['name' => $label]);
				$tag = $tagDAO->searchByName($label);
				if ($tag === null) {
					$this->log('WARN', "could not create label \"$label\" (is there a category with the same name?)");
				}
			}
			$this->topTagId = $tag === null ? 0 : $tag->id();
		}
		if ($this->topTagId > 0) {
			FreshRSS_Factory::createTagDao()->tagEntry($this->topTagId, $entryId, true);
		}
	}

	// ============================================================
	// ARTICLE TEXT
	// ============================================================

	/** Plain text to send to the LLM: RSS summary, or fetched page when the summary is short. */
	private function articleText(FreshRSS_Entry $entry, string $url): string {
		$conf = $this->conf();
		$summary = $this->htmlToText($entry->content(false));
		$text = $summary;

		$scheme = $url !== '' ? (parse_url($url, PHP_URL_SCHEME) ?? '') : '';
		$host = $url !== '' ? (parse_url($url, PHP_URL_HOST) ?? '') : '';
		$skipHosts = is_array($conf['fetch_skip_hosts']) ? $conf['fetch_skip_hosts'] : [];

		$shouldFetch =
			!empty($conf['fetch_full'])
			&& $url !== ''
			&& in_array($scheme, ['http', 'https'], true)
			&& !in_array($host, $skipHosts, true)
			&& mb_strlen($summary) < (int)$conf['fetch_summary_cutoff'];

		if ($shouldFetch) {
			$fetched = $this->fetchArticle($url);
			// Prefer fetched text when it's meaningfully longer than the RSS summary.
			// The LLM uses it to judge relevance and to detect paywalls itself.
			if ($fetched !== null && mb_strlen($fetched) > mb_strlen($summary)) {
				$text = $fetched;
			}
		}

		return mb_substr($text, 0, (int)$conf['fetch_text_clip']);
	}

	/** Normalize HTML into plain text with collapsed whitespace. */
	private function htmlToText(string $html): string {
		$t = strip_tags($html);
		$t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$t = preg_replace('/\s+/u', ' ', $t);
		return trim((string)$t);
	}

	/**
	 * Collapse a string to a single log-safe line: no newlines (some feeds put
	 * them in titles, which would split log records), no NBSP, collapsed whitespace.
	 */
	private function oneLine(string $s): string {
		$s = str_replace("\u{00A0}", ' ', $s);
		$s = preg_replace('/\s+/u', ' ', $s);
		return trim((string)$s);
	}

	/**
	 * Attempt to fetch and extract article body text from a URL.
	 * Returns the cleaned, clipped article text, or null on failure.
	 * Paywall handling is left to the LLM, which sees the fetched text directly.
	 */
	private function fetchArticle(string $url): ?string {
		$conf = $this->conf();
		$ch = curl_init($url);
		if ($ch === false) {
			return null;
		}
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
			// Article links come from feeds: never follow them to anything but the web.
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_TIMEOUT => (int)$conf['fetch_timeout'],
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FreshRSS-AiFilter/' . $this->getVersion()
				. '; +https://github.com/AlexeyTrudov/xExtension-AiFilter)',
			CURLOPT_ENCODING => '',	// accept gzip/deflate
			CURLOPT_RANGE => '0-' . ((int)$conf['fetch_max_bytes'] - 1),
			CURLOPT_SSL_VERIFYPEER => true,
		]);
		$html = curl_exec($ch);
		$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);

		if (!is_string($html) || $html === '') {
			$this->log('WARN', "fetch failed http=$http err=" . mb_substr($err, 0, 120) . " url=$url");
			return null;
		}

		// Accept 200/206 as normal; 402/403 often carry a paywall notice that
		// we still want the model to see and judge.
		if (!in_array($http, [200, 206, 402, 403], true)) {
			return null;
		}

		$text = $this->extractArticleText($html);
		if ($text === '') {
			return null;
		}

		return mb_substr($text, 0, (int)$conf['fetch_text_clip']);
	}

	/**
	 * Pull a best-effort plain-text body out of an HTML document. Prefers <article>,
	 * then <main>/[role=main], then <body>. Strips script/style/nav/footer/etc first.
	 */
	private function extractArticleText(string $html): string {
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		// Force UTF-8 interpretation
		$prefix = '<?xml encoding="UTF-8">';
		$dom->loadHTML($prefix . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
		libxml_clear_errors();

		$xpath = new DOMXPath($dom);

		// Remove noise.
		$junk = $xpath->query('//script | //style | //nav | //header | //footer | //aside | //form | //noscript | //iframe');
		if ($junk !== false) {
			foreach (iterator_to_array($junk) as $node) {
				if ($node->parentNode !== null) {
					$node->parentNode->removeChild($node);
				}
			}
		}

		$selectors = ['//article', '//main', '//*[@role="main"]', '//body'];
		foreach ($selectors as $sel) {
			$nodes = $xpath->query($sel);
			if ($nodes !== false && $nodes->length > 0) {
				$text = $nodes->item(0)->textContent ?? '';
				$text = preg_replace('/\s+/u', ' ', $text);
				$text = trim((string)$text);
				if (mb_strlen($text) > 200) {
					return $text;
				}
			}
		}
		return '';
	}

	// ============================================================
	// CLOUDFLARE
	// ============================================================

	/**
	 * Score a batch of articles in a single Cloudflare call.
	 *
	 * @param list<array{id:string,title:string,url:string,cat:string,mode:string,text:string}> $articles
	 * @return array{ok:bool,abort:bool,results:list<array{score:float,reason:string}>}
	 */
	private function scoreBatch(string $prompt, array $articles): array {
		$conf = $this->conf();
		if (count($articles) === 0) {
			return ['ok' => true, 'abort' => false, 'results' => []];
		}

		// Build user message: "Article N:\nTitle: ...\nText: ..." separated by ---
		$parts = [];
		foreach ($articles as $i => $a) {
			$idx = $i + 1;
			$parts[] = "Article $idx:\nTitle: {$a['title']}\nText: {$a['text']}";
		}
		$userMsg = implode("\n\n---\n\n", $parts);

		// Qwen3 hybrid-thinking models: soft switch to disable the reasoning
		// preamble, which would otherwise eat max_tokens and pollute the JSON.
		$systemPrompt = $prompt;
		if (stripos($this->confString('cf_model'), 'qwen3') !== false) {
			$systemPrompt .= "\n/no_think";
		}

		$url = 'https://api.cloudflare.com/client/v4/accounts/' . $this->confString('cf_account_id')
			. '/ai/run/' . $this->confString('cf_model');

		$raw = null;
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$payload = json_encode([
				'messages' => [
					['role' => 'system', 'content' => $systemPrompt],
					['role' => 'user', 'content' => $userMsg],
				],
				// A ceiling, not a target: generous headroom so reasoning-mode models
				// (which spend output tokens on thinking first) can still emit the
				// JSON. Doubled on retry when reasoning burned the whole budget.
				'max_tokens' => (200 * count($articles) + 400) * ($attempt + 1),
				'temperature' => 0.1,
			]);

			$ch = curl_init($url);
			if ($ch === false) {
				return ['ok' => false, 'abort' => false, 'results' => []];
			}
			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $payload,
				CURLOPT_HTTPHEADER => [
					'Content-Type: application/json',
					'Authorization: Bearer ' . $this->confString('cf_api_token'),
				],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => self::CF_TIMEOUT,
			]);
			$body = curl_exec($ch);
			$errno = curl_errno($ch);
			$errmsg = curl_error($ch);
			$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($errno !== 0 || $httpcode !== 200 || !is_string($body) || $body === '') {
				$this->log('ERROR', "cf curl: errno=$errno http=$httpcode msg=" . mb_substr($errmsg, 0, 200)
					. ' body=' . mb_substr(is_string($body) ? $body : '', 0, 200));
				return ['ok' => false, 'abort' => $httpcode === 429, 'results' => []];
			}

			$resp = json_decode($body, true);
			$raw = is_array($resp) ? ($resp['result']['response'] ?? null) : null;

			// Some models (e.g. Gemma 4, gpt-oss) answer in OpenAI Chat Completions shape instead.
			if ($raw === null && is_array($resp)) {
				$choice = $resp['result']['choices'][0] ?? $resp['choices'][0] ?? null;
				if (is_array($choice)) {
					$raw = $choice['message']['content'] ?? null;
					if (!is_string($raw) || trim($raw) === '') {
						$finish = (string)($choice['finish_reason'] ?? '?');
						$hasReasoning = !empty($choice['message']['reasoning_content']);
						if ($attempt === 0 && $hasReasoning && $finish === 'length') {
							$this->log('WARN', 'cf reasoning consumed the token budget, retrying once with a doubled ceiling');
							$raw = null;
							continue;
						}
						$this->log('ERROR', "cf empty content (finish_reason=$finish"
							. ($hasReasoning ? ', model spent the token budget on reasoning' : '') . ')');
						return ['ok' => false, 'abort' => false, 'results' => []];
					}
				}
			}

			if ($raw === null) {
				$this->log('ERROR', 'cf response missing result.response: ' . mb_substr($body, 0, 200));
				return ['ok' => false, 'abort' => false, 'results' => []];
			}
			break;
		}

		if ($raw === null) {
			return ['ok' => false, 'abort' => false, 'results' => []];
		}

		// CF returns `response` as parsed array when the model outputs JSON,
		// and as a string (sometimes markdown-fenced) otherwise.
		if (is_array($raw)) {
			$parsed = $raw;
		} else {
			// Reasoning models may prepend a <think>…</think> block despite /no_think.
			$raw = preg_replace('/<think>[\s\S]*?<\/think>/iu', '', (string)$raw);
			$raw = preg_replace('/^```json\s*/i', '', trim((string)$raw));
			$raw = preg_replace('/```$/', '', trim((string)$raw));
			$parsed = json_decode(trim((string)$raw), true);
			if (!is_array($parsed)) {
				$parsed = [];
			}
		}

		$scores = is_array($parsed['scores'] ?? null) ? $parsed['scores'] : [];

		// A response with no scores at all means the model ignored the format:
		// treat the batch as unscored (kept) rather than fabricating 5.0s that
		// would silently hide the articles.
		if (count($scores) === 0) {
			$this->log('ERROR', 'cf response contained no scores: '
				. mb_substr($this->oneLine(is_string($raw) ? $raw : (json_encode($parsed) ?: '')), 0, 200));
			return ['ok' => false, 'abort' => false, 'results' => []];
		}

		// Build id => result map from what the model returned.
		$byId = [];
		foreach ($scores as $i => $s) {
			if (!is_array($s)) {
				continue;
			}
			$sid = isset($s['id']) ? (int)$s['id'] : ((int)$i + 1);
			$byId[$sid] = $s;
		}

		// Align by 1-based index; fall back to positional if id missing.
		$results = [];
		foreach (array_values($articles) as $i => $_) {
			$target = $i + 1;
			if (isset($byId[$target])) {
				$s = $byId[$target];
			} elseif (isset($scores[$i]) && is_array($scores[$i])) {
				$s = $scores[$i];
			} else {
				$results[$i] = ['score' => 5.0, 'reason' => 'missing in batch response'];
				continue;
			}
			$results[$i] = [
				'score' => (float)($s['score'] ?? 5),
				'reason' => is_string($s['reason'] ?? null) ? $s['reason'] : '',
			];
		}

		return ['ok' => true, 'abort' => false, 'results' => $results];
	}
}
