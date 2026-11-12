# Changelog

All notable changes to the Tarsus plugin for ILIAS. The plugin follows
[semantic versioning](https://semver.org/); the version is in `plugin.php`.

## [Unreleased]

- Mock mode: the sample brain names no longer contain id-like segments.

### Added

- **AI interactions counted the way Tarsus counts them.** The ledger, the
  monthly limit, its warnings, the cost estimate and the analytics work on AI
  interactions by the counting rules Tarsus agrees with its partners (public
  overview: [tarsus.ai/ki-interaktionen](https://tarsus.ai/ki-interaktionen))
  instead of an estimate of six AI credits per request:
  - one per request in which the AI answers, creates, evaluates, narrates or
    indexes: a question, the next quiz questions, the next learning step (agent
    mode included), the audio explainer, the evaluation of a free answer or a
    calculation;
  - none for the automatic correction of single-choice and true/false answers,
    for reading the history, for checking, withdrawing and deleting material and
    for querying the usage;
  - one per upload when material is indexed – audio and video one per started
    two hours, read from the file (getID3, with built-in readers for WAV and the
    MPEG-4 family);
  - voice input (2) and `think_longer` (doubles any request) are part of the
    rules, although the plugin sends neither yet.

  The count is derived from the request that actually goes over the wire:
  `Support\Interactions` lists all 79 requests of the API documentation with
  their rule, the HTTP client counts what it sends and the mock client reports
  the same figures.
- **License-Key** for existing Tarsus customers (*Settings › Tarsus connection ›
  Customer*): the key is sent together with the API key in place of the brain
  ID, in a configurable form field (`license_key` by default). Such customers are
  billed by Tarsus directly, so the cost estimate names no partner price for
  them. A missing key is an error; the plugin never falls back to the brain ID.
  **The Tarsus API does not accept the key yet – leave the setting on
  *brain ID* until Tarsus confirms it.**
- **Packages as Tarsus offers them**: "Ask questions", "Test knowledge", "Build
  knowledge" and Pay-As-You-Grow, with the functions of each in one table
  (`Plan::FEATURES`: the three modes, audio explainer, voice input, agent mode).
  A request for a function outside the booked package is refused with its own
  message, and the usage panel says so while no package has been set.
- Uploads are held by the monthly limit like every other counted request. The
  limit is asked before the previous version is withdrawn, so a refused re-index
  – by a teacher or by the cron job – leaves the brain as it was.
- The usage panel (*Package & AI interactions*, *Status*) shows who is billed –
  the partner, or Tarsus for a License-Key customer – and how AI interactions
  are counted.
- A test suite that needs no ILIAS (`php tests/run.php`) and a workflow that
  runs it on every push and pull request.

### Changed

- "AI credits" are called "AI interactions" throughout, in English and German;
  the tab *Plan & credits* is now *Package & AI interactions*.
- The cost estimate charges the AI interactions beyond the included ones. It
  used to count requests against credits.
- The automatic correction of single-choice and true/false answers stays
  possible when the monthly limit is reached: it costs nothing.

### Database

- Step 9: ledger rows written by versions up to 1.1.0 are converted to AI
  interactions (every charged request and every upload becomes one) and the
  month's warnings start afresh. ILIAS runs the step when the plugin is
  updated, so the release that carries it needs a new version in `plugin.php`.

## [1.1.0] – 2026-11-06

### Added

- **Plans and credits** (*Plugins › Tarsus › Configure › Plan & credits*): the
  customer's subscription – Build Knowledge (LEARN), Knowledge Test (PRACTICE),
  Ask Question (ASK) or Pay as you grow – with included AI credits per month,
  price per additional request and currency, editable for custom agreements.
  Modes, narration and AI-driven interaction follow the plan everywhere: in the
  object settings (locked options), in the learner's mode switch and in the
  server-side checks.
- **Monthly credit limit** with warnings at 50 %, 75 %, 90 % and 100 %: a banner
  for teachers and administrators, one e-mail per threshold and month to the
  configured recipients (fallback: the installation's administrator address),
  and a clear refusal for learners once the limit is reached. Raising the limit
  takes effect at once; lowering it asks for confirmation.
- **Usage from the brain**: the cron job (and the *Status* tab on demand) fetches
  the month's AI credits from `awareness/get/usage`; the limit uses the larger of
  the brain's figure and the local ledger. The *Status* tab estimates requests
  beyond the included credits and their cost.
- **Interaction type** per object (`interaction_type` = `trainer-driven` or
  `ai-driven`), sent with every generate call. AI-driven answers and learning
  units may be interactive HTML pages; they are rendered in a sandboxed frame,
  sized automatically, can be enlarged and are kept in the history.
- **Analytics** tab for teachers: requests, credits and active learners this
  month, requests per day for the last 30 days, split by mode, most active
  learners and the installation's monthly budget.
- **Help** button on every screen with the support links and videos configured
  by the administrator (YouTube and Vimeo play inline).
- Mock mode ships three interactive demo pages (speed, capacitor discharge,
  cylinder volume).

### Changed

- Score and progress now depend on the active modes: Test knowledge alone –
  score = XP in percent, progress = the brain's practice progress; Build
  knowledge alone – score = learning units in percent, progress = the brain's
  learning progress; both – score = XP in percent, progress = learning units in
  percent; neither – a read-only activity without score or progress. Existing
  figures are recomputed when the plan changes.
- The fixed credit budget of 1.0.0 was replaced by the monthly limit above.

### Database

- Step 8: column `interaction` on `rep_robj_xtar_data`.

## [1.0.0] – 2026-11-06

First release for ILIAS 9, 10 and 11.

- Repository object *Tarsus* (`xtar`) for courses and groups with the modes
  Ask a question, Test knowledge and Build knowledge, grounded in the course's
  own material.
- Materials tab: index files, web links, content pages, wikis, learning
  modules and glossaries into the brain; include/exclude per object; sync.
- Learners tab with progress, XP, learning units, score and CSV export; ILIAS
  learning progress integration; copy support.
- Didactic settings per object (conversation style, DQR level, target group,
  taxonomy levels, question types, operators, learning pace, completion
  threshold) and an offline/online switch.
- Plugin configuration with connection test, brain selection, pending
  deletions and a status page; mock mode for installations without an API key.
- Guardrails for outbound calls: kill switch, circuit breaker, rate limit.
- Cron job *Tarsus: synchronise the brain* (every 15 minutes).
- Default permissions seeded on first activation for course and group roles.
- English and German.

[1.1.0]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v1.1.0
[1.0.0]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v1.0.0
