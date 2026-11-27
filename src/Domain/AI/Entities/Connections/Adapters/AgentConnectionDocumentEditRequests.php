<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The resolved batchUpdate requests of one native edit (Docs or Slides), as the provider's API takes them, with the
 * revision they are locked on.
 */
class AgentConnectionDocumentEditRequests extends ValueObject
{
    public string $documentId = '';
    public string $requiredRevisionId = '';
    /** @var string The provider's batchUpdate requests, in its wire format (JSON array); built only by the edit resolver */
    public string $providerRequestsJson = '[]';
}
