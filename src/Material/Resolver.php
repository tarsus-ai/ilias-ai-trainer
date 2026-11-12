<?php

namespace TarsusIlias\Material;

use TarsusIlias\Client\HttpClient;
use TarsusIlias\Support\Ilias;

/**
 * Finds the material of a course (or group) and turns each object into
 * bits for the brain.
 *
 * Supported: files (documents, audio, video), web links, content pages,
 * wikis, ILIAS learning modules and glossaries. Text content is read from
 * the page editor tables; files are read from the resource storage at send
 * time.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Resolver
{
    /** @var string[] Repository object types that carry material. */
    public const TYPES = ['file', 'webr', 'copa', 'wiki', 'lm', 'glo'];

    /** Characters of text sent per bit at most. */
    private const MAX_TEXT = 1500000;

    public function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * The material objects in a container's scope, in tree order. A
     * container that is neither course nor group (a category, say) does not
     * reach into the courses and groups below it: those scope themselves.
     *
     * @return array<int, array{ref_id: int, obj_id: int, type: string, title: string, description: string}>
     */
    public function nodes(int $containerref): array
    {
        $tree = Ilias::tree();
        if (!Ilias::refAlive($containerref)) {
            return [];
        }
        $root = $tree->getNodeData($containerref);
        if (!$root) {
            return [];
        }
        $isscope = in_array((string) ($root['type'] ?? ''), ['crs', 'grp'], true);
        $nodes = [];
        foreach ($tree->getSubTree($root, true, self::TYPES) as $node) {
            $refid = (int) ($node['child'] ?? ($node['ref_id'] ?? 0));
            if ($refid <= 0 || $refid === $containerref) {
                continue;
            }
            if (!$isscope && !$this->directlyBelow($refid, $containerref)) {
                continue;
            }
            $nodes[] = [
                'ref_id' => $refid,
                'obj_id' => (int) ($node['obj_id'] ?? 0),
                'type' => (string) ($node['type'] ?? ''),
                'title' => (string) ($node['title'] ?? ''),
                'description' => (string) ($node['description'] ?? ''),
            ];
        }
        return $nodes;
    }

    /**
     * Whether a material belongs to a container's scope.
     */
    public function inScope(int $refid, int $containerref): bool
    {
        if (!Ilias::refAlive($refid) || !Ilias::refAlive($containerref)) {
            return false;
        }
        $tree = Ilias::tree();
        if (!$tree->isGrandChild($containerref, $refid)) {
            return false;
        }
        $type = \ilObject::_lookupType($containerref, true);
        if (in_array($type, ['crs', 'grp'], true)) {
            return true;
        }
        return $this->directlyBelow($refid, $containerref);
    }

    /**
     * For a container that is no course or group: the material must not
     * sit inside a course or group below it.
     */
    private function directlyBelow(int $refid, int $containerref): bool
    {
        $tree = Ilias::tree();
        foreach (['crs', 'grp'] as $type) {
            $found = (int) $tree->checkForParentType($refid, $type);
            if ($found > 0 && $found !== $containerref && $tree->isGrandChild($containerref, $found)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Every bit in a container, in tree order.
     *
     * @return Material[]
     */
    public function forContainer(int $containerref): array
    {
        $materials = [];
        foreach ($this->nodes($containerref) as $node) {
            foreach ($this->forNode($node, $containerref) as $material) {
                $materials[] = $material;
            }
        }
        return $materials;
    }

    /**
     * The bits of one material object.
     *
     * @param array{ref_id: int, obj_id: int, type: string, title: string, description?: string} $node
     * @return Material[]
     */
    public function forNode(array $node, int $containerref): array
    {
        try {
            switch ($node['type']) {
                case 'file':
                    return $this->fileBits($node, $containerref);
                case 'webr':
                    return $this->linkBits($node, $containerref);
                case 'copa':
                    return $this->pageBits($node, $containerref, 'copa', [(int) $node['obj_id'] => (string) $node['title']]);
                case 'wiki':
                    return $this->pageBits($node, $containerref, 'wpg', $this->wikiPages((int) $node['obj_id']));
                case 'lm':
                    return $this->pageBits($node, $containerref, 'lm', $this->lmPages((int) $node['obj_id']));
                case 'glo':
                    return $this->glossaryBits($node, $containerref);
            }
        } catch (\Throwable $e) {
            Ilias::log('could not read material ' . $node['type'] . ' ' . $node['ref_id'] . ': ' . $e->getMessage());
        }
        return [];
    }

    /**
     * Re-resolve one bit from its resource id (current state).
     */
    public function resolveByResourceId(string $resourceid): ?Material
    {
        $parts = ResourceId::parse($resourceid);
        if ($parts === null || !ResourceId::isLocal($resourceid) || !$this->supports($parts['type'])) {
            return null;
        }
        $refid = $parts['refid'];
        if (!$this->inScope($refid, $parts['containerref'])) {
            return null;
        }
        if (\ilObject::_lookupType($refid, true) !== $parts['type']) {
            return null;
        }
        $objid = \ilObject::_lookupObjId($refid);
        $node = [
            'ref_id' => $refid,
            'obj_id' => $objid,
            'type' => $parts['type'],
            'title' => \ilObject::_lookupTitle($objid),
            'description' => \ilObject::_lookupDescription($objid),
        ];
        foreach ($this->forNode($node, $parts['containerref']) as $material) {
            if ($material->resourceid === $resourceid) {
                return $material;
            }
        }
        return null;
    }

    /**
     * The payload the brain's track endpoint receives for a bit; text is
     * extracted here, at send time.
     *
     * @return array<string, mixed>
     */
    public function payload(Material $material): array
    {
        $payload = [
            'resource_id' => $material->resourceid,
            'context_id' => ResourceId::assetId($material->containerref),
            'type' => $material->bittype,
            'title' => $material->title,
        ];

        switch ($material->bittype) {
            case BitType::URL:
                $payload['url'] = (string) ($material->source['url'] ?? '');
                break;
            case BitType::DOCUMENT:
            case BitType::MEDIA:
                $payload['rid'] = (string) ($material->source['rid'] ?? '');
                $payload['filename'] = (string) ($material->source['filename'] ?? $material->title);
                $payload['mimetype'] = (string) ($material->source['mimetype'] ?? 'application/octet-stream');
                break;
            default:
                $payload['text'] = $this->text($material);
                break;
        }

        return $payload;
    }

    // ------------------------------------------------------------------
    // Files
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $node
     * @return Material[]
     */
    private function fileBits(array $node, int $containerref): array
    {
        $db = Ilias::db();
        $res = $db->queryF('SELECT file_name, rid FROM file_data WHERE file_id = %s', ['integer'], [(int) $node['obj_id']]);
        $row = $db->fetchAssoc($res);
        $rid = trim((string) ($row['rid'] ?? ''));
        if ($rid === '' || $rid === '-') {
            return [];
        }

        $filename = (string) ($row['file_name'] ?? $node['title']);
        $mimetype = 'application/octet-stream';
        $version = 0;
        $size = 0;
        try {
            $storage = Ilias::dic()->resourceStorage();
            $identification = $storage->manage()->find($rid);
            if ($identification === null) {
                return [];
            }
            $revision = $storage->manage()->getCurrentRevision($identification);
            $information = $revision->getInformation();
            $version = (int) $revision->getVersionNumber();
            $size = (int) $information->getSize();
            $mimetype = (string) $information->getMimeType();
            $title = (string) $information->getTitle();
            if ($title !== '') {
                $filename = $title;
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [new Material(
            ResourceId::build($containerref, 'file', (int) $node['ref_id']),
            BitType::forMime($mimetype),
            $containerref,
            'file',
            (int) $node['ref_id'],
            (int) $node['obj_id'],
            (string) $node['title'],
            (string) $node['title'],
            implode('|', [$rid, $version, $size]),
            ['rid' => $rid, 'filename' => $filename, 'mimetype' => $mimetype, 'size' => $size]
        )];
    }

    // ------------------------------------------------------------------
    // Web links
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $node
     * @return Material[]
     */
    private function linkBits(array $node, int $containerref): array
    {
        $db = Ilias::db();
        $res = $db->queryF(
            'SELECT link_id, title, target, active, internal FROM webr_items WHERE webr_id = %s ORDER BY link_id',
            ['integer'],
            [(int) $node['obj_id']]
        );
        $links = [];
        while ($row = $db->fetchAssoc($res)) {
            $target = trim((string) ($row['target'] ?? ''));
            if (empty($row['active']) || !empty($row['internal']) || !preg_match('~^https?://~i', $target)) {
                continue;
            }
            $links[] = ['id' => (int) $row['link_id'], 'title' => trim((string) ($row['title'] ?? '')), 'target' => $target];
        }

        $single = count($links) === 1;
        $bits = [];
        foreach ($links as $link) {
            $title = $single || $link['title'] === '' ? (string) $node['title'] : $link['title'];
            $bits[] = new Material(
                ResourceId::build($containerref, 'webr', (int) $node['ref_id'], 'link' . $link['id']),
                BitType::URL,
                $containerref,
                'webr',
                (int) $node['ref_id'],
                (int) $node['obj_id'],
                $title,
                (string) $node['title'],
                $link['target'],
                ['url' => $link['target'], 'link_id' => $link['id']]
            );
        }
        return $bits;
    }

    // ------------------------------------------------------------------
    // Page-based objects
    // ------------------------------------------------------------------

    /**
     * One text bit for a page-based object (content page, wiki, learning
     * module): the pages are read in order at send time.
     *
     * @param array<string, mixed> $node
     * @param array<int, string|array{title: string, chapter?: string}> $pages Page id => title (in order).
     * @return Material[]
     */
    private function pageBits(array $node, int $containerref, string $parenttype, array $pages): array
    {
        if ($pages === []) {
            return [];
        }
        $stamps = PageText::pages($parenttype, array_keys($pages), false);
        if ($stamps === []) {
            return [];
        }
        $fingerprint = [(string) ($node['description'] ?? '')];
        foreach ($pages as $id => $page) {
            $title = is_array($page) ? (string) ($page['title'] ?? '') . '/' . (string) ($page['chapter'] ?? '') : (string) $page;
            $fingerprint[] = $id . ':' . ($stamps[$id]['changed'] ?? '') . ':' . ($stamps[$id]['lang'] ?? '') . ':' . $title;
        }

        return [new Material(
            ResourceId::build($containerref, (string) $node['type'], (int) $node['ref_id']),
            BitType::TEXT,
            $containerref,
            (string) $node['type'],
            (int) $node['ref_id'],
            (int) $node['obj_id'],
            (string) $node['title'],
            (string) $node['title'],
            hash('sha256', implode("\n", $fingerprint)),
            ['parenttype' => $parenttype, 'pages' => $pages, 'description' => (string) ($node['description'] ?? '')]
        )];
    }

    /**
     * @return array<int, string> Page id => title, alphabetical as in the wiki's index.
     */
    private function wikiPages(int $wikiid): array
    {
        $db = Ilias::db();
        $res = $db->queryF('SELECT id, title FROM il_wiki_page WHERE wiki_id = %s ORDER BY title, id', ['integer'], [$wikiid]);
        $pages = [];
        while ($row = $db->fetchAssoc($res)) {
            $pages[(int) $row['id']] = (string) $row['title'];
        }
        return $pages;
    }

    /**
     * @return array<int, array{title: string, chapter: string}> Page id => title and chapter, in reading order.
     */
    private function lmPages(int $lmid): array
    {
        $db = Ilias::db();
        $res = $db->queryF(
            'SELECT d.obj_id, d.title, d.type FROM lm_tree t JOIN lm_data d ON d.obj_id = t.child'
            . ' WHERE t.lm_id = %s AND d.lm_id = %s ORDER BY t.lft',
            ['integer', 'integer'],
            [$lmid, $lmid]
        );
        $pages = [];
        $chapter = '';
        while ($row = $db->fetchAssoc($res)) {
            if ($row['type'] === 'st') {
                $chapter = (string) $row['title'];
            } elseif ($row['type'] === 'pg') {
                $pages[(int) $row['obj_id']] = ['title' => (string) $row['title'], 'chapter' => $chapter];
            }
        }
        return $pages;
    }

    // ------------------------------------------------------------------
    // Glossaries
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $node
     * @return Material[]
     */
    private function glossaryBits(array $node, int $containerref): array
    {
        $terms = $this->glossaryTerms((int) $node['obj_id']);
        if ($terms === []) {
            return [];
        }
        $fingerprint = [(string) ($node['description'] ?? '')];
        foreach ($terms as $id => $term) {
            $fingerprint[] = $id . ':' . $term['term'] . ':' . $term['changed'];
        }
        return [new Material(
            ResourceId::build($containerref, 'glo', (int) $node['ref_id']),
            BitType::TEXT,
            $containerref,
            'glo',
            (int) $node['ref_id'],
            (int) $node['obj_id'],
            (string) $node['title'],
            (string) $node['title'],
            hash('sha256', implode("\n", $fingerprint)),
            ['glossary' => (int) $node['obj_id'], 'description' => (string) ($node['description'] ?? '')]
        )];
    }

    /**
     * @return array<int, array{term: string, changed: string, short: string}>
     */
    private function glossaryTerms(int $gloid): array
    {
        $db = Ilias::db();
        $res = $db->queryF('SELECT * FROM glossary_term WHERE glo_id = %s ORDER BY term, id', ['integer'], [$gloid]);
        $terms = [];
        while ($row = $db->fetchAssoc($res)) {
            $terms[(int) $row['id']] = [
                'term' => (string) ($row['term'] ?? ''),
                'changed' => (string) ($row['last_update'] ?? ''),
                'short' => (string) ($row['short_text'] ?? ''),
            ];
        }
        return $terms;
    }

    // ------------------------------------------------------------------
    // Text extraction (send time)
    // ------------------------------------------------------------------

    private function text(Material $material): string
    {
        $parts = ['# ' . $material->objecttitle];
        $description = trim((string) ($material->source['description'] ?? ''));
        if ($description !== '') {
            $parts[] = $description;
        }

        if ($material->srctype === 'glo') {
            $parts[] = $this->glossaryText((int) ($material->source['glossary'] ?? 0));
        } else {
            $parenttype = (string) ($material->source['parenttype'] ?? '');
            $pages = (array) ($material->source['pages'] ?? []);
            $contents = PageText::pages($parenttype, array_keys($pages), true);
            $chapter = '';
            foreach ($pages as $id => $page) {
                $text = PageText::fromXml($contents[$id]['content'] ?? '');
                if (is_array($page)) {
                    if (($page['chapter'] ?? '') !== '' && $page['chapter'] !== $chapter) {
                        $chapter = (string) $page['chapter'];
                        $parts[] = '## ' . $chapter;
                    }
                    $parts[] = '### ' . (string) ($page['title'] ?? '');
                } elseif ($material->srctype !== 'copa') {
                    $parts[] = '## ' . (string) $page;
                }
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        $text = trim(implode("\n\n", array_filter($parts, static fn($part) => trim((string) $part) !== '')));
        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT);
        }
        return $text;
    }

    private function glossaryText(int $gloid): string
    {
        $terms = $this->glossaryTerms($gloid);
        if ($terms === []) {
            return '';
        }
        // ILIAS 9 and later keep one definition page per term ('term');
        // older content kept separate definitions ('gdf').
        $pages = PageText::pages('term', array_keys($terms), true);
        $legacy = [];
        if (count($pages) < count($terms)) {
            $legacy = $this->legacyDefinitions(array_diff(array_keys($terms), array_keys($pages)));
        }
        $lines = [];
        foreach ($terms as $id => $term) {
            $definition = isset($pages[$id]) ? PageText::fromXml($pages[$id]['content']) : ($legacy[$id] ?? '');
            if ($definition === '') {
                $definition = $term['short'];
            }
            $lines[] = '**' . $term['term'] . '**: ' . str_replace("\n", ' ', $definition);
        }
        return implode("\n\n", $lines);
    }

    /**
     * @param int[] $termids
     * @return array<int, string>
     */
    private function legacyDefinitions(array $termids): array
    {
        if ($termids === []) {
            return [];
        }
        $db = Ilias::db();
        $definitions = [];
        try {
            $res = $db->query(
                'SELECT id, term_id FROM glossary_definition WHERE ' . $db->in('term_id', array_values($termids), false, 'integer') . ' ORDER BY nr'
            );
            $byterm = [];
            while ($row = $db->fetchAssoc($res)) {
                $byterm[(int) $row['id']] = (int) $row['term_id'];
            }
            $pages = PageText::pages('gdf', array_keys($byterm), true);
            foreach ($byterm as $defid => $termid) {
                $text = PageText::fromXml($pages[$defid]['content'] ?? '');
                if ($text !== '') {
                    $definitions[$termid] = trim(($definitions[$termid] ?? '') . ' ' . $text);
                }
            }
        } catch (\Throwable $e) {
            // No legacy definitions table: nothing to add.
        }
        return $definitions;
    }

    /**
     * Copy a file bit into a temporary file (diagnostics).
     */
    public static function fileToTemp(Material $material): ?string
    {
        return HttpClient::copyToTemp((string) ($material->source['rid'] ?? ''));
    }
}
