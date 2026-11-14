<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use RuntimeException;

/**
 * The envelope service of `mgamadeus/ddd-ai` with its in-process envelope ids, recording every envelope it opens and
 * closes. Stores nothing. On demand the opening fails, or the closing throws after the envelope was closed in memory.
 */
class RecordingUsageEnvelopesServiceDouble extends AIUsageEnvelopesService
{
    public const string OPENING_FAILURE_MESSAGE = 'the envelope could not be opened';

    public const string CLOSING_FAILURE_MESSAGE = 'the envelope could not be closed';

    /** @var AIUsageEnvelopeContext[] The envelopes opened, in order */
    public array $openedEnvelopeContexts = [];

    /** @var AIUsageEnvelopeContext[] The envelopes closed, in order */
    public array $closedEnvelopeContexts = [];

    public bool $openingFails = false;

    public bool $closingThrows = false;

    public function closeEnvelope(?AIUsageEnvelopeContext $envelopeContext = null): void
    {
        parent::closeEnvelope($envelopeContext);
        if ($this->closingThrows) {
            throw new RuntimeException(self::CLOSING_FAILURE_MESSAGE);
        }
    }

    protected function persistEnvelopeOpening(AIUsageEnvelopeContext $envelopeContext): int
    {
        if ($this->openingFails) {
            throw new RuntimeException(self::OPENING_FAILURE_MESSAGE);
        }
        $this->openedEnvelopeContexts[] = $envelopeContext;
        return parent::persistEnvelopeOpening($envelopeContext);
    }

    protected function persistEnvelopeClosing(AIUsageEnvelopeContext $envelopeContext): void
    {
        $this->closedEnvelopeContexts[] = $envelopeContext;
    }
}
