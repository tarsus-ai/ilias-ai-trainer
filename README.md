# Tarsus for ILIAS

An AI learning companion inside a course or group, grounded in that course's
own material. Learners ask questions, test their knowledge and build it up
step by step; teachers decide which material the assistant may use and see
how their learners progress. The conversations run in the Tarsus brain;
ILIAS keeps only the figures it needs for the learning progress.

This is a repository object plugin (`RepositoryObject/Tarsus`, type id
`xtar`) for ILIAS 9, 10 and 11.

## Installation

1. Copy the `Tarsus` folder to

   * ILIAS 10 and 11: `public/Customizing/global/plugins/Services/Repository/RepositoryObject/Tarsus`
   * ILIAS 9: `Customizing/global/plugins/Services/Repository/RepositoryObject/Tarsus`

   (create the intermediate folders if they do not exist).

2. In the ILIAS root directory run

       composer du

   so ILIAS picks up the plugin's classes and control structure.

3. In ILIAS open **Administration > Extending ILIAS > Plugins**, and for
   *Tarsus* choose **Install**, then **Activate**.

   On the first activation the plugin gives the new object type sensible
   default permissions: course and group members may read Tarsus objects,
   tutors and administrators of courses and groups may create and manage
   them. This is written into the role templates and into the local roles
   of existing courses and groups once; afterwards the permissions are yours
   to change like any other.

4. Choose **Configure** for the plugin and enter the connection to Tarsus:
   base URL, API key and the brain (the *Brains* tab lists the brains the key
   can reach; activate one). Switch **Mock mode** off once the connection is
   configured. The *Status* tab shows whether calls get through.

   The plugin starts in mock mode: a built-in deterministic sample answers
   instead of the brain, so the screens can be tried out without an API key.
   Learners see a note while mock mode is on.

5. Make sure ILIAS's cron job runs (`php cli/cron.php run-jobs <user> <client>`
   in ILIAS 10/11, `php cron/cron.php ...` in ILIAS 9). The plugin's job
   *Tarsus: synchronise the brain* is activated automatically and runs every
   15 minutes: it withdraws material that was deleted from a course, re-sends
   material that changed, confirms pending uploads and retries deletions the
   brain did not confirm. Without the cron job the same work happens when a
   teacher opens the *Materials* tab.

## Using it

**Teacher**

* Create a *Tarsus* object in a course (or group). It is offline until you
  switch it online in its settings.
* *Materials*: the course material the assistant may use - files, web links,
  content pages, wikis, ILIAS learning modules and glossaries. *Index* sends
  material to the brain; the *Included* switch decides what this object may
  answer from. *Sync* reads the brain's current state for the page.
* *Settings*: the modes offered (Ask a question, Test knowledge, Build
  knowledge), the didactic settings (conversation style, DQR level, target
  group, taxonomy levels, question types, operators, learning pace, ...) and
  the progress at which the learning progress counts as completed.
* *Learners*: how the members progress (progress, experience points,
  learning units, score), with a CSV export. The ILIAS learning progress of
  the object follows the same figures.

**Learner**

* *Ask a question*: a chat grounded in the included material.
* *Test knowledge*: practice questions of several kinds (single choice,
  true/false, open questions, rule of three, spreadsheet calculation) with
  immediate feedback, experience points and a progress bar.
* *Build knowledge*: learning units as slides with a check question, read
  aloud on request; "Ask about this" sends a slide or question to the chat.
* *Reset chat history* erases the learner's own conversation in the brain and
  the local figures.

## Data

The plugin keeps its data in seven tables (`rep_robj_xtar_*`): the plugin
configuration, the settings of each object, the index map of the material
sent to the brain, the inclusions per object, the latest figures per
learner, a usage ledger (credits per operation) and the guardrail state.
Learners are sent to the brain under a pseudonym (an HMAC of the ILIAS user
id with a secret generated at installation), their display name and
interface language. The conversation itself lives in the brain.

Uninstalling the plugin drops these tables.

## Guardrails

Outbound calls pass a kill switch, a circuit breaker (opens after five
consecutive failures for five minutes), a daily credit budget and a rate
limit (calls per minute). All four are configured and shown in the plugin
configuration.

## Licence

GPL-3.0-or-later. (c) 2026 lern.link GmbH
