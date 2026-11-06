<?php

namespace TarsusIlias\Support;

/**
 * Thin, version-tolerant access to the ILIAS services the plugin uses.
 *
 * The plugin runs on ILIAS 9, 10 and 11. Everything that differs between
 * those releases is decided here, in one place, by feature detection.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Ilias
{
    public const PLUGIN_ID = 'xtar';

    private static ?\ilPlugin $plugin = null;

    public static function dic(): \ILIAS\DI\Container
    {
        global $DIC;
        return $DIC;
    }

    public static function db(): \ilDBInterface
    {
        return self::dic()->database();
    }

    public static function user(): \ilObjUser
    {
        return self::dic()->user();
    }

    public static function access(): \ilAccessHandler
    {
        return self::dic()->access();
    }

    public static function tree(): \ilTree
    {
        return self::dic()->repositoryTree();
    }

    public static function ctrl(): \ilCtrlInterface
    {
        return self::dic()->ctrl();
    }

    /**
     * The plugin instance (for its language and paths).
     */
    public static function plugin(): \ilPlugin
    {
        if (self::$plugin === null) {
            /** @var \ilComponentFactory $factory */
            $factory = self::dic()['component.factory'];
            self::$plugin = $factory->getPlugin(self::PLUGIN_ID);
        }
        return self::$plugin;
    }

    /**
     * Inject the plugin instance (the plugin class does, so the factory is
     * not asked from inside its own construction).
     */
    public static function setPlugin(\ilPlugin $plugin): void
    {
        self::$plugin = $plugin;
    }

    /**
     * The plugin directory relative to the web root, for URLs of its
     * assets. ILIAS 9 returns it relative already; ILIAS 10 and later return
     * an absolute path below public/ and offer getRelativeDirectory().
     */
    public static function webDirectory(): string
    {
        $plugin = self::plugin();
        if (method_exists($plugin, 'getRelativeDirectory')) {
            $dir = (string) $plugin->getRelativeDirectory();
        } else {
            $dir = (string) $plugin->getDirectory();
        }
        if ($dir !== '' && $dir[0] === '/') {
            // An absolute path: keep what follows the web root.
            foreach (['/public/Customizing/', '/Customizing/'] as $needle) {
                $pos = strpos($dir, $needle);
                if ($pos !== false) {
                    $dir = ltrim(substr($dir, $pos + ($needle === '/public/Customizing/' ? 8 : 1)), '/');
                    break;
                }
            }
        }
        $dir = preg_replace('~^\./~', '', $dir);
        return rtrim((string) $dir, '/');
    }

    /**
     * URL of a file inside the plugin's templates directory, with a cache
     * buster from the plugin version.
     */
    public static function assetUrl(string $relative, bool $versioned = true): string
    {
        $url = self::webDirectory() . '/templates/' . ltrim($relative, '/');
        if ($versioned) {
            $url .= '?v=' . rawurlencode(self::pluginVersion());
        }
        return $url;
    }

    public static function pluginVersion(): string
    {
        static $version = null;
        if ($version === null) {
            $version = '0';
            $file = dirname(__DIR__, 2) . '/plugin.php';
            if (is_file($file)) {
                $text = (string) file_get_contents($file);
                if (preg_match('/\$version\s*=\s*[\'"]([^\'"]+)[\'"]/', $text, $m)) {
                    $version = $m[1];
                }
            }
        }
        return $version;
    }

    /**
     * Localised plugin text with {placeholder} substitution.
     *
     * @param array<string, string|int|float> $params
     */
    public static function txt(string $key, array $params = []): string
    {
        $text = self::plugin()->txt($key);
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /**
     * Whether the plugin language has the key (missing keys come back
     * wrapped in dashes).
     */
    public static function hasTxt(string $key): bool
    {
        $text = self::plugin()->txt($key);
        return !preg_match('/^-.*-$/', $text);
    }

    /**
     * The current user's two-letter interface language.
     */
    public static function userLanguage(): string
    {
        try {
            $lang = (string) self::user()->getLanguage();
        } catch (\Throwable $e) {
            $lang = '';
        }
        if ($lang === '') {
            try {
                $lang = (string) self::dic()->language()->getLangKey();
            } catch (\Throwable $e) {
                $lang = 'de';
            }
        }
        return strtolower(substr($lang, 0, 2));
    }

    public static function log(string $message, string $level = 'warning'): void
    {
        try {
            $logger = self::dic()->logger()->root();
            if ($level === 'error') {
                $logger->error('Tarsus: ' . $message);
            } elseif ($level === 'info') {
                $logger->info('Tarsus: ' . $message);
            } else {
                $logger->warning('Tarsus: ' . $message);
            }
        } catch (\Throwable $e) {
            error_log('Tarsus: ' . $message);
        }
    }

    /**
     * Whether a repository object (by ref id) is alive: in the tree and not
     * in the trash.
     */
    public static function refAlive(int $refId): bool
    {
        if ($refId <= 0) {
            return false;
        }
        try {
            if (!\ilObject::_exists($refId, true)) {
                return false;
            }
            if (\ilObject::_isInTrash($refId)) {
                return false;
            }
            return self::tree()->isInTree($refId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The repository container that scopes a Tarsus object's material: the
     * nearest course, else the nearest group, else the direct parent.
     */
    public static function scopeContainer(int $refId): int
    {
        $tree = self::tree();
        foreach (['crs', 'grp'] as $type) {
            $found = (int) $tree->checkForParentType($refId, $type);
            if ($found > 0) {
                return $found;
            }
        }
        return (int) $tree->getParentId($refId);
    }

    /**
     * Send a JSON response and stop.
     *
     * @param array<string, mixed> $data
     */
    public static function sendJson(array $data, int $status = 200): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $json = '{"ok":false,"error":"encoding"}';
        }
        try {
            $http = self::dic()->http();
            $response = $http->response()
                ->withStatus($status)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store')
                ->withBody(\ILIAS\Filesystem\Stream\Streams::ofString($json));
            $http->saveResponse($response);
            $http->sendResponse();
            $http->close();
        } catch (\Throwable $e) {
            // Fall through to the plain output below.
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo $json;
        exit;
    }

    /**
     * Send a generated file as a download and stop.
     */
    public static function sendDownload(string $filename, string $content, string $mimetype): void
    {
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        try {
            $http = self::dic()->http();
            $response = $http->response()
                ->withHeader('Content-Type', $mimetype)
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Cache-Control', 'no-store')
                ->withBody(\ILIAS\Filesystem\Stream\Streams::ofString($content));
            $http->saveResponse($response);
            $http->sendResponse();
            $http->close();
        } catch (\Throwable $e) {
            // Fall through to the plain output below.
        }
        if (!headers_sent()) {
            header('Content-Type: ' . $mimetype);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');
        }
        echo $content;
        exit;
    }

    /**
     * The POST body of the current request as an array.
     *
     * @return array<string, mixed>
     */
    public static function postBody(): array
    {
        try {
            $body = self::dic()->http()->request()->getParsedBody();
            if (is_array($body)) {
                return $body;
            }
        } catch (\Throwable $e) {
        }
        return $_POST;
    }

    /**
     * The JSON payload the plugin's scripts send as the "payload" field.
     *
     * @return array<string, mixed>
     */
    public static function jsonPayload(): array
    {
        $body = self::postBody();
        $raw = $body['payload'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A date in the user's presentation format.
     */
    public static function date(int $timestamp): string
    {
        try {
            return \ilDatePresentation::formatDate(new \ilDateTime($timestamp, IL_CAL_UNIX));
        } catch (\Throwable $e) {
            return date('Y-m-d H:i', $timestamp);
        }
    }

    /**
     * A global ILIAS setting (the "common" module), '' when unset.
     */
    public static function setting(string $key): string
    {
        try {
            return (string) self::dic()->settings()->get($key, '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The installation's title for mails and banners.
     */
    public static function siteTitle(): string
    {
        $title = '';
        try {
            $title = trim((string) self::dic()->clientIni()->readVariable('client', 'name'));
        } catch (\Throwable $e) {
            $title = '';
        }
        if ($title === '' && defined('CLIENT_ID')) {
            $title = (string) CLIENT_ID;
        }
        return $title !== '' ? $title : 'ILIAS';
    }

    /**
     * Send a plain-text mail from the system sender. Failures are logged,
     * never thrown: a warning mail must not break the request it rides on.
     *
     * @param string[] $recipients
     */
    public static function mail(array $recipients, string $subject, string $body): bool
    {
        try {
            $mail = new \ilMimeMail();
            /** @var \ilMailMimeSenderFactory $factory */
            $factory = self::dic()['mail.mime.sender.factory'];
            $mail->From($factory->system());
            $mail->To(array_values($recipients));
            $mail->Subject($subject);
            $mail->Body($body);
            $mail->Send();
            return true;
        } catch (\Throwable $e) {
            self::log('mail failed: ' . $e->getMessage());
            return false;
        }
    }
}
