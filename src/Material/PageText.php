<?php

namespace TarsusIlias\Material;

use TarsusIlias\Support\Ilias;

/**
 * Turns ILIAS page editor content (COPage XML) into plain text with a
 * light Markdown structure the brain reads well: headings, list items,
 * table rows, paragraphs.
 *
 * Read straight from the page_object table: the format has been stable for
 * many ILIAS releases, and reading it needs no page GUI or rendering.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class PageText
{
    /** Elements that end a line. */
    private const BLOCKS = [
        'Paragraph', 'ListItem', 'TableRow', 'Section', 'PageContent', 'FileItem', 'Caption',
        'Tab', 'TabCaption', 'Question', 'QuestionText', 'Answer', 'Interaction', 'Blockquote',
        'Code', 'Grid', 'GridCell', 'Resources', 'ContentInclude', 'Accordion', 'List',
    ];

    /** Elements whose content is not text the learner reads. */
    private const SKIP = [
        'MediaAlias', 'MediaAliasItem', 'Layout', 'Parameter', 'MapArea', 'ExtLinkItem',
        'PlaceHolder', 'Plugged', 'Map', 'Location', 'PlaceholderContent',
    ];

    /**
     * Text of one page.
     */
    public static function fromXml(string $xml): string
    {
        $xml = trim($xml);
        if ($xml === '') {
            return '';
        }
        $xml = (string) preg_replace('/^<\?xml[^>]*\?>/', '', $xml);

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = $dom->loadXML('<?xml version="1.0" encoding="UTF-8"?>' . $xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok || $dom->documentElement === null) {
            // Unparseable: fall back to the bare text.
            $text = str_ireplace(['<br/>', '<br />', '<br>', '</Paragraph>', '</ListItem>'], "\n", $xml);
            return self::tidy(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        $out = '';
        self::walk($dom->documentElement, $out);
        return self::tidy($out);
    }

    private static function walk(\DOMNode $node, string &$out): void
    {
        if ($node instanceof \DOMText) {
            $out .= $node->nodeValue;
            return;
        }
        if (!$node instanceof \DOMElement) {
            return;
        }
        $name = $node->nodeName;
        if (in_array($name, self::SKIP, true)) {
            return;
        }
        if ($name === 'br') {
            $out .= "\n";
            return;
        }

        $prefix = '';
        if ($name === 'Paragraph') {
            $characteristic = (string) $node->getAttribute('Characteristic');
            if (preg_match('/^Headline([1-3])$/', $characteristic, $m)) {
                $prefix = str_repeat('#', (int) $m[1] + 1) . ' ';
            }
        } elseif ($name === 'ListItem') {
            $prefix = '- ';
        } elseif ($name === 'TableData') {
            $prefix = ' | ';
        } elseif ($name === 'TabCaption' || $name === 'Caption') {
            $prefix = '### ';
        }

        if (in_array($name, self::BLOCKS, true)) {
            $out .= "\n";
        }
        $out .= $prefix;
        foreach ($node->childNodes as $child) {
            self::walk($child, $out);
        }
        if (in_array($name, self::BLOCKS, true)) {
            $out .= "\n";
        }
    }

    private static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00a0}"], ["\n", "\n", ' '], $text);
        $lines = array_map(static fn(string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)), explode("\n", $text));
        $text = implode("\n", $lines);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    // ------------------------------------------------------------------
    // Page access
    // ------------------------------------------------------------------

    /**
     * Content and modification stamps of pages, keyed by page id. Prefers
     * the default language ('-') where translations exist.
     *
     * @param int[] $pageids
     * @return array<int, array{content: string, changed: string, lang: string}>
     */
    public static function pages(string $parenttype, array $pageids, bool $withcontent): array
    {
        $pageids = array_values(array_unique(array_map('intval', $pageids)));
        if ($pageids === []) {
            return [];
        }
        $db = Ilias::db();
        $fields = 'page_id, lang, last_change' . ($withcontent ? ', content' : '');
        $pages = [];
        foreach (array_chunk($pageids, 500) as $chunk) {
            $res = $db->query(
                'SELECT ' . $fields . ' FROM page_object WHERE parent_type = ' . $db->quote($parenttype, 'text')
                . ' AND ' . $db->in('page_id', $chunk, false, 'integer')
            );
            while ($row = $db->fetchAssoc($res)) {
                $id = (int) $row['page_id'];
                $lang = (string) ($row['lang'] ?? '-');
                if (isset($pages[$id]) && $pages[$id]['lang'] === '-') {
                    continue;
                }
                $pages[$id] = [
                    'content' => $withcontent ? (string) ($row['content'] ?? '') : '',
                    'changed' => (string) ($row['last_change'] ?? ''),
                    'lang' => $lang,
                ];
            }
        }
        return $pages;
    }
}
