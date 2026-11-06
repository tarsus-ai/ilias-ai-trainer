<h1 align="center">Tarsus for ILIAS</h1>

<p align="center">
  An AI learning companion inside every ILIAS course – grounded in the course's own material.<br>
  Learners <b>ask</b>, <b>test</b> and <b>build</b> their knowledge; teachers choose the material and follow the progress.
</p>

<p align="center">
  <a href="https://github.com/tarsus-ai/ilias-ai-trainer/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/tarsus-ai/ilias-ai-trainer?label=release"></a>
  <img alt="ILIAS 9, 10 and 11" src="https://img.shields.io/badge/ILIAS-9%20%7C%2010%20%7C%2011-1f4e79">
  <img alt="Plugin slot" src="https://img.shields.io/badge/slot-RepositoryObject-555">
  <a href="LICENSE"><img alt="Licence GPL-3.0-or-later" src="https://img.shields.io/badge/licence-GPL--3.0--or--later-2e7d32"></a>
</p>

<p align="center">
  <img src="docs/screenshots/25_ask_interactive.png" alt="A learner asks a question and receives an interactive answer page" width="900">
</p>

Tarsus for ILIAS is a repository-object plugin (`RepositoryObject/Tarsus`, object type `xtar`) that connects an ILIAS installation to a [Tarsus](https://tarsus.ai) brain. A *Tarsus* object lives in a course or group like any other object, uses only the material the teacher has indexed and included, and reports back into ILIAS' learning progress. The conversations run in the brain; ILIAS keeps only the figures it needs.

**Contents** · [Features](#features) · [Requirements](#requirements) · [Installation](#installation) · [Updating](#updating) · [Configuration](#configuration) · [Plans, credits and the monthly limit](#plans-credits-and-the-monthly-limit) · [Interaction types](#interaction-types) · [Score and progress](#score-and-progress) · [Brain API](#brain-api) · [Data and privacy](#data-and-privacy) · [Development](#development) · [Licence](#licence)

## Features

### Three ways to learn

| Ask a question | Test knowledge | Build knowledge |
|:--:|:--:|:--:|
| ![Ask a question](docs/screenshots/03_ask_mode.png) | ![Test knowledge](docs/screenshots/05_test_feedback.png) | ![Build knowledge](docs/screenshots/06_build_mode.png) |
| A chat grounded in the included course material, with formulas and tables rendered. | Practice questions of several kinds – single choice, true/false, open questions, rule of three, spreadsheet calculation – with immediate feedback, experience points and a progress bar. | Learning units as slides with a check question, read aloud on request. *Ask about this* sends any slide or question to the chat. |

- **Interactive answers.** With the *AI-driven* interaction type the brain may answer with small HTML pages – animations, simulations, sliders, plots. They run in a sandboxed frame (scripts allowed, no access to ILIAS, its cookies or the page), are sized automatically, can be enlarged and stay in the history.
- **Help where it is needed.** A help button on every screen opens the support links and videos the administrator configured; YouTube and Vimeo play inline.
- **English and German**, following the user's interface language.

### For teachers

| Materials | Learners |
|:--:|:--:|
| ![Materials tab](docs/screenshots/09_materials.png) | ![Learners tab](docs/screenshots/10_learners.png) |
| Index files, web links, content pages, wikis, ILIAS learning modules and glossaries into the brain and decide per object which of them the assistant may use. | Progress, experience points, learning units and score per learner, with a CSV export. The ILIAS learning progress of the object follows the same figures. |

| Analytics | Settings |
|:--:|:--:|
| ![Analytics tab](docs/screenshots/24_analytics.png) | ![Settings](docs/screenshots/23_settings_interaction.png) |
| Requests, AI credits and active learners this month, the last 30 days, the split by mode, the most active learners and the installation's monthly budget. | Modes, interaction type, didactic settings (conversation style, DQR level, target group, taxonomy levels, question types, operators, learning pace) and the completion threshold. Options outside the customer's plan are shown locked. |

### For administrators

| Plan & credits | Status |
|:--:|:--:|
| ![Plan and credits](docs/screenshots/20_admin_plan.png) | ![Status page](docs/screenshots/22_admin_status_usage.png) |
| The customer's subscription, the included credits and price per additional request, the monthly credit limit and the recipients of the warnings. | Connection test, guardrail state, the month's usage counted locally and as charged by the brain, and the estimated cost beyond the included credits. |

- **Monthly credit limit** with banners at 50 %, 75 %, 90 % and 100 % and one e-mail per threshold and month. Raising the limit takes effect at once; lowering it asks for confirmation.
- **Guardrails** on every outbound call: kill switch, circuit breaker, monthly limit and rate limit.
- **Mock mode** answers with a built-in deterministic sample, so the screens can be tried out before an API key exists.

<details>
<summary><b>More screenshots</b></summary>
<br>

| | |
|:--:|:--:|
| ![Welcome](docs/screenshots/02_chat_welcome.png) Welcome card | ![Test question](docs/screenshots/04_test_question.png) Practice question |
| ![Narrated unit](docs/screenshots/07_build_audio.png) Learning unit with narration | ![Check question](docs/screenshots/08_build_question.png) Check question in a learning unit |
| ![Interactive unit](docs/screenshots/27_build_interactive.png) Interactive learning unit | ![Enlarged page](docs/screenshots/28_enlarged.png) Interactive page enlarged |
| ![Discharge curve](docs/screenshots/26_ask_interactive_curve.png) Interactive answer with a plot | ![Help panel](docs/screenshots/29_help_panel.png) Help panel with a support video |
| ![Object settings](docs/screenshots/11_settings.png) Object settings | ![Locked by plan](docs/screenshots/32_settings_locked_by_plan.png) Options locked by the plan |
| ![Limit banner](docs/screenshots/30_limit_banner_teacher.png) Teacher banner when the limit is reached | ![Limit for learners](docs/screenshots/31_limit_learner.png) What learners see when the limit is reached |
| ![Confirm lowering](docs/screenshots/21_admin_limit_confirm.png) Confirmation when lowering the limit | ![Plugin settings](docs/screenshots/13_admin_settings.png) Plugin settings |
| ![Brains](docs/screenshots/14_admin_brains.png) Brains reachable with the API key | ![Status](docs/screenshots/15_admin_status.png) Status page |
| ![Info](docs/screenshots/12_info.png) Info screen | ![German](docs/screenshots/16_german.png) German interface |
| ![Course listing](docs/screenshots/01_course_listing.png) The object in a course | |

</details>

## Requirements

- ILIAS **9**, **10** or **11** (tested on 9.24, 10.12 and 11.5), with the PHP version your ILIAS release requires and the `curl` extension ILIAS needs anyway.
- A Tarsus brain: the API base URL, an API key and a brain the key may use. Without one the plugin runs in mock mode.
- ILIAS' cron job (recommended – see below).

## Installation

1. **Put the plugin into the plugin slot.** The folder must be called `Tarsus`.

   | ILIAS | Plugin folder |
   |---|---|
   | 10, 11 | `public/Customizing/global/plugins/Services/Repository/RepositoryObject/Tarsus` |
   | 9 | `Customizing/global/plugins/Services/Repository/RepositoryObject/Tarsus` |

   Either download `Tarsus-ilias-plugin-<version>.zip` from the [releases](https://github.com/tarsus-ai/ilias-ai-trainer/releases) and unzip it into `.../RepositoryObject/` (the zip already contains the `Tarsus` folder), or clone this repository:

   ```bash
   cd public/Customizing/global/plugins/Services/Repository/RepositoryObject   # ILIAS 9: without public/
   git clone https://github.com/tarsus-ai/ilias-ai-trainer.git Tarsus
   ```

2. **Rebuild ILIAS' artifacts** in the ILIAS root directory so ILIAS picks up the plugin's classes and control structure:

   ```bash
   composer du
   ```

3. **Install and activate** under *Administration › Extending ILIAS › Plugins*: for *Tarsus* choose **Install**, then **Activate**.

   On the first activation the plugin gives the new object type sensible default permissions: course and group members may read Tarsus objects, tutors and administrators of courses and groups may create and manage them. This is written into the role templates and into the local roles of existing courses and groups once; afterwards the permissions are yours to change like any other.

4. **Configure** the plugin (*Configure* in the plugin list) – see [Configuration](#configuration).

5. **Make sure the cron job runs** (`php cli/cron.php run-jobs <user> <client>` in ILIAS 10/11, `php cron/cron.php run-jobs <user> <client>` in ILIAS 9). The plugin's job *Tarsus: synchronise the brain* is activated automatically and runs every 15 minutes: it withdraws material that was deleted from a course, re-sends material that changed, confirms pending uploads, retries deletions the brain did not confirm and fetches the month's usage from the brain. Without it the material work happens when a teacher opens the *Materials* tab, and the usage figure comes from the local ledger only.

Teachers can then add a *Tarsus* object to a course or group. New objects are offline until they are switched online in their settings.

## Updating

1. Replace the `Tarsus` folder with the new version (unzip the new release over it, or `git pull`).
2. Run `composer du` in the ILIAS root directory.
3. Under *Administration › Extending ILIAS › Plugins* choose **Update** for *Tarsus*, then **Refresh Languages**.

Database updates run during the update; settings, material indexes and learner figures are kept. The [changelog](CHANGELOG.md) lists what changed in each version.

## Configuration

*Administration › Extending ILIAS › Plugins › Tarsus › Configure* has five tabs:

| Tab | What it holds |
|---|---|
| **Settings** | Connection to the brain (base URL, API key), mock mode, the assistant's name and avatar, guardrails (kill switch, rate limit), and the help links shown behind the help button – one `Title \| URL` per line. |
| **Plan & credits** | The customer's subscription, included credits and price, the monthly credit limit and the recipients of the warnings (see below). |
| **Brains** | The brains the API key can reach; activate the one this installation should use. |
| **Deletions** | Material withdrawals the brain has not confirmed yet. |
| **Status** | Connection test, guardrail state, usage this month (local and as charged by the brain, with a refresh button) and the estimated cost beyond the included credits. |

A banner on these pages and in the teachers' views shows the state of the monthly budget once half of it is used; learners are told when it is exhausted.

## Plans, credits and the monthly limit

The customer's Tarsus subscription is recorded on the *Plan & credits* tab. Plans, credits and prices are agreed with Tarsus and lern.link; the figures are editable for custom agreements.

| Plan | Modes | Narration | AI-driven interaction | Included credits |
|---|---|:--:|:--:|---|
| **Build Knowledge** (LEARN) | Ask, Test, Build | ✓ | ✓ | 100,000 / month |
| **Knowledge Test** (PRACTICE) | Ask, Test | – | – | 100,000 / month |
| **Ask Question** (ASK) | Ask | – | – | 100,000 / month |
| **Pay as you grow** | Ask, Test, Build | ✓ | ✓ | none – every request is charged |

- Included credits are per **calendar month** and do not carry over. Requests beyond them are charged per request (0.32 EUR unless agreed otherwise).
- The plan is enforced everywhere: modes and options outside the plan are locked in the object settings, hidden from the learner's mode switch and refused by the server.
- The **monthly credit limit** is the most AI credits the installation may spend per calendar month. It defaults to the included credits; `0` means no limit. Once it is reached, AI requests are refused with a clear message for learners and a banner for teachers and administrators. At 50 %, 75 %, 90 % and 100 % a banner appears and one e-mail per threshold and month goes to the configured recipients (or the installation's administrator address). Raising the limit takes effect at once; lowering it shows what has already been used and asks for confirmation.
- **Usage** is counted locally with every request and fetched from the brain's usage endpoint by the cron job and on demand on the *Status* tab. The gate uses the larger of the two figures, so the brain's own number is authoritative when it is available and other systems using the same brain are accounted for.

## Interaction types

Each object has an interaction type, sent to the brain as `interaction_type` with every generate call:

- **trainer-driven** – the brain answers with text, formulas, tables and the usual question widgets.
- **ai-driven** – the brain may additionally answer with HTML pages: animations, simulations, small tools (a rotating cylinder whose volume follows the sliders, a speed-from-distance-and-time experiment, a capacitor discharge curve with a marker, ...). Both chat answers and learning units may be pages.

Pages are rendered in an `<iframe sandbox="allow-scripts allow-forms allow-pointer-lock">` with `srcdoc`, so they can run their scripts but cannot reach ILIAS, its cookies or the surrounding page. A small reporter inside the frame sizes it to its content; an *Enlarge* button opens the page in a dialog. AI-driven interaction is available on the Build Knowledge and Pay as you grow plans.

## Score and progress

Which figure counts as the score and which as the progress depends on the modes active in an object (a mode is active when it is enabled in the settings and allowed by the plan):

| Active modes | Score | Progress |
|---|---|---|
| Test knowledge only | XP in percent | the brain's practice progress |
| Build knowledge only | Learning units in percent | the brain's learning progress |
| Test and Build knowledge | XP in percent | Learning units in percent |
| Neither (Ask a question only) | – | – (read-only activity) |

The *Learners* tab, the CSV export and ILIAS' learning progress all use these rules; a learner counts as completed once the progress reaches the object's completion threshold. Figures are recomputed when the plan changes.

## Brain API

The plugin speaks to the brain over HTTPS with form-data requests carrying `api_key` and `brain_id` (reference: [Tarsus API documentation](https://documenter.getpostman.com/view/23991933/2s9YJXYQT2)):

- `access/track/{text,url,document,audio,video}-bits`, `access/untrack/multiple-resources`, `access/untrack/multiple-assets`, `access/check/tracked-resources` – material indexing and withdrawal,
- `awareness/list/memories`, `awareness/list/clone-voices`, `awareness/get/usage` – brains, voices and the month's AI credits,
- `/api/t0/activity/generate/next/widgets`, `retrieve/next/widgets`, `evaluate/*-question/widget`, `generate/audio/explainer/stack`, `reset/user/widgets` – the learning activity.

Every call passes the guardrail pipeline: kill switch → circuit breaker (opens after five consecutive failures for five minutes) → monthly credit limit → rate limit. Transport and protocol failures are turned into a friendly message; nothing is retried blindly.

## Data and privacy

- The plugin keeps its data in seven tables (`rep_robj_xtar_*`): plugin configuration (including the plan, the limit and the brain's usage figure), the settings of each object, the index map of the material sent to the brain, the inclusions per object, the latest figures per learner, a usage ledger (credits per operation, also behind the analytics) and the guardrail state. Uninstalling the plugin drops them.
- Learners are sent to the brain under a **pseudonym** – an HMAC of the ILIAS user id with a secret generated at installation – together with their display name and interface language. The conversation itself lives in the brain. *Reset chat history* erases the learner's own conversation in the brain and the local figures.
- Interactive pages from the brain run sandboxed (see above) and are never stored in ILIAS beyond the conversation history the brain returns.

## Development

```
plugin.php                 plugin id, version and ILIAS range
classes/                   ILIAS entry points (plugin, object, GUI, access, list GUI, config GUI)
src/                       namespace TarsusIlias
  Activity/                settings, conversation state, scoring
  Client/                  HTTP client, mock client, client factory
  Cron/                    the synchronisation job
  Guardrail/               kill switch, breaker, budget, rate limit
  Index/, Material/        material discovery and indexing state machine
  Output/                  views, shaper of brain responses, icons
  Service/                 learner API (AJAX endpoints)
  Support/                 configuration, plan, budget, help links, ILIAS glue
sql/dbupdate.php           database steps
templates/                 CSS, JavaScript, images and the mock mode's demo pages
lang/                      ilias_en.lang, ilias_de.lang
docs/screenshots/          the images in this README
```

- **Mock mode** (default after installation) makes the whole UI usable without a brain: the mock client answers deterministically, including the interactive demo pages, and records credits like the real one, so plans, limits and analytics can be exercised locally.
- The plugin is tested end to end with Playwright against ILIAS 9, 10 and 11: clean install from the release zip, update in place, permissions, all three modes, learning progress, copy, configuration pages, plan gating, the monthly limit and the cron job.
- A version bump needs `composer du` before ILIAS offers **Update** in the plugin list.
- **Releases** are published by the [Release workflow](.github/workflows/release.yml): bump `$version` in `plugin.php`, add the version's section to `CHANGELOG.md` and push to `main` – the workflow tags the commit, builds `Tarsus-ilias-plugin-<version>.zip` with `git archive` (repository-only files such as `docs/` are left out) and creates the GitHub release with the changelog section as notes.

Issues and pull requests are welcome.

## Licence

GPL-3.0-or-later – see [LICENSE](LICENSE). © 2026 lern.link GmbH.
