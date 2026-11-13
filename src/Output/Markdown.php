<?php

namespace TarsusIlias\Output;

/**
 * Renders the brain's Markdown as safe HTML.
 *
 * Raw HTML in the input is escaped and unsafe links are dropped, so the
 * result can be inserted as-is. Formulas - the brain writes TeX between
 * single or double dollars - are lifted out before the Markdown pass (which
 * would otherwise eat their underscores and backslashes) and come back
 * wrapped for MathJax: [tex]...[/tex] inside an element MathJax processes.
 *
 * Uses league/commonmark (shipped with ILIAS 9, 10 and 11) and falls back
 * to a small built-in renderer if it is ever missing.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Markdown
{
    private const TOKEN = 'XTARMATHTOKEN';

    private static $converter = null;

    public static function render(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        $math = [];
        $text = self::protectMath($markdown, $math);
        $html = self::convert($text);
        $html = self::restoreMath($html, $math);
        $html = (string) preg_replace('/<a href="/', '<a target="_blank" rel="noopener noreferrer" href="', $html);
        $html = (string) preg_replace('/<table>/', '<div class="tarsus-table-wrap"><table>', $html);
        $html = (string) preg_replace('/<\/table>/', '</table></div>', $html);
        return MathSupport::prepare(trim($html));
    }

    /**
     * Plain text as safe HTML (line breaks kept), for text that is not Markdown.
     */
    public static function plain(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    /**
     * Replace formulas by tokens the Markdown pass leaves alone.
     *
     * @param array<int, array{tex: string, display: bool}> $math
     */
    private static function protectMath(string $text, array &$math): string
    {
        $patterns = [
            ['/\[tex\](.+?)\[\/tex\]/s', false],
            ['/\$\$(.+?)\$\$/s', true],
            ['/\\\\\[(.+?)\\\\\]/s', true],
            ['/\\\\\((.+?)\\\\\)/s', false],
            // Single dollars: no space just inside the delimiters and no
            // digit or letter right after the closing one, so "$5 and $10"
            // stays text.
            ['/(?<![\\\\$\w])\$(?=\S)([^$\n]+?)(?<=\S)\$(?![\w$])/u', false],
        ];
        foreach ($patterns as [$pattern, $display]) {
            $text = (string) preg_replace_callback($pattern, static function (array $m) use (&$math, $display): string {
                $math[] = ['tex' => trim($m[1]), 'display' => $display];
                return self::TOKEN . (count($math) - 1) . 'X';
            }, $text);
        }
        return $text;
    }

    /**
     * @param array<int, array{tex: string, display: bool}> $math
     */
    private static function restoreMath(string $html, array $math): string
    {
        if ($math === []) {
            return $html;
        }
        return (string) preg_replace_callback('/' . self::TOKEN . '(\d+)X/', static function (array $m) use ($math): string {
            $entry = $math[(int) $m[1]] ?? null;
            if ($entry === null) {
                return '';
            }
            $class = 'tarsus-math c-legacy__content--latex' . ($entry['display'] ? ' tarsus-math--display' : '');
            return '<span class="' . $class . '">[tex]' . htmlspecialchars($entry['tex'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '[/tex]</span>';
        }, $html);
    }

    private static function convert(string $markdown): string
    {
        if (self::$converter === null) {
            self::$converter = false;
            if (class_exists('League\\CommonMark\\GithubFlavoredMarkdownConverter')) {
                try {
                    self::$converter = new \League\CommonMark\GithubFlavoredMarkdownConverter([
                        'html_input' => 'escape',
                        'allow_unsafe_links' => false,
                        'max_nesting_level' => 25,
                    ]);
                } catch (\Throwable $e) {
                    self::$converter = false;
                }
            }
        }
        if (self::$converter !== false) {
            try {
                $result = self::$converter->convert($markdown);
                return method_exists($result, 'getContent') ? (string) $result->getContent() : (string) $result;
            } catch (\Throwable $e) {
                // Fall through to the built-in renderer.
            }
        }
        return self::fallback($markdown);
    }

    /**
     * A small, safe Markdown subset: headings, emphasis, code, links,
     * lists, quotes, rules and paragraphs. Everything is escaped first.
     */
    public static function fallback(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $html = [];
        $paragraph = [];
        $list = null;
        $incode = false;
        $code = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $closeList = static function () use (&$list, &$html): void {
            if ($list !== null) {
                $html[] = '</' . $list . '>';
                $list = null;
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line)) {
                if ($incode) {
                    $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                    $code = [];
                    $incode = false;
                } else {
                    $flushParagraph();
                    $closeList();
                    $incode = true;
                }
                continue;
            }
            if ($incode) {
                $code[] = $line;
                continue;
            }
            if (trim($line) === '') {
                $flushParagraph();
                $closeList();
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $flushParagraph();
                $closeList();
                $level = min(6, strlen($m[1]) + 2);
                $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $flushParagraph();
                $closeList();
                $html[] = '<hr>';
                continue;
            }
            if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m) || preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $n)) {
                $flushParagraph();
                $type = isset($m[1]) && $m !== [] ? 'ul' : 'ol';
                $content = $m[1] ?? ($n[1] ?? '');
                if ($list !== $type) {
                    $closeList();
                    $html[] = '<' . $type . '>';
                    $list = $type;
                }
                $html[] = '<li>' . self::inline($content) . '</li>';
                $m = [];
                $n = [];
                continue;
            }
            if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
                $flushParagraph();
                $closeList();
                $html[] = '<blockquote>' . self::inline($m[1]) . '</blockquote>';
                continue;
            }
            $closeList();
            $paragraph[] = $line;
        }
        if ($incode) {
            $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        }
        $flushParagraph();
        $closeList();
        return implode("\n", $html);
    }

    /**
     * Inline Markdown on an escaped line.
     */
    public static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $codes = [];
        $text = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . $m[1] . '</code>';
            return "\x01" . (count($codes) - 1) . "\x01";
        }, $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text);
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', static function (array $m): string {
            return '<a href="' . $m[2] . '">' . $m[1] . '</a>';
        }, $text);
        return (string) preg_replace_callback("/\x01(\d+)\x01/", static fn(array $m): string => $codes[(int) $m[1]] ?? '', $text);
    }
}
