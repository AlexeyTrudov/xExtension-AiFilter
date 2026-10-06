# AI Filter for FreshRSS

A [FreshRSS](https://freshrss.org) extension that reads your new articles with an
LLM and keeps only what is worth your time. Every article that arrives is scored
from 0 to 10 against prompts you write in plain language. Low scores are marked
as read, exceptional ones get a label, and every decision is logged with a
one-line reason.

It runs inside FreshRSS during its own scheduled feed refresh, so there is no
extra service, cron job or API client to maintain. Inference runs on
[Cloudflare Workers AI](https://developers.cloudflare.com/workers-ai/), whose
free daily allowance comfortably covers a personal feed reader.

## Features

- **Prompt-driven scoring.** Write a short profile of yourself, then add
  per-category prompts (“for *Events*, only meetups in my city”). A sensible
  generic rubric is built in.
- **Three modes per category.** *Score* with the prompts, *pass everything
  through* untouched, or *remove advertising only*: a dedicated ad detector for
  sources you trust but whose sponsored posts you don't want.
- **Hides, never deletes.** Filtered articles are only marked as read, so they
  stay searchable and recoverable. When the LLM call fails, articles are kept.
- **Highlights the best.** Articles above a second threshold get a label
  (`Top` by default) that you can open as a stream.
- **Short summaries get expanded.** When a feed only carries a teaser, the
  article page is fetched so the model judges the real text.
- **Built-in log viewer** on the settings page, filterable into kept, removed
  and system messages.
- **Cheap and bounded.** Articles are scored once, in batches, oldest first. A
  backlog window keeps an OPML import from burning your quota.

## How it works

FreshRSS fires the `freshrss_user_maintenance` hook at the start of every batch
feed refresh. On each such refresh the extension:

1. takes up to *Articles per run* unread articles that arrived since the last
   processed one (never older than the *Backlog window*), oldest first;
2. looks at each article's category mode: *pass through* articles are left
   alone, the rest are queued for scoring;
3. uses the RSS text, or fetches the article page when the summary is short;
4. sends the articles to Cloudflare Workers AI in batches (5 per call by
   default), together with your prompts;
5. marks articles below the *Keep threshold* as read and labels articles above
   the *Label threshold*;
6. writes one log line per article and advances its watermark.

Because the hook fires at the *start* of a refresh, articles fetched by one
refresh are scored during the next one. With a 15-minute refresh interval, new
articles stay unfiltered for up to about 15 minutes.

## Requirements

- FreshRSS **1.28** or newer. Developed and tested on 1.28.1; older versions are
  untested and probably incompatible.
- PHP 8.1+ with the `curl` and `dom` extensions. The official Docker image
  includes both.
- A **scheduled feed refresh**: the built-in Docker cron, a system cron, or a
  web cron. See [When the filter runs](#when-the-filter-runs). Without one, the
  filter only runs when you press *Run now*.
- A Cloudflare account. The free plan is enough for checking at least 100 articles per day, possibly more (depending on model choosen).

## Installation

The extension is a single folder, `xExtension-AiFilter`, placed in FreshRSS's
`extensions/` directory. Pick whichever method matches your setup.

### With git

```sh
cd /path/to/FreshRSS/extensions
git clone https://github.com/AlexeyTrudov/xExtension-AiFilter.git
```

Update later with `git pull` in that folder.

### From a ZIP

Download the repository as a ZIP (*Code → Download ZIP*) and extract it into
`FreshRSS/extensions/`. The result must be
`extensions/xExtension-AiFilter/extension.php`. FreshRSS does not care about the
folder name, so a `-main` suffix from GitHub's ZIP is harmless, but keeping the
plain name makes updates easier.

### Docker (official `freshrss/freshrss` image)

Extensions live in the `/var/www/FreshRSS/extensions` volume. Either clone into
the host path of that volume, or copy the folder into the running container:

```sh
docker cp xExtension-AiFilter freshrss:/var/www/FreshRSS/extensions/
```

Also make sure the image's built-in cron is **enabled**. It is off unless you set
`CRON_MIN`, for example `-e 'CRON_MIN=1,31'` to refresh at :01 and :31.

### Docker (linuxserver.io image)

Put the folder in `/config/www/freshrss/extensions/` inside the container, i.e.
in the `www/freshrss/extensions` subfolder of your `/config` volume.

### PikaPods

Enable SFTP for your pod (*Pod settings → SFTP*), connect with any SFTP client,
and upload the `xExtension-AiFilter` folder into the top-level `extensions/`
folder. PikaPods refreshes feeds twice an hour (at :07 and :37) with a CLI cron,
so no further setup is needed.

### Enable it

In FreshRSS go to **Settings → Extensions**, enable **AI Filter**, and open its
settings with the gear icon. In multi-user installations every user enables and
configures the extension separately, with their own Cloudflare credentials.

## Setup

1. **Get Cloudflare credentials.** In the Cloudflare dashboard open
   *AI → Workers AI* and choose **Use REST API**. That page shows your
   **Account ID** and has a **Create a Workers AI API Token** button. Copy both.
   You can also create the token under *My Profile → API Tokens* with the
   *Workers AI* template.
2. **Enter them** on the AI Filter settings page and press **Save**. The token
   is stored in your FreshRSS user configuration and is never shown again. Leave
   the field empty on later saves to keep it.
3. **Describe yourself** in **Reader profile**. This is where most of the
   personalisation happens; see [Writing prompts](#writing-prompts).
4. **Set up your categories.** The settings page lists all your FreshRSS
   categories. Choose a mode for each, and write a prompt for the ones that
   deserve specific rules. Categories without a prompt use the default prompt.
5. **Press “Run now”.** The very first run only sets a starting point: articles
   that arrive *after* this moment will be scored, and your existing unread
   articles are left alone.
6. **Watch the log** at the bottom of the settings page over the next refreshes,
   and tune prompts and thresholds based on the scores and reasons you see.

## When the filter runs

The filter runs at the start of every **scheduled** batch refresh:

| Refresh triggered by | Filter runs? |
|---|---|
| Docker image cron (`CRON_MIN`), `app/actualize_script.php`, `cli/actualize-user.php` | yes |
| Web cron calling the refresh URL (`…/i/?c=feed&a=actualize&ajax=1&user=…&token=…`) | yes |
| Refreshing from the FreshRSS web interface | no, unless you untick *Run only during scheduled refreshes* |
| *Run now* button | always |

In-browser refreshes are skipped by default because the browser request would
wait for the LLM calls, which can take a minute or two. The *Min. minutes
between runs* setting (default 5) prevents duplicate runs when two triggers fire
close together, and a lock prevents parallel runs.

If you refresh with a **web cron on shared hosting**, keep *Max seconds per run*
well below PHP's `max_execution_time`, since the filter runs inside that request
before the feeds are refreshed. Anything unfinished is picked up by the next run.

## Settings reference

| Setting | Default | Meaning |
|---|---|---|
| Account ID / API token | — | Cloudflare credentials. Without them the filter is idle. |
| Model | `@cf/qwen/qwen3-30b-a3b-fp8` | Any Workers AI text-generation model id; see [Choosing a model](#choosing-a-model). |
| Keep threshold | 6 | Articles scoring below this are marked as read. |
| Label threshold / label | 8.5 / `Top` | Articles scoring strictly above this get the label. The label is created on first use and must not share its name with a category. |
| Articles per run | 30 | Maximum articles handled per refresh. |
| Articles per LLM call | 5 | Batch size of one Cloudflare request. |
| Min. minutes between runs | 5 | Lower bound between automatic runs. |
| Max seconds per run | 120 | After this, no new batches start; the rest waits for the next run. |
| Backlog window (days) | 3 | Articles older than this are never scored and simply stay unread. |
| Run only during scheduled refreshes | on | See [When the filter runs](#when-the-filter-runs). |
| Fetch the article page… | on | Fetch the page when the RSS text is shorter than the cutoff. |
| Fetch if summary shorter than | 1500 chars | Cutoff for fetching. |
| Text sent to the LLM | 3000 chars | Per-article text limit. This is the main cost lever. |
| Fetch timeout | 5 s | Per page. |
| Never fetch from these hosts | t.me, YouTube, X/Twitter, Instagram | Hosts whose pages can't be usefully fetched. |
| Reader profile | empty | Free-text description of you, prepended to every scoring prompt. |
| Universal rules | built-in | Scoring scale, procedure and general quality signals. |
| Default prompt | built-in | Used for categories without their own prompt. |
| Category mode / prompt | Score / empty | Per category; see [Category modes](#category-modes). |

## Writing prompts

For a scored category, the extension sends the model one system prompt
assembled from four parts:

```text
About the reader:            ← your Reader profile (omitted when empty)
<reader profile>

<universal rules>            ← scale, procedure, general signals

<category prompt>            ← or the default prompt

<output format>              ← fixed; asks for JSON scores
```

The model scores each article from a baseline of 5. Hard disqualifiers cap a
score at 3. “Raises score” and “Lowers score” signals move it up and down.

**Tips**

- **Most personalisation belongs in the reader profile**: who you are, where you
  live, what you work on, what you care about, what you never want to see, and
  which languages you read. The universal rules then rarely need editing.
- **Keep category prompts short and concrete**, as two lists: *Raises score* and
  *Lowers score*. Name real things (“confirmed legislation, not proposals”) rather
  than vague qualities (“important news”).
- **Bonuses are not requirements.** The built-in rules tell the model that a
  missing bonus is not a penalty. If you want something penalised, put it under
  *Lowers score* explicitly.
- **Add calibration lines** when a category's scores bunch up, for example
  “a typical launch post should score 2–4; above 6 only if…”.
- **Iterate from the log.** Every line shows the score and the model's reason.
  When a reason reveals a wrong assumption, add a line to the prompt that
  addresses it directly.
- **Reset prompts** restores the built-in universal rules and default prompt.
  Your profile and category prompts are kept. A built-in prompt you never edited
  stays linked to the default and picks up improvements in future versions.

### Example reader profile

```text
Backend engineer living in Lyon, France. Interested in distributed systems,
databases, urban cycling, and personal finance (index investing, French tax
rules). Not interested in celebrity news, sports, or crypto price speculation.
Reads English and French; does not read German.
```

### Example category prompts

A city news category:

```text
Category: Local news.

Raises score:
- Decisions already taken or formally submitted (laws, council votes, budgets) — not plans or rumours
- Changes to public transport, roadworks, closures, or strikes with dates
- Concrete upcoming events with a date and place

Lowers score:
- Crime and accident reports without wider consequences
- Political statements that do not lead to a decision
- Lifestyle and travel fluff
```

A technology category:

```text
Category: Technology.

Raises score:
- Technical depth: architecture, benchmarks, post-mortems, research results
- First-hand experience from people who built or operated the thing
- Clear explanations of a non-obvious trade-off

Lowers score:
- Product announcements and funding news without technical substance
- Rumours about unreleased products
- Generic "AI will change everything" commentary
```

Short-form idea blogs, which a generic rubric tends to punish for brevity:

```text
Category: Short-form idea blogs. These posts are deliberately brief (100-300 words).
Brevity is the format, not a defect: never lower a score for length, missing data,
or absent supporting evidence. Judge only the idea.

Raises score:
- A reframing that changes how one looks at a familiar situation
- A concrete, usable heuristic for work, marketing, or decision-making

Lowers score:
- Restates a truism everyone already holds
- Motivational filler with no transferable idea
```

## Category modes

| Mode | What happens | LLM call |
|---|---|---|
| **Score with the prompt below** | Full rubric: profile + universal rules + category or default prompt. Articles can be hidden or labelled. | yes |
| **Pass everything through** | Articles are left untouched and logged as `PASS`. Use it for sources you always want to read. | no |
| **Remove advertising only** | A stand-alone ad detector judges each article. Only sponsored posts, affiliate pitches, promo codes and similar are hidden. Everything else is logged as `PASS`, and no label is applied. The profile, universal rules and category prompt are not used. | yes |

## Choosing a model

Any Workers AI text-generation model that accepts chat `messages` works. Paste
its id into **Model**, save, press **Run now**, and check the log. Models tested
with this extension (prices per million tokens as of August 2026; check the
[current pricing](https://developers.cloudflare.com/workers-ai/platform/pricing/)):

| Model | Input / output | Notes |
|---|---|---|
| `@cf/qwen/qwen3-30b-a3b-fp8` (default) | $0.05 / $0.34 | Cheapest, strongly multilingual, good reasons. A reasoning model: the extension disables its thinking automatically. |
| `@cf/openai/gpt-oss-20b` | $0.20 / $0.30 | Good format discipline. Reasons by default and occasionally exhausts its token budget; the extension retries once with a larger budget. |
| `@cf/mistralai/mistral-small-3.1-24b-instruct` | $0.35 / $0.56 | No reasoning mode, the most predictable output. |
| `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | $0.29 / $2.25 | Works well but has the most expensive output. |
| `@cf/google/gemma-4-26b-a4b-it` | $0.10 / $0.30 | **Not recommended.** It reasons by default and spends its budget on thinking before producing any scores. |

Workers AI includes a free allowance of 10,000 neurons per day. With the default
model and settings, one call scoring 5 articles costs a few dozen neurons, so the
free allowance covers on the order of a thousand articles a day. Article length,
prompt size and *Text sent to the LLM* shift this considerably.

## Reading the log

The newest lines are at the top. Each line has a timestamp, a type and details:

```text
2026-08-23 16:05 KEEP  [7/10] [News] Title | one-line reason | https://…
```

| Type | Meaning |
|---|---|
| `KEEP` | Scored at or above the keep threshold; left unread. |
| `TOP` | Scored above the label threshold; labelled. |
| `SKIP` | Scored below the keep threshold; marked as read. |
| `PASS` | Not scored (pass-through category) or not an ad (ads-only category). |
| `INFO` | Run start and end summaries, configuration changes. |
| `WARN` | A non-fatal problem: an article page could not be fetched, a retry. |
| `ERROR` | A failed Cloudflare call. The affected articles are logged as `unscored (kept)`. |

The run summary (`passed=…, top=…, kept=…, removed=…, unscored=…`) totals one run.

## Troubleshooting

**Nothing appears in the log.** Check, in order:

- the credentials banner at the top of the settings page;
- that you pressed *Run now* once, because the first run only sets a starting
  point;
- that a scheduled refresh exists (see [When the filter runs](#when-the-filter-runs)),
  e.g. `CRON_MIN` is set on the official Docker image;
- the *Queue* counter. Zero means there is nothing new within the backlog window.

**`ERROR unscored (kept)`**: the Cloudflare call failed. The `ERROR` line above it
says why. Those articles stay unread and are not retried. Articles never get
lost, just unfiltered.

**`ERROR cf curl: … http=429`**: the daily quota is exhausted. The run stops, and
the remaining articles are scored after the quota resets, as long as they are
still within the backlog window.

**`cf empty content (finish_reason=length, model spent the token budget on reasoning)`**:
the model is a reasoning model that thinks before answering. Switch to one of the
recommended models.

**`cf response contained no scores`**: the model ignored the JSON format. This is
rare with the recommended models; otherwise switch models.

**The label never appears.** A FreshRSS label cannot share its name with a
category. Pick another label name; a `WARN` line in the log confirms this case.

**New articles show up unfiltered for a while.** That is by design: they are
scored during the next scheduled refresh (see [How it works](#how-it-works)).

## Privacy and security

- **Data sent to Cloudflare:** for each scored article, its title and up to
  *Text sent to the LLM* characters of its text, together with your prompts and
  reader profile, under your own Cloudflare account. Nothing is sent anywhere
  else.
- **Outgoing requests from your server:** besides Cloudflare, the extension
  fetches article pages (HTTP and HTTPS only, including redirects) when their RSS
  text is short. Turn this off with *Fetch the article page…*.
- **Credentials:** the API token is stored in your FreshRSS user configuration
  (`data/users/<user>/config.php`, outside the web root in standard
  installations) and is never written back into the settings page.
- **Prompt injection:** an article's text can try to talk the model into a high
  score. The worst outcome is an article that is kept or labelled when it should
  not have been. The model has no other capabilities.

## Data and uninstalling

The extension keeps its state per user in
`data/users/<user>/extensions/AiFilter/`:

- `state.json` holds the watermark and the time of the last run;
- `filter.log` holds the log, trimmed to the last 2,000 lines;
- `run.lock` exists only while a run is in progress.

To uninstall, disable the extension in *Settings → Extensions*, delete the
`xExtension-AiFilter` folder, and optionally delete the data folder above.
Articles that were marked as read and the `Top` label stay as they are.

## License

[MIT](LICENSE)
