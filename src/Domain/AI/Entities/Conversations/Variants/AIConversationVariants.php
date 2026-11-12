<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Variants;

use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The conversation's situation: one {@see AIConversationVariant} per declared dimension, persisted in the agent
 * working state ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState::$variants}) and rendered
 * into the skills, the request snapshot, the inspector and the judge trace.
 *
 * @property AIConversationVariant[] $elements
 * @method AIConversationVariant[] getElements()
 * @method AIConversationVariant|null first()
 * @phpstan-consistent-constructor
 */
class AIConversationVariants extends ObjectSet
{
    public function get(string $dimension): ?AIConversationVariant
    {
        foreach ($this->getElements() as $variant) {
            if ($variant->dimension === $dimension) {
                return $variant;
            }
        }
        return null;
    }

    public function getValue(string $dimension): string
    {
        $variant = $this->get($dimension);
        return $variant !== null ? $variant->value : AIConversationVariantDimension::VALUE_UNKNOWN;
    }

    public function isDecided(string $dimension): bool
    {
        $variant = $this->get($dimension);
        return $variant !== null && $variant->decided;
    }

    /** Replaces the variant of the same dimension (an ObjectSet keys by uniqueKey = the dimension). */
    public function put(AIConversationVariant $variant): void
    {
        $existing = $this->get($variant->dimension);
        if ($existing !== null) {
            $this->remove($existing);
        }
        $this->add($variant);
    }

    /**
     * Whether a skill section keyed by these selectors applies: every `dimension=value` pair must match the
     * resolved value (an unknown dimension never matches, so a section for a dimension the purpose does not
     * declare stays hidden).
     *
     * @param array<string, string> $selectors dimension => value
     */
    public function matchesSelectors(array $selectors): bool
    {
        foreach ($selectors as $dimension => $value) {
            $variant = $this->get((string)$dimension);
            if ($variant === null) {
                return false;
            }
            $allowedValues = array_map('trim', explode('|', (string)$value));
            if ((string)$dimension === OwnerDevice::SURFACE_DIMENSION && in_array(OwnerDevice::SURFACE_SCREEN, $allowedValues, true)) {
                // `screen` names every surface with a screen
                $allowedValues = array_merge($allowedValues, OwnerDevice::SURFACE_SCREEN_VALUES);
            }
            if (!in_array($variant->value, $allowedValues, true)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, string> dimension => value */
    public function toSelectorMap(): array
    {
        $map = [];
        foreach ($this->getElements() as $variant) {
            $map[$variant->dimension] = $variant->value;
        }
        return $map;
    }

    /** `businessClass=walkIn(owner@14) identityPath=import(record@8) …` — one line for the inspector and the judge. */
    public function toTraceLine(): string
    {
        return implode(' ', array_map(static fn (AIConversationVariant $variant): string => $variant->toTraceToken(), $this->getElements()));
    }

    /** @return array<int, array<string, mixed>> */
    public function toRepositoryArray(): array
    {
        return array_map(static fn (AIConversationVariant $variant): array => $variant->mapToRepository(), array_values($this->getElements()));
    }

    /** @param array<int, mixed> $repositoryVariants */
    public static function fromRepositoryArray(array $repositoryVariants): static
    {
        $variants = new static();
        foreach ($repositoryVariants as $variantFields) {
            if (!is_array($variantFields)) {
                continue;
            }
            $variant = new AIConversationVariant();
            $variant->mapFromRepository($variantFields);
            if ($variant->dimension !== '') {
                $variants->put($variant);
            }
        }
        return $variants;
    }

    /**
     * The situation the model reads under the loaded-skills header — the selectors only, the values the sections were
     * picked by: `identityPath=greenfield · businessClass=walkIn · locality=local`. The provenance flips mid-turn
     * (a widget click confirms a proposed value) and rides the volatile tail instead ({@see toUnconfirmedProvenanceLine()}),
     * so a confirmation does not rewrite the system content.
     */
    public function toSelectorLine(): string
    {
        return implode(' · ', array_map(static fn (AIConversationVariant $variant): string => $variant->toSelector(), $this->getElements()));
    }

    /**
     * The provenance of the dimensions the owner has not confirmed yet, for the wire tail: `businessClass=walkIn is
     * proposed from the record, not yet confirmed by the owner; locality=unknown is not yet known`. Null when every
     * dimension is confirmed (stated by the owner or fixed).
     */
    public function toUnconfirmedProvenanceLine(): ?string
    {
        $unconfirmedTokens = [];
        foreach ($this->getElements() as $variant) {
            if ($variant->isUnknown()) {
                $unconfirmedTokens[] = $variant->toSelector() . ' is not yet known';
            } elseif (!$variant->decided) {
                $unconfirmedTokens[] = $variant->toSelector() . ' is proposed from the record, not yet confirmed by the owner';
            }
        }
        return $unconfirmedTokens !== [] ? implode('; ', $unconfirmedTokens) : null;
    }

    /**
     * Rebuild a set from a request snapshot's compact record (`dimension => "value(provenance)"`) — the point-in-time
     * reconstruction renders the sections that call saw, never today's record. Provenance is parsed back as far as
     * the token carries it.
     *
     * @param array<string, string> $snapshot
     */
    public static function fromSnapshotArray(array $snapshot): static
    {
        $variants = new static();
        foreach ($snapshot as $dimension => $token) {
            $token = (string)$token;
            $value = trim(strstr($token, '(', true) ?: $token);
            if ($value === '') {
                continue;
            }
            $provenance = trim((string)substr($token, strlen($value)), '() ');
            $decided = !str_starts_with($provenance, 'proposed');
            $sourcePart = (string)preg_replace('/^proposed,/', '', $provenance);
            $source = strstr($sourcePart, '@', true) ?: $sourcePart;
            $evidence = str_contains($sourcePart, '@') ? (int)substr($sourcePart, strpos($sourcePart, '@') + 1) : null;
            $source = in_array($source, AIConversationVariant::SOURCES, true) ? $source : AIConversationVariant::SOURCE_PURPOSE;
            $variants->put($decided
                ? AIConversationVariant::decided((string)$dimension, $value, $source, $evidence)
                : AIConversationVariant::proposed((string)$dimension, $value, $source, $evidence));
        }
        return $variants;
    }

    /** The compact per-call record for the request snapshot: `dimension => "value(provenance)"`. */
    public function toSnapshotArray(): array
    {
        $snapshot = [];
        foreach ($this->getElements() as $variant) {
            $snapshot[$variant->dimension] = substr($variant->toTraceToken(), strlen($variant->dimension) + 1);
        }
        return $snapshot;
    }

    /**
     * The dimensions whose value, source or decision changed against an earlier set — the transition log line.
     *
     * @param self|null $earlier
     * @return AIConversationVariantTransition[]
     */
    public function transitionsSince(?self $earlier): array
    {
        $transitions = [];
        foreach ($this->getElements() as $variant) {
            $before = $earlier?->get($variant->dimension);
            if ($variant->sameValueAs($before)) {
                continue;
            }
            $transitions[] = AIConversationVariantTransition::forDimensionFromTo(
                $variant->dimension,
                $before?->toTraceToken() ?? 'none',
                $variant->toTraceToken()
            );
        }
        return $transitions;
    }
}
