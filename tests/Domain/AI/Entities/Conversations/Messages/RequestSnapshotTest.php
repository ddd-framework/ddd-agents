<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Unit coverage of the SLIM {@see RequestSnapshot} (dynamic-context plan): the VO no longer stores the outgoing wire
 * payload — it is a thin stamp of the three things that CANNOT be reconstructed from the immutable message rows + the
 * compaction tree: the model name, the reasoning effort, and the moment the turn was sent (the asOf anchor for the
 * point-in-time rebuild). Container-less; the VO only holds its own scalar fields.
 */
class RequestSnapshotTest extends UnitTestCase
{
    public function testIsAValueObjectThatStampsTheModelReasoningEffortAndSentAt(): void
    {
        /** @var RequestSnapshot $requestSnapshot */
        $requestSnapshot = self::instantiateWithoutConstructor(RequestSnapshot::class);
        self::assertInstanceOf(ValueObject::class, $requestSnapshot, 'the slim snapshot is a ValueObject');

        $sentAt = new DateTime('2026-06-19 12:34:56');
        $requestSnapshot->modelName = 'gpt-x';
        $requestSnapshot->reasoningEffort = 'high';
        $requestSnapshot->sentAt = $sentAt;

        self::assertSame('gpt-x', $requestSnapshot->modelName);
        self::assertSame('high', $requestSnapshot->reasoningEffort);
        self::assertSame($sentAt, $requestSnapshot->sentAt, 'sentAt is the asOf anchor for the dynamic rebuild');
    }

    public function testCarriesNoWirePayload(): void
    {
        // The dynamic-context plan dropped the stored payload (it was an exact duplicate of the rows, rebuilt on
        // demand). Guard against anyone re-introducing a heavy per-message column.
        self::assertFalse(
            property_exists(RequestSnapshot::class, 'payload'),
            'RequestSnapshot must NOT store a wire payload — it is reconstructed dynamically'
        );
    }

    public function testDefaultsAreNullBeforeAnyTurnIsStamped(): void
    {
        /** @var RequestSnapshot $requestSnapshot */
        $requestSnapshot = self::instantiateWithoutConstructor(RequestSnapshot::class);
        self::assertNull($requestSnapshot->modelName);
        self::assertNull($requestSnapshot->reasoningEffort);
        self::assertNull($requestSnapshot->sentAt);
    }
}
