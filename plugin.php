<?php

/**
 * Tarsus for ILIAS - repository object plugin.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */

// Plugin id: the object type of Tarsus objects (max. 4 characters, must
// start with "x" for repository object plugins).
$id = 'xtar';

// Plugin version.
$version = '1.0.0';

// ILIAS releases this version runs on.
$ilias_min_version = '9.0';
$ilias_max_version = '11.999';

// Responsible developer.
$responsible = 'lern.link GmbH';
$responsible_mail = '';

// The plugin reports learning progress (ilLPStatusPluginInterface).
$learning_progress = true;

// No XML export/import: the conversation lives in the brain.
$supports_export = false;
