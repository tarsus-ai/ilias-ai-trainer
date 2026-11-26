<?php

namespace TarsusIlias;

/**
 * The plugin's top exception: every exception the plugin raises derives
 * from it, and it derives from ILIAS's ilException (ILIAS's guideline
 * "Using Exceptions").
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
class TarsusException extends \ilException
{
}
