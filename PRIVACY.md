# Tarsus for ILIAS Privacy

> **Disclaimer: This documentation does not guarantee completeness or accuracy. Please report any missing or incorrect information via an [issue](https://github.com/tarsus-ai/ilias-ai-trainer/issues).**

This document follows ILIAS's guide [How to write a PRIVACY.md](https://github.com/ILIAS-eLearning/ILIAS/blob/trunk/docs/development/how-to-write-a-privacy.md). It covers the repository object plugin *Tarsus* (object type `xtar`).

## General information

- Tarsus is an AI learning coach. The conversations of learners with the coach are held by the Tarsus *brain*, a service operated by Tarsus GmbH outside ILIAS; ILIAS keeps only the figures it needs for results and learning progress, and a log of the requests sent.
- Learners are identified towards the brain by a **pseudonym**: an HMAC of the ILIAS user id with a secret generated when the plugin is installed. Their display name and interface language are sent along, so the coach can address them.
- In **demo mode** (the default after installation, until an administrator connects a brain) nothing is sent to Tarsus; the demo conversation is stored in ILIAS instead (see below).

## Integrated components

- Tarsus objects employ the following components (please refer to their respective PRIVACY.md files):
  - [Object](https://github.com/ILIAS-eLearning/ILIAS/blob/trunk/components/ILIAS/ILIASObject/PRIVACY.md)
  - [AccessControl](https://github.com/ILIAS-eLearning/ILIAS/blob/trunk/components/ILIAS/AccessControl/PRIVACY.md)
  - Learning Progress: the plugin reports each learner's status (not attempted, in progress, completed) to ILIAS's learning progress.
  - Cron: the scheduled task *Tarsus: synchronise the brain*.

## Data being stored

- **Figures per learner and object** (table `rep_robj_xtar_prg`): user id, experience points earned and in total, learning units completed and in total, progress values and the time of the last change. Purpose: the learner's result, the learning progress status and the teachers' evaluation.
- **Request log** (table `rep_robj_xtar_use`): user id, object, course, kind of request, AI credits and time of every request sent to the brain. Purpose: the requests per kind of request on the administration pages, and the usage figures of demo mode (with a brain connected, the AI credits come from the brain).
- **Demo conversation** (demo mode only, table `rep_robj_xtar_cfg`): the learner's questions and the sample answers, stored under a hash of the pseudonym, at most the last 300 entries per learner and object. Purpose: showing the demo conversation again on the next visit.
- **Sent to Tarsus** (with a brain connected): the pseudonym, display name and interface language; the learner's questions, answers to exercises and spoken questions (as audio, for transcription); the conversation is stored in the brain. Purpose: the coaching itself.

## Data being presented

- Each person sees their own conversation, results and progress in the Tarsus object.
- Persons with the "Edit Settings" permission on the object see, in the *Evaluation* panel, the name, login, result, progress, learning progress status and last activity of every learner of the object, and, in the *History* panel, one learner's conversation (read only) with the growth of their figures over the last 30 days.
- Persons with administration access to the plugin see the AI credits used per month and the requests per kind of request, not per learner.

## Data being deleted

- A learner deletes their own conversation (in the brain) and their figures with *Reset chat history* in the Tarsus object.
- When an ILIAS user account is deleted, the scheduled task erases the account's conversations in the brain, deletes its figures and removes the user id from its rows in the request log (the counts stay, without the person). The local data goes only once the brain has confirmed the erasure, or holds nothing for the account; while the brain cannot be reached, or the kill switch is on, nothing is deleted and the next run tries again.
- Deleting a Tarsus object from trash deletes its settings, the material it may use and the figures of all its learners. The conversations held in the brain stay there; Tarsus GmbH removes them on request.
- Uninstalling the plugin drops all of its tables.

## Data being exported

- Persons with the "Edit Settings" permission on the object can export the evaluation as a CSV file: last name, first name, login, progress, experience points, learning units, result, learning progress status and last activity of every learner.
- Tarsus objects have no XML export; their conversations are not part of any ILIAS export.
