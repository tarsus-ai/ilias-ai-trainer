<p align="center">
  <a href="https://tarsus.ai"><img src="https://framerusercontent.com/images/bhXmm51vAp3AcBXb7St6VJakI4.webp" alt="The Tarsus AI learning coach: a course with learning goals, explanation cards, exercises and progress" width="920"></a>
</p>

<h1 align="center">Tarsus for ILIAS</h1>

<p align="center">
  <b>The Tarsus AI learning coach inside every ILIAS course.</b><br>
  Explanations, graphics, simulations and exercises – generated in the dialogue, from your own course material only.<br>
  Learners <b>build</b>, <b>test</b> and <b>ask</b>; teachers choose the material and follow the progress.
</p>

<p align="center">
  <a href="https://github.com/tarsus-ai/ilias-ai-trainer/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/tarsus-ai/ilias-ai-trainer?label=release&color=1f4e79"></a>
  <img alt="ILIAS 9, 10 and 11" src="https://img.shields.io/badge/ILIAS-9%20%7C%2010%20%7C%2011-1f4e79">
  <img alt="Languages" src="https://img.shields.io/badge/UI-English%20%7C%20Deutsch-555">
  <a href="LICENSE"><img alt="Licence GPL-3.0-or-later" src="https://img.shields.io/badge/licence-GPL--3.0--or--later-2e7d32"></a>
</p>

<p align="center">
  <a href="https://tarsus.ai">Website</a> ·
  <a href="https://tarsus.ai/funktionen">Features</a> ·
  <a href="#getting-access">Get access</a> ·
  <a href="https://github.com/tarsus-ai/ilias-ai-trainer/releases">Download</a> ·
  <a href="#installation">Install</a> ·
  <a href="#watch">Videos</a> ·
  <a href="https://www.youtube.com/@tarsus-ai">YouTube</a>
</p>

---

Tarsus is an AI learning coach for training providers, companies and universities. It explains, lets learners practise and answers their questions – always from the organisation's own documents, at the right level for each person. **Tarsus for ILIAS** brings this coach into ILIAS as a repository object (`RepositoryObject/Tarsus`, type `xtar`): a *Tarsus* object lives in a course or group like any other object, uses only the material the teacher has indexed and included, and reports back into ILIAS' learning progress. The conversations run in the Tarsus brain; ILIAS keeps only the figures it needs.

> This repository contains **no API keys and no brain IDs**. The plugin ships in mock mode; the credentials for a real brain are entered in ILIAS' plugin configuration and stay in the ILIAS database – the API key is never sent to a browser. See [Getting access](#getting-access).

