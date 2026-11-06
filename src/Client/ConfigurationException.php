<?php

namespace TarsusIlias\Client;

/**
 * The plugin is not configured well enough to reach the brain (no base URL,
 * no API key, or no brain selected). The message key names a language
 * variable of the plugin.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
class ConfigurationException extends \RuntimeException
{
    public string $langKey;

    public function __construct(string $langKey)
    {
        $this->langKey = $langKey;
        parent::__construct($langKey);
    }
}
