# Tests

```bash
php tests/run.php              # everything, a few seconds
php tests/run.php -v           # one line per test
php tests/run.php budget plan  # only tests whose "file: name" contains one of the words
```

Needs PHP 8.1 or later with the `curl`, `pdo_sqlite`, `mbstring` and `json` extensions. No ILIAS, no database server, no Composer packages, no network beyond `127.0.0.1`. The folder is left out of the release zip (`export-ignore` in `.gitattributes`).

## How it works

The plugin's own classes (`src/`, `classes/class.ilTarsusConfigGUI.php`) run unmodified against a small stand-in for ILIAS:

| File | What it stands in for |
|---|---|
| `lib/fake_ilias.php` | The service container, users, the repository tree, permissions, the resource storage, mail, logging and the plugin's language files (missing variables come back wrapped in dashes, as in ILIAS). |
| `lib/FakeDb.php` | `ilDBInterface` on an in-memory SQLite database. Values too long for their column and `NULL` in a `NOT NULL` column are refused, as MySQL would. |
| `lib/fake_gui.php` | The form, tab, template and confirmation classes the configuration screen uses. A redirect is an exception the test catches. |
| `lib/World.php` | The state behind all of it, reset before every test: a fresh database with **every step of `sql/dbupdate.php` applied**, mock mode on, one course with one Tarsus object. Helpers add files and web links, put material into an object's scope and write ledger rows. |
| `server/router.php`, `lib/TarsusServer.php` | A stand-in for the Tarsus API, run by PHP's built-in web server on a free local port. It records every request – method, path, form fields, uploaded files – and answers plausibly; a test can make single endpoints fail. The real `HttpClient` talks to it over real HTTP. |
| `lib/Media.php` | Tiny WAV and MPEG-4 files of a chosen length. |
| `lib/framework.php` | `test()`, a handful of assertions and the runner. Warnings and notices are errors. |

## What is covered

| Case file | Subject |
|---|---|
| `interactions.php` | The counting rules: every row of the "AI interactions via the API" table, all 79 requests of the API documentation, voice input, `think_longer`, recordings by length. |
| `plan.php` | The "Functions per package" table cell by cell, included AI interactions, price, who is billed. |
| `client_credential.php` | Brain ID or License-Key on the wire, for every request the client can send; no fallback; field names. |
| `client_counting.php` | The count each client call reports, HTTP and mock; failures count nothing. |
| `learner_enforcement.php` | Server-side package enforcement: what reaches the API in each package and what does not. |
| `learner_metering.php` | Ledger entries for every learner call, live and in mock mode. |
| `indexing.php` | Uploads through the teacher's API and the cron reconciler: counted, limited, never half-done. |
| `budget.php` | Monthly limit, warnings, the brain's own figure, cost estimate, License-Key billing. |
| `cron.php` | The cron run: re-sent material is counted, everything else is free. |
| `media_duration.php` | Reading the length of WAV and MPEG-4 files, including broken ones. |
| `migration.php` | Database step 9 on a 1.1.0 ledger, early or late. |
| `config_gui.php` | The configuration screen: credential fields, validation, usage panel, status. |
| `language.php` | Both language files hold the same variables and placeholders; every variable the code uses exists. |
| `static.php` | PHP syntax of every file, no stray `brain_id`, release zip contents. |

## Limits

The suite does not start ILIAS: object GUI, permissions, learning progress and the browser side are exercised by the end-to-end runs against real ILIAS installations. It does not call the real Tarsus API either – the stand-in answers what the plugin's client expects, not necessarily what Tarsus sends.
