<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Everything the reader of one `ComposedView` block dropped or flagged, in reading order.
 *
 * @property ComposedViewValidationFinding[] $elements
 * @method ComposedViewValidationFinding[] getElements()
 * @method ComposedViewValidationFinding|null first()
 * @phpstan-consistent-constructor
 */
class ComposedViewValidationFindings extends ObjectSet
{
    /** Adds one finding. */
    public function addFinding(string $code, string $path, string $message): void
    {
        $composedViewValidationFinding = ComposedViewValidationFinding::create($code, $path, $message);
        $this->add($composedViewValidationFinding);
    }

    /**
     * The codes, reading order, repeats kept.
     *
     * @return string[]
     */
    public function getCodes(): array
    {
        return array_map(static fn (ComposedViewValidationFinding $finding): string => $finding->code, $this->getElements());
    }

    /**
     * The codes whose finding DROPS a node or a bound value from what renders: a node of an unknown
     * type, without a required prop or in a slot that does not accept it (`INVALID_NODE`), and a binding to nothing
     * (`UNKNOWN_BINDING`). Findings that keep the node (`INLINE_VALUE_WITHOUT_SOURCE`, …) are not among them.
     *
     * @var string[]
     */
    public const array CODES_THAT_DROP_A_NODE_OR_A_BINDING = [
        ComposedViewValidationFinding::CODE_INVALID_NODE,
        ComposedViewValidationFinding::CODE_UNKNOWN_BINDING,
    ];

    /** The findings of {@see CODES_THAT_DROP_A_NODE_OR_A_BINDING}, reading order. */
    public function buildFindingsThatDropANodeOrABinding(): static
    {
        $droppingFindings = new static();
        foreach ($this->getElements() as $composedViewValidationFinding) {
            if (in_array($composedViewValidationFinding->code, self::CODES_THAT_DROP_A_NODE_OR_A_BINDING, true)) {
                $droppingFindings->add($composedViewValidationFinding);
            }
        }
        return $droppingFindings;
    }

    /**
     * Removes the findings of `$code` at a path inside the subtree at `$subtreePath` (`view.children[1]` and below) — the
     * notes of a layout node that a lift emptied and that was pruned with it.
     */
    public function removeFindingsOfCodeInSubtree(string $code, string $subtreePath): void
    {
        foreach ($this->getElements() as $composedViewValidationFinding) {
            $isInSubtree = $composedViewValidationFinding->path === $subtreePath || str_starts_with($composedViewValidationFinding->path, $subtreePath . '.');
            if ($composedViewValidationFinding->code === $code && $isInSubtree) {
                $this->remove($composedViewValidationFinding);
            }
        }
    }

    /** Whether a finding of `$code` exists. */
    public function hasCode(string $code): bool
    {
        return in_array($code, $this->getCodes(), true);
    }
}
