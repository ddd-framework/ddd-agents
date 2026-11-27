<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The structured read of a native document: markdown with stable anchors (`[p:12]`, `[tbl:2 r3c1]`,
 * `[nr:Summary]` for Docs; `[s:3 obj:g12ab]` for Slides; sheet and range for Sheets), the revision it was read at, and
 * the anchor map a later edit resolves against (anchor => start/end index of that revision).
 */
class AgentConnectionDocumentRead extends ValueObject
{
    public string $itemId = '';
    public string $kind = AgentConnectionDriveItem::KIND_GOOGLE_DOC;
    public string $title = '';
    public string $revisionId = '';
    public string $markdown = '';
    /** @var AgentConnectionDocumentAnchors The anchors of the read revision */
    public AgentConnectionDocumentAnchors $anchors;
    public bool $isTruncated = false;

    public function __construct()
    {
        $this->anchors = new AgentConnectionDocumentAnchors();
        parent::__construct();
    }
}
