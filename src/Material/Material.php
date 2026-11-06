<?php

namespace TarsusIlias\Material;

/**
 * One unit of material - a single bit destined for the brain.
 *
 * Produced from current ILIAS state. The content hash (change detection)
 * derives from a cheap fingerprint of the source - file revision, page
 * modification times, link targets - so detecting a change never needs the
 * content itself; the payload is only built when a bit is actually sent.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Material
{
    public string $resourceid;
    public string $bittype;
    public int $containerref;
    public string $srctype;
    public int $srcref;
    public int $srcobjid;
    /** The bit's own title (the object's, or a link's). */
    public string $title;
    /** The source object's title. */
    public string $objecttitle;
    public string $fingerprint;
    /** @var array<string, mixed> Source details (rid, mimetype, url, page ids, ...). */
    public array $source;

    /**
     * @param array<string, mixed> $source
     */
    public function __construct(
        string $resourceid,
        string $bittype,
        int $containerref,
        string $srctype,
        int $srcref,
        int $srcobjid,
        string $title,
        string $objecttitle,
        string $fingerprint,
        array $source = []
    ) {
        $this->resourceid = $resourceid;
        $this->bittype = $bittype;
        $this->containerref = $containerref;
        $this->srctype = $srctype;
        $this->srcref = $srcref;
        $this->srcobjid = $srcobjid;
        $this->title = $title;
        $this->objecttitle = $objecttitle;
        $this->fingerprint = $fingerprint;
        $this->source = $source;
    }

    /**
     * A stable hash of this material's content: an unchanged hash means no
     * API call.
     */
    public function contentHash(): string
    {
        return hash('sha256', implode("\x1f", [$this->bittype, trim($this->title), $this->fingerprint]));
    }
}
