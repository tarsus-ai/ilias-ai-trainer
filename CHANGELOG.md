# Changelog

All notable changes to the Tarsus plugin for ILIAS. The plugin follows
[semantic versioning](https://semver.org/); the version is in `plugin.php`.

## [2.2.0] – 2026-11-25

The plugin follows ILIAS's development rules: forms, dialogs and buttons of ILIAS's UI framework, the coach's script as checked ES6 modules, calls to Tarsus through ILIAS's HTTP connection, uploads through ILIAS's upload service. Learners and teachers keep every function; a few screens look like the rest of ILIAS now.

### Changed

- **Settings** of the AI trainer, the **plugin configuration** and **Plan & credits** use ILIAS's standard forms (UI framework) with the same fields, defaults, locks and checks. A wrong entry is marked at its field. Lowering the monthly limit still asks first.
- **Reset chat history** is a button above the coach that opens ILIAS's confirmation dialog; no extra page.
- Ending a live conversation by switching the mode, and removing material, ask in a dialog of the coach instead of the browser's pop-up: the focus starts on *Cancel*, <kbd>Tab</kbd> stays inside, <kbd>Esc</kbd> cancels.
- The assistant's picture is uploaded with ILIAS's file field; ILIAS keeps the upload until the configuration is saved and removes it afterwards.
- Spoken questions go through ILIAS's upload service, so the installation's rules for uploads (blocked file types, virus scanner) apply. If an installation does not accept audio files, the learner is told so.
- Calls to Tarsus use ILIAS's HTTP connection (ilCurlConnection) with ILIAS's proxy settings.
- When an ILIAS user account is deleted, the scheduled task also erases the account's conversations in the brain and its figures in the plugin (see [PRIVACY.md](PRIVACY.md)).

### For developers

- The coach's script is written as ES6 modules in `templates/js/src`, checked with ILIAS's ESLint configuration (no findings) and bundled with Rollup into `templates/js/dist/tarsus.js`.
- Exceptions derive from `ilException`, log entries go to ILIAS's log (component `xtar`), requests are read through ILIAS's request wrapper, the reload of the voices is protected by ILIAS's token, unused language entries are removed, and a [PRIVACY.md](PRIVACY.md) describes the personal data the plugin keeps.

To update, run the installer command again – it also runs `composer du`, which ILIAS needs for this version's new upload of the assistant's picture – then click **Update** and **Refresh Languages** in the plugin list (*Administration › Extending ILIAS › Plugins*). Your settings, material and learners' figures are kept.

## [2.1.2] – 2026-11-25

A security update: please install it.

### Security

- The page of the **live interaction** now runs in a sandboxed frame like the AI trainer's other pages. Before, it ran in a frame without a sandbox, so the call page's scripts could reach the ILIAS page, the cookies and the user's session. Browsers give a sandboxed page no microphone, so the plugin records it and passes the sound on to the call. Nothing changes for learners: the call starts, sounds and ends as before. A call that starts in another tab ends the running one, as before.

After updating, click **Update** in the plugin list (*Administration › Extending ILIAS › Plugins*). Your settings, material and learners' figures are kept.

## [2.1.1] – 2026-11-24

The screen reader and keyboard support described for 2.1.0, now in the download.

### Fixed

- The 2.1.0 zip was built from an earlier commit and did not contain the screen reader and keyboard support listed for 2.1.0 (skip link, headings on every card, read-out answers and results, keyboard focus on new questions and verdicts, dialogs that keep the focus, right and wrong in words and symbols). 2.1.1 contains it. After updating, click **Update** and then **Refresh Languages** in the plugin list, so the new texts for screen readers appear.
- The release workflow builds each version from the newest commit that declares it.

## [2.1.0] – 2026-11-24

Tarsus's texts look the way they do in the Tarsus app, and the coach works with screen readers and the keyboard alone.

### Added

- **Screen readers and keyboard** (WCAG 2.2 AA): a skip link to the input field; the coach is a named region with a heading on every card; answers, mode changes, slides, verdicts and results are read out; a new question, the verdict after **Check** and a new learning step get the keyboard focus; side panels, pictures and enlarged pages are dialogs that keep the focus and give it back; right and wrong are words and a ✓ or ✗, not only a colour; **Check** with nothing chosen says what is missing. See the README's *Accessibility* section.

### Changed

- **Text and formulas like in Tarsus**: answers, learning units, questions, answer options, feedback and sources are rendered in the browser with the pipeline the Tarsus app uses – React-Markdown's CommonMark parser with GitHub tables, task lists, strikethrough and footnotes, and formulas with KaTeX 0.16.47 (chemistry with mhchem). Tables, lists, code and formulas look the same in every ILIAS skin.
- Formulas no longer depend on ILIAS's MathJax set-up: KaTeX and its fonts ship with the plugin, so nothing is loaded from other servers.
- Answer options of questions can contain formulas, as in Tarsus.
- The input field stays focused (read-only) while Tarsus answers, instead of being switched off.
- Red action buttons (e.g. *Remove* on the material list) use a darker red that is readable on white.

### Security

- Same rules as React-Markdown: HTML inside a text is shown as text, and links with any protocol but http(s), mailto, irc(s) or xmpp are removed. Links open in a new tab.

## [2.0.1] – 2026-11-24

Runs on more ILIAS and PHP versions.

### Added

- **ILIAS 12**: the plugin installs and runs on ILIAS 12 (tested with the 12.0 beta, on PHP 8.4 and 8.5).

### Changed

- The installer starts composer with a PHP version your ILIAS accepts – also on servers with several PHP versions, and when it runs composer as the web server's user.

### Fixed

- No deprecation notices under PHP 8.5: two calls that have done nothing since PHP 8.0 (`curl_close()` and `imagedestroy()`) are gone.

## [2.0.0] – 2026-11-24

Catches the plugin up with Tarsus: everything the coach shows comes from the Tarsus API, and nothing is assumed about what happens in the brain.

### Added

- **Live interaction**, a fourth mode next to *Build knowledge*, *Test knowledge* and *Ask a question*: a voice call with the AI trainer that shows slides while it talks. Teachers switch it on or off per object like the other modes. Included in the Build Knowledge and Pay as you grow packages.
- **Spoken questions** in *Ask a question*: record, listen back, send. The answer card shows the recording and the text Tarsus heard. Can be switched off per object.
- **View sources** at the top right of an answer: the passages, tables and pictures of the course material Tarsus used, in a panel that slides in from the right, each with a link to the material in ILIAS.
- **Ask about this** at the top right of learning stacks and quiz questions: the next question refers to that stack or question.
- **Teacher panels** that slide in from the right, as in Tarsus: *Settings*, *Evaluation* (every learner's result, progress and learning status, the distribution of results, CSV export) and *History* (one learner's conversation, read only, with the growth of experience points and learning units over the last 30 days).
- **Thinking level** 1–4 per object (default 4), replacing "Think longer".
- **Visual accessibility** per object, for learners who use a screen reader.
- **AI-driven** pages in *Build knowledge* and *Ask a question*; pictures and diagrams stay where Tarsus puts them (above or beside the text), animated GIFs included.

### Changed

- **AI credits come from the brain only**: the plugin shows and enforces the usage figure Tarsus reports (fractions included) and no longer estimates or counts credits itself.
- **ILIAS learning progress** shows the result in percent: the experience points as a share of all (the learning units when only *Build knowledge* is active). Experience points and learning units themselves are only shown.
- The *Learners* and *Analytics* tabs are replaced by the teacher panels; the CSV export is in *Evaluation*.
- Widgets form a centred column as in Tarsus, and the modes are in the same order as in Tarsus.
- When a learning path is finished, Tarsus repeats its closing card: it is shown once.
- Every refusal Tarsus can send has its own clear message in English and German; the circuit breaker opens only on outages, not on refusals.
- **Changing the connection** (leaving demo mode, another server, brain ID or license key) sends the material the previous brain held to the new one by itself; material indexed in demo mode no longer shows as indexed against a real brain.
- A result that falls back to 0 (a reset chat, another brain) is written to ILIAS' learning progress too.

### Removed

- The local credit estimates and the counting panel.
- The "Think longer" setting.

### Database

- Step 10: per-object settings for the live interaction, the thinking level, visual accessibility, sources and spoken questions.

## [1.2.1] – 2026-11-13

### Changed

- The plugin is published and maintained by Tarsus GmbH: author and responsible developer now read *Tarsus GmbH* throughout – `plugin.php` (shown in the ILIAS plugin administration), every file header and the README. No functional change, no database step.

## [1.2.0] – 2026-11-12

### Added

- **Partner agreement** (tarsus.ai/impressum/partnervereinbarung, 12 November 2026) implemented:
  - *Packages:* the feature matrix "Funktionen je Paket" is the single source for what a package allows – Ask Question, Knowledge Test, Build Knowledge (with audio explainer, voice input and agent mode) and Pay as you grow; the *Plan & credits* tab shows the matrix with the current package marked, and every configuration page says so while no package is set on a live connection.
  - *License key:* existing Tarsus customers enter their license key next to the API key (*Settings*); every call then sends the API key and the license key instead of the brain ID, and the status pages show that Tarsus bills them directly – no partner price is applied to their usage.
  - *AI interactions:* the ledger counts calls the way the agreement does – one per answer, learning step, quiz question or audio explainer, none for single-choice and true/false corrections, one per index request (media: one per started two hours) – instead of the earlier estimate of six credits per call; single-choice and true/false corrections pass even when the monthly limit is reached; the month's cost is interactions beyond the included amount times the price; the counting rules are listed on the *Plan & credits* tab.
  - *Testing:* the connection settings and the *Brains* tab explain that there are no private test brains – the shared sandbox brain is billed like production, mock mode is the free way to try the plugin.
- New question type **Accounting exercise** (German: *Buchhaltung*) in the Test knowledge settings. It requests `accountingWidget`; the brain answers with spreadsheet-type tasks, so display and evaluation are unchanged.
- PHPUnit tests of the agreement's rules (`tests/`, not in the release zip).

### Changed

- Mock mode: the sample brain names no longer contain id-like segments.
- The *Status* tab shows the customer type (partner customer or Tarsus license key) and the booked package; *Connection configured* accepts a license key in place of a brain ID.

### Database

- Step 9: ledger rows recorded with the earlier six-credit estimate are recalibrated to one interaction each.

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

[2.2.0]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v2.2.0
[2.1.2]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v2.1.2
[2.1.1]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v2.1.1
[2.1.0]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v2.1.0
[2.0.1]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v2.0.1
[2.0.0]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v2.0.0
[1.2.1]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v1.2.1
[1.2.0]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v1.2.0
[1.1.0]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v1.1.0
[1.0.0]: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v1.0.0
