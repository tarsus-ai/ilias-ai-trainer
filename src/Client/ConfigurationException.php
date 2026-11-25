<?php

namespace TarsusIlias\Client;

/**
 * The plugin is not configured well enough to reach the brain (no base URL,
 * no API key, or no brain selected). The message key names a language
 * variable of the plugin.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
class ConfigurationException extends \TarsusIlias\TarsusException
{
    public string $lang_key;

    public function __construct(string $lang_key)
    {
        $this->lang_key = $lang_key;
        parent::__construct($lang_key);
    }
}
