<?php

namespace TarsusIlias\Output;

/**
 * The brain's Markdown for the browser.
 *
 * Tarsus writes its texts for React-Markdown with KaTeX formulas. The
 * coach shows them exactly that way: the browser renders them with the
 * same pipeline (templates/js/vendor/tarsus-markdown.min.js, built from
 * build/markdown). The server only hands the text over, escaped, in an
 * element the coach script recognises; until it is rendered (or if the
 * script could not load) the text reads as plain text.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Markdown
{
    /**
     * A Markdown text for the coach script to render ('' for an empty one).
     */
    public static function render(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        return '<div class="tarsus-md">' . self::plain($markdown) . '</div>';
    }

    /**
     * Plain text as safe HTML (line breaks kept), for text that is not Markdown.
     */
    public static function plain(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}
