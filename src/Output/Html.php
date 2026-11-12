<?php

namespace TarsusIlias\Output;

/**
 * HTML helpers.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Html
{
    /**
     * Escape text for HTML. Curly braces become entities as well: the
     * ILIAS template engine strips anything that looks like one of its own
     * {PLACEHOLDERS} from the final page, formulas and JSON included.
     */
    public static function e(?string $text): string
    {
        return str_replace(
            ['{', '}'],
            ['&#123;', '&#125;'],
            htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * A JSON value safe to embed in an HTML attribute or a script block.
     *
     * @param mixed $value
     */
    public static function json($value): string
    {
        return (string) json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    /**
     * An inline SVG icon from the plugin's icon set.
     */
    public static function icon(string $name, string $class = 'tarsus-icon'): string
    {
        $path = Icons::PATHS[$name] ?? '';
        if ($path === '') {
            return '';
        }
        return '<svg class="' . self::e($class) . '" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">'
            . $path . '</svg>';
    }
}
