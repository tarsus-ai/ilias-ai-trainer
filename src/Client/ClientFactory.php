<?php

namespace TarsusIlias\Client;

use TarsusIlias\Support\Config;

/**
 * Returns the active Tarsus client (mock or real) per site configuration.
 *
 * Defaults to the mock when mock mode is unset (fresh install), so no code
 * path can reach a live endpoint by accident.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class ClientFactory
{
    private static ?ClientInterface $override = null;

    public static function getClient(): ClientInterface
    {
        if (self::$override !== null) {
            return self::$override;
        }
        if (Config::mockMode()) {
            return new MockClient();
        }
        return new HttpClient(Config::baseUrl(), Config::apiKey(), Config::brainId(), Config::licenseKey());
    }

    /**
     * Force a client (tests).
     */
    public static function override(?ClientInterface $client): void
    {
        self::$override = $client;
    }
}
