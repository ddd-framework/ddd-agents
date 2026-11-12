<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;

/**
 * One change of a volatile value, delivered ONCE into the history: the model call that first carries
 * it records it on its request snapshot ({@see \DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot::$stateDeltas})
 * with its literal text — every later call of the state epoch re-renders the same bytes at the same position — and the
 * value the model has seen since ({@see $valueAfter}), which the next comparison starts from. Pure.
 *
 * The kind of the note about the space's context text is the application's word ({@see getKindOfAgentSpaceContext()});
 * the other kinds are the module's.
 *
 * @phpstan-consistent-constructor
 */
class AgentStateDelta
{
    public const string KIND_OWNER_ADDRESSING = 'owner-addressing';
    public const string KIND_TIME_ZONE = 'time-zone';
    public const string KIND_STANDING_INSTRUCTIONS = 'standing-instructions';
    public const string KIND_PLAN_OF_THE_PRODUCT = 'plan-of-the-product';
    public const string KIND_SITUATION = 'situation';

    /** One of the KIND_* constants. */
    public string $kind = '';

    /** The literal `[SIGNAL:<kind>] …` text the model reads. */
    public string $text = '';

    /**
     * @var mixed The value the model has seen once this delta is delivered — the field of {@see AgentConversationState}
     *      the kind sets (a string, an array, null).
     */
    public mixed $valueAfter = null;

    /**
     * The kind of the note that reports a changed context text of the space: the word the application declares
     * ({@see AgentApplicationWording::$agentSpaceContextStateKind}).
     */
    public static function getKindOfAgentSpaceContext(): string
    {
        return AgentApplicationWording::current()->agentSpaceContextStateKind;
    }

    /** @return string[] The kinds in the order one call delivers them. */
    public static function getKindsInDeliveryOrder(): array
    {
        return [
            self::getKindOfAgentSpaceContext(),
            self::KIND_OWNER_ADDRESSING,
            self::KIND_TIME_ZONE,
            self::KIND_STANDING_INSTRUCTIONS,
            self::KIND_PLAN_OF_THE_PRODUCT,
            self::KIND_SITUATION,
        ];
    }

    public static function of(string $kind, string $text, mixed $valueAfter): static
    {
        $agentStateDelta = new static();
        $agentStateDelta->kind = $kind;
        $agentStateDelta->text = $text;
        $agentStateDelta->valueAfter = $valueAfter;
        return $agentStateDelta;
    }

    /** @return array{kind: string, text: string, valueAfter: mixed} the snapshot form */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'text' => $this->text, 'valueAfter' => $this->valueAfter];
    }

    /** @param array<string, mixed> $deltaArray the snapshot form; null when it carries no kind or no text */
    public static function fromArray(array $deltaArray): ?self
    {
        $kind = $deltaArray['kind'] ?? null;
        $text = $deltaArray['text'] ?? null;
        if (!is_string($kind) || $kind === '' || !is_string($text) || $text === '') {
            return null;
        }
        return self::of($kind, $text, $deltaArray['valueAfter'] ?? null);
    }
}
