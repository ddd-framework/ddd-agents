<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Variants;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * The resolved value of one variant dimension on a conversation: what the situation IS right now, with its
 * provenance. `decided=false` is a PROPOSAL from a record default (a value an import wrote): its skill section
 * renders already — the draft needs the line — while guards keep treating the dimension as open until an owner
 * source decides it. The model never sets these; they are a projection of the record and the owner's statements.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationVariant extends ValueObject
{
    /** The purpose's default (the dimension is dormant or unknown) */
    public const string SOURCE_PURPOSE = 'purpose';
    /** A record fact (a stored column, a tool result) */
    public const string SOURCE_RECORD = 'record';
    /** The owner's own statement or answer, through a grounded tool call */
    public const string SOURCE_OWNER = 'owner';

    public const array SOURCES = [self::SOURCE_PURPOSE, self::SOURCE_RECORD, self::SOURCE_OWNER];

    /** @var string The dimension name, e.g. `businessClass` */
    public string $dimension = '';

    /** @var string The value, e.g. `walkIn` */
    public string $value = AIConversationVariantDimension::VALUE_UNKNOWN;

    #[Choice(choices: self::SOURCES)]
    public string $source = self::SOURCE_PURPOSE;

    /** @var bool Whether the value is decided (fixed until an owner correction) or a proposal */
    public bool $decided = false;

    /** @var int|null The message index of the evidence (the tool call or owner row the value came from) */
    public ?int $evidenceMessageIndex = null;

    /** @var int|null The message index at which the value was (last) resolved */
    public ?int $resolvedAtMessageIndex = null;

    public static function unknown(string $dimension, string $default = AIConversationVariantDimension::VALUE_UNKNOWN): static
    {
        $variant = new static();
        $variant->dimension = $dimension;
        $variant->value = $default;
        $variant->source = self::SOURCE_PURPOSE;
        $variant->decided = false;
        return $variant;
    }

    public static function proposed(string $dimension, string $value, string $source = self::SOURCE_RECORD, ?int $evidenceMessageIndex = null): static
    {
        $variant = new static();
        $variant->dimension = $dimension;
        $variant->value = $value;
        $variant->source = $source;
        $variant->decided = false;
        $variant->evidenceMessageIndex = $evidenceMessageIndex;
        return $variant;
    }

    public static function decided(string $dimension, string $value, string $source, ?int $evidenceMessageIndex = null): static
    {
        $variant = new static();
        $variant->dimension = $dimension;
        $variant->value = $value;
        $variant->source = $source;
        $variant->decided = true;
        $variant->evidenceMessageIndex = $evidenceMessageIndex;
        return $variant;
    }

    public function isUnknown(): bool
    {
        return $this->value === AIConversationVariantDimension::VALUE_UNKNOWN;
    }

    /** The marker form the skills use: `customerKind=walkIn`. */
    public function toSelector(): string
    {
        return $this->dimension . '=' . $this->value;
    }

    /** One readable token for the inspector and the judge: `customerKind=walkIn(owner@14)` / `(proposed,record@8)`. */
    public function toTraceToken(): string
    {
        $provenance = ($this->decided ? '' : 'proposed,') . $this->source
            . ($this->evidenceMessageIndex !== null ? '@' . $this->evidenceMessageIndex : '');
        return $this->toSelector() . '(' . $provenance . ')';
    }

    public function sameValueAs(?self $other): bool
    {
        return $other !== null && $other->value === $this->value && $other->decided === $this->decided && $other->source === $this->source;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->dimension);
    }

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'dimension' => $this->dimension,
            'value' => $this->value,
            'source' => $this->source,
            'decided' => $this->decided,
            'evidenceMessageIndex' => $this->evidenceMessageIndex,
            'resolvedAtMessageIndex' => $this->resolvedAtMessageIndex,
        ];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $repoArray = is_array($repoObject) ? $repoObject : [];
        $this->dimension = (string)($repoArray['dimension'] ?? '');
        $value = trim((string)($repoArray['value'] ?? ''));
        $this->value = $value !== '' ? $value : AIConversationVariantDimension::VALUE_UNKNOWN;
        $source = (string)($repoArray['source'] ?? self::SOURCE_PURPOSE);
        $this->source = in_array($source, self::SOURCES, true) ? $source : self::SOURCE_PURPOSE;
        $this->decided = (bool)($repoArray['decided'] ?? false);
        $this->evidenceMessageIndex = isset($repoArray['evidenceMessageIndex']) && is_numeric($repoArray['evidenceMessageIndex']) ? (int)$repoArray['evidenceMessageIndex'] : null;
        $this->resolvedAtMessageIndex = isset($repoArray['resolvedAtMessageIndex']) && is_numeric($repoArray['resolvedAtMessageIndex']) ? (int)$repoArray['resolvedAtMessageIndex'] : null;
    }
}