**Contents** · [What the coach does](#what-the-coach-does) · [Inside ILIAS](#inside-ilias) · [Watch](#watch) · [Getting access](#getting-access) · [Requirements](#requirements) · [Installation](#installation) · [Updating](#updating) · [Configuration](#configuration) · [Packages, AI interactions and the monthly limit](#packages-ai-interactions-and-the-monthly-limit) · [Interaction types](#interaction-types) · [Score and progress](#score-and-progress) · [Data and privacy](#data-and-privacy) · [Development](#development) · [Acknowledgements](#acknowledgements) · [Licence](#licence)

## What the coach does

### Three ways to learn

| Build knowledge | Test knowledge | Ask a question |
|:--:|:--:|:--:|
| <img src="https://framerusercontent.com/images/s0fmuHNzzQzugFWdZ64BNRzBZVE.webp" alt="Explanation card built from the course material" width="400"> | <img src="https://framerusercontent.com/images/hgagwUKE5rL19zOgU4zCXHDL8wk.webp" alt="Spreadsheet task with line-by-line feedback" width="400"> | <img src="https://framerusercontent.com/images/Gg6PdLZrXoksmnEkebq2iomzcCM.webp" alt="The coach answers a question and shows the calculation path" width="400"> |
| Explanation cards, one idea at a time, with examples for the target group and optional narration. A check question closes each unit. | Exercises with feedback that moves learners on: single choice, true/false, open questions, rule of three, spreadsheet calculations and accounting exercises – with experience points and a progress bar. | Questions in the learner's own words, by text or voice, answered from the included material – with the calculation path when it is a task. |

### Graphics, simulations and 3D models on demand

| | | |
|:--:|:--:|:--:|
| <img src="https://framerusercontent.com/images/Cosivt9X6HrNKgVnRe4uAuwSXI.webp" alt="Rotatable 3D cylinder with diameter and height" width="400"> | <img src="https://framerusercontent.com/images/uZ93hce6VN7ddmDdqNunRz8mg.webp" alt="Interactive characteristic curve: a slider moves the operating point" width="400"> | <img src="https://framerusercontent.com/images/XuhQqOxDZ7REyDl40HnvMnVjpms.webp" alt="Decision tree: what does the body orbit?" width="400"> |
| **3D models** that learners can turn and measure. | **Simulations** where a slider changes the physics and the result follows. | **Diagrams and animations** drawn for the question at hand. |

With the *AI-driven* interaction type the coach answers inside ILIAS with such pages – small HTML programs rendered in a sandboxed frame. See [Interaction types](#interaction-types).

### Many task formats

| | | | |
|:--:|:--:|:--:|:--:|
| <img src="https://framerusercontent.com/images/sdtCvG7M3Q59Nl80mwa2yOOaMTY.webp" alt="Single-choice question with explanation" width="300"> | <img src="https://framerusercontent.com/images/du1FTrsuwAElUTZUEEC5v1euI.webp" alt="Open question with partial credit and feedback on what is missing" width="300"> | <img src="https://framerusercontent.com/images/uNSphTDFSP6R85PZw7wZVvGQK0.webp" alt="True-or-false task with a check button" width="300"> | <img src="https://framerusercontent.com/images/rmkJPlhI2NqsHY2Xt1UFnddNeoU.webp" alt="Calculation chain with two correctly computed values" width="300"> |
| Single choice with an explanation | Open questions with partial credit | True or false | Calculation chains, spreadsheets and accounting exercises |

### The right level for every person

The coach adapts to the **DQR level**, the **taxonomy stage** (Anderson & Krathwohl), the **learning pace** and the **target group** that the teacher sets per object. Which task comes next is decided from the learner's answers – the same ideas as in item response theory – so strong learners are stretched and nobody is left behind. Read more in the Tarsus [Praxiswissen](https://tarsus.ai/praxiswissen) articles and watch the explainers under [Watch](#watch).

## Inside ILIAS

<p align="center">
  <img src="docs/screenshots/25_ask_interactive.png" alt="A learner asks a question in ILIAS and receives an interactive answer page" width="900">
</p>

### For learners

| Build knowledge | Test knowledge | Ask a question |
|:--:|:--:|:--:|
| ![Build knowledge in ILIAS](docs/screenshots/06_build_mode.png) | ![Test knowledge in ILIAS](docs/screenshots/05_test_feedback.png) | ![Ask a question in ILIAS](docs/screenshots/03_ask_mode.png) |
| Learning goals, then units as slides with a check question; read aloud on request. *Ask about this* sends any slide to the chat. | Practice questions with immediate feedback, experience points and progress. | A chat grounded in the included material, with formulas and tables rendered – and interactive pages on AI-driven objects. |

- A **help button** on every screen opens the support links and videos the administrator configured; YouTube and Vimeo play inline.
- **English and German**, following the user's interface language.

### For teachers

| Materials | Learners |
|:--:|:--:|
| ![Materials tab](docs/screenshots/09_materials.png) | ![Learners tab](docs/screenshots/10_learners.png) |
| Index files, web links, content pages, wikis, ILIAS learning modules and glossaries into the brain and decide per object which of them the coach may use. | Progress, experience points, learning units and score per learner, with a CSV export. ILIAS' own learning progress follows the same figures. |

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
| ![Confirm lowering](docs/screenshots/21_admin_limit_confirm.png) Confirmation when lowering the limit | ![Plugin settings](docs/screenshots/13_admin_settings.png) Plugin settings (mock values) |
| ![Brains](docs/screenshots/14_admin_brains.png) Brains reachable with the API key (mock values) | ![Status](docs/screenshots/15_admin_status.png) Status page |
| ![Info](docs/screenshots/12_info.png) Info screen | ![German](docs/screenshots/16_german.png) German interface |
| ![Course listing](docs/screenshots/01_course_listing.png) The object in a course | |

</details>

## Watch

Explainers from the [Tarsus YouTube channel](https://www.youtube.com/@tarsus-ai/videos) (German):

<table>
  <tr>
    <td align="center" width="33%">
      <a href="https://www.youtube.com/watch?v=JuZLVYBUBLA"><img src="https://img.youtube.com/vi/JuZLVYBUBLA/hqdefault.jpg" alt="Tarsus | So funktioniert die Tarsus Architektur" width="300"></a><br>
      <b>How the Tarsus architecture works</b><br><sub>What happens between a learner's question and the coach's answer</sub>
    </td>
    <td align="center" width="33%">
      <a href="https://www.youtube.com/watch?v=kXTSVUBS9GQ"><img src="https://img.youtube.com/vi/kXTSVUBS9GQ/hqdefault.jpg" alt="E-Learning vs. Rapid Learning" width="300"></a><br>
      <b>E-learning vs. rapid learning</b><br><sub>Why generating the course in the dialogue beats producing it up front</sub>
    </td>
    <td align="center" width="33%">
      <a href="https://www.youtube.com/watch?v=NSif7VgL_w4"><img src="https://img.youtube.com/vi/NSif7VgL_w4/hqdefault.jpg" alt="Tarsus KI-Lerncoach | Bei BZEcom mit Daniel Hobein" width="300"></a><br>
      <b>Tarsus in practice</b><br><sub>The AI learning coach at BZEcom, with Daniel Hobein</sub>
    </td>
  </tr>
</table>

The ideas behind the coach, explained in short articles with videos:

- **[Item response theory with AI](https://tarsus.ai/praxiswissen/item-response-theory)** – why the coach weighs ability and task difficulty instead of counting right answers, and how that picks the next task.
- **[Taxonomies in e-learning](https://tarsus.ai/praxiswissen/dihk-taxonomie-stufen)** – thinking levels and knowledge types after Anderson & Krathwohl, and the "knowledge cube" the coach uses to see strengths, gaps and learning paths.
- **[The learning rate](https://tarsus.ai/praxiswissen/lernrate-adaptives-lernen)** – how adaptive learning updates a learner's level after each answer, and why surprising answers count more.
- **[DQR levels](https://tarsus.ai/praxiswissen/dqr-niveaus)** · **[Avoiding AI hallucinations](https://tarsus.ai/praxiswissen/ki-halluzinationen)** · **[Exam simulations](https://tarsus.ai/praxiswissen/pruefungssimulation-erstellen)** · all articles: [Praxiswissen](https://tarsus.ai/praxiswissen)

## Getting access

The plugin needs a Tarsus brain: the API base URL, an **API key** and a **brain ID** – or, for existing Tarsus customers, a **license key** in place of the brain ID. These are issued by Tarsus for your organisation – they are not in this repository and not in any release. Tarsus activates keys and brains by hand; there are no private test brains, only a sandbox brain shared by all partners on which every request is billed like any other.

- **Request access:** write to [info@tarsus.ai](mailto:info@tarsus.ai?subject=Tarsus%20for%20ILIAS) or [book an introductory call](https://outlook.office.com/book/Erstgespraech@tarsus.ai/). More about the product on [tarsus.ai](https://tarsus.ai).
- **Try the coach first:** a free account on [zero.tarsus.ai](https://zero.tarsus.ai/) lets you upload your own documents and talk to the coach before anything is installed.
- **On-premise:** Tarsus can also deploy the entire Tarsus stack on your own infrastructure if your organisation requires it. [Talk to us](mailto:info@tarsus.ai?subject=Tarsus%20on-premise).
- **Packages:** Ask Question, Knowledge Test, Build Knowledge or Pay as you grow – see [Packages, AI interactions and the monthly limit](#packages-ai-interactions-and-the-monthly-limit).

Until a brain is configured the plugin runs in **mock mode** with simulated answers, so administrators and teachers can explore every screen.

## Requirements

- ILIAS **9**, **10** or **11** (tested on 9.24, 10.12 and 11.5), with the PHP version your ILIAS release requires and the `curl` extension ILIAS needs anyway.
- A Tarsus brain ([Getting access](#getting-access)) – or mock mode for a look around.
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
| **Settings** | Connection to the brain (base URL, API key, brain ID, and the license key of an existing Tarsus customer), mock mode, the coach's name and avatar, guardrails (kill switch, rate limit), and the help links shown behind the help button – one `Title \| URL` per line. |
| **Plan & credits** | The booked package, included AI interactions and price, the monthly limit and the recipients of the warnings (see below), plus the package matrix and the counting rules of the partner agreement for reference. |
| **Brains** | The brains the API key can reach; activate the one this installation should use. |
| **Deletions** | Material withdrawals the brain has not confirmed yet. |
| **Status** | Connection test, customer type (partner customer or Tarsus license key), booked package, guardrail state, usage this month (local and as charged by the brain, with a refresh button) and the estimated cost beyond the included amount. |

A banner on these pages and in the teachers' views shows the state of the monthly budget once half of it is used; learners are told when it is exhausted.

## Packages, AI interactions and the monthly limit

The package the customer booked is recorded on the *Plan & credits* tab. The plugin implements the rules of the [Tarsus partner agreement](https://tarsus.ai/impressum/partnervereinbarung): a customer reaches only the features of the booked package, usage is counted in AI interactions the way Tarsus counts them, and existing Tarsus customers are recognised by their license key. The figures are editable for custom agreements.

**Features per package** – each package contains the one before it:

| Feature | Ask Question | Knowledge Test | Build Knowledge | Pay as you grow |
|---|:--:|:--:|:--:|:--:|
| Ask a question: the AI answers from the documents | ✓ | ✓ | ✓ | ✓ |
| Test knowledge: the AI creates and marks quizzes | – | ✓ | ✓ | ✓ |
| Build knowledge: the coach explains step by step | – | – | ✓ | ✓ |
| Audio explainer in the coach | – | – | ✓ | ✓ |
| Voice input in the coach | – | – | ✓ | ✓ |
| Agent mode (AI-driven interaction type) | – | – | ✓ | ✓ |

The package is enforced everywhere: features outside it are locked in the object settings, hidden from the learner's mode switch and refused by the server. The subscription packages include 100,000 AI interactions per calendar month (editable); Pay as you grow includes none. While no package is set on a live connection, the configuration pages say so – until then Build Knowledge is assumed.

**How AI interactions are counted** – the plugin records these figures in its ledger for every call it makes:

| What the AI does | AI interactions |
|---|:--:|
| Answer a question; create the next learning step, slide or quiz question; a step in agent mode | 1 |
| Audio explainer of a learning unit | 1 |
| Spoken question or answer (audio plus an AI step) | 2 |
| Mark an open question, a rule of three or a spreadsheet calculation | 1 |
| Correct a single-choice or true/false answer | 0 |
| Index text, a table, a question, a URL, a document or an image (per request) | 1 |
| Index audio or video | 1 per started two hours |
| Manage keys and brains, read the usage, retrieve, list, check, release, edit or delete content | 0 |

"Think longer" doubles any request; the plugin does not use it. The public overview is at [tarsus.ai/ki-interaktionen](https://tarsus.ai/ki-interaktionen).

- Included interactions are per **calendar month** and do not carry over. Interactions beyond them are charged per interaction (0.32 EUR flat for partner customers unless agreed otherwise); the *Status* tab shows the month's figure.
- **License key:** an existing Tarsus customer enters the license key next to the API key. Every call then carries the API key and the license key – and no brain ID, the two forms never mix – and the status pages show that Tarsus bills this customer directly; no partner price is applied.
- The **monthly limit** is the most AI interactions the installation may spend per calendar month. It defaults to the included amount; `0` means no limit. Once it is reached, AI requests are refused with a clear message for learners and a banner for teachers and administrators (single-choice and true/false corrections still pass: they cost nothing). At 50 %, 75 %, 90 % and 100 % a banner appears and one e-mail per threshold and month goes to the configured recipients (or the installation's administrator address). Raising the limit takes effect at once; lowering it shows what has already been used and asks for confirmation.
- **Usage** is counted locally with every request and fetched from the brain's usage endpoint by the cron job and on demand on the *Status* tab. The gate uses the larger of the two figures, so the brain's own number is authoritative when it is available and other systems using the same brain are accounted for.

## Interaction types

Each object has an interaction type, sent to the brain as `interaction_type` with every generate call:

- **trainer-driven** – the coach answers with text, formulas, tables and the usual question widgets.
- **ai-driven** – the coach may additionally answer with HTML pages: animations, simulations, small tools (a rotating cylinder whose volume follows the sliders, a speed-from-distance-and-time experiment, a capacitor discharge curve with a marker, …). Both chat answers and learning units may be pages.

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

## Data and privacy

- The plugin talks to the brain over HTTPS only; every call carries the installation's API key and brain ID – or, for an existing Tarsus customer, the API key and the license key – and passes the guardrail pipeline (kill switch → circuit breaker, which opens after five consecutive failures for five minutes → monthly credit limit → rate limit). Transport and protocol failures are turned into a friendly message; nothing is retried blindly.
- The plugin keeps its data in seven tables (`rep_robj_xtar_*`): plugin configuration (including the plan, the limit and the brain's usage figure), the settings of each object, the index map of the material sent to the brain, the inclusions per object, the latest figures per learner, a usage ledger (credits per operation, also behind the analytics) and the guardrail state. Uninstalling the plugin drops them.
- Learners are sent to the brain under a **pseudonym** – an HMAC of the ILIAS user id with a secret generated at installation – together with their display name and interface language. The conversation itself lives in the brain. *Reset chat history* erases the learner's own conversation in the brain and the local figures.
- Interactive pages from the brain run sandboxed (see above) and are never stored in ILIAS beyond the conversation history the brain returns.
- The coach does not search the internet: it generates explanations and answers exclusively from the documents your organisation provides. Tarsus stores your content at Microsoft Azure in Germany, GDPR-compliant – and the entire stack can be deployed on-premise on request ([Getting access](#getting-access)).

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
  Support/                 configuration, package (Plan), counting rules (Interactions), budget, help links, ILIAS glue
sql/dbupdate.php           database steps
templates/                 CSS, JavaScript, images and the mock mode's demo pages
lang/                      ilias_en.lang, ilias_de.lang
tests/                     PHPUnit tests of the agreement rules (not in the release zip)
docs/screenshots/          the ILIAS screenshots in this README
```

- **Mock mode** (default after installation) makes the whole UI usable without a brain: the mock client answers deterministically, including the interactive demo pages, and records credits like the real one, so plans, limits and analytics can be exercised locally. All mock brain names start with `mock-`.
- **Unit tests** cover the partner agreement's rules – every row of the package matrix and of the counting table, the license-key transport and the pricing of the month. They need the interfaces of an ILIAS checkout (PHPUnit 10 or 11 ships with ILIAS 10 and 11): `ILIAS_ROOT=/path/to/ilias php /path/to/ilias/vendor/composer/vendor/bin/phpunit -c phpunit.xml`.
- The plugin is tested end to end with Playwright against ILIAS 9, 10 and 11: clean install from the release zip, update in place, permissions, all three modes, learning progress, copy, configuration pages, package gating, the license key, the ledger's counting, the monthly limit and the cron job.
- A version bump needs `composer du` before ILIAS offers **Update** in the plugin list.
- **Releases** are published by the [Release workflow](.github/workflows/release.yml): bump `$version` in `plugin.php`, add the version's section to `CHANGELOG.md` and push to `main` – the workflow tags the commit, builds `Tarsus-ilias-plugin-<version>.zip` with `git archive` (repository-only files such as `docs/` are left out) and creates the GitHub release with the changelog section as notes.

Issues and pull requests are welcome.

## Acknowledgements

Our thanks go to **Fred Neumann**, **Martin Studer** and **Lars Egger** for all the years of support in the ILIAS world – this plugin stands on what they built and shared.

## Licence

GPL-3.0-or-later – see [LICENSE](LICENSE). © 2026 Tarsus GmbH, [tarsus.ai](https://tarsus.ai).

<p align="center"><sub>Our goal is to make high-quality education as accessible as Netflix.</sub></p>
