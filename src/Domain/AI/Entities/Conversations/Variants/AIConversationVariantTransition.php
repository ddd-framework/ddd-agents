<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Variants;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE variant dimension whose value, source or decision changed against an earlier set of variants, built by
 * {@see AIConversationVariants::transitionsSince()}: the dimension and its trace token before and after. The trace
 * line of a variant resolution carries it as `{"dimension":…,"from":…,"to":…}` ({@see toTraceArray()}).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationVariantTransition extends ValueObject
{
    /** @var string The dimension that changed, e.g. `businessClass` */
    public string $dimension = '';

    /** @var string The earlier trace token (`businessClass=walkIn(proposed,record@8)`), `none` when there was none */
    public string $from = 'none';

    /** @var string The current trace token */
    public string $to = '';

    /**
     * @param string $dimension
     * @param string $from
     * @param string $to
     * @return static
     */
    public static function forDimensionFromTo(string $dimension, string $from, string $to): static
    {
        $aIConversationVariantTransition = new static();
        $aIConversationVariantTransition->dimension = $dimension;
        $aIConversationVariantTransition->from = $from;
        $aIConversationVariantTransition->to = $to;
        return $aIConversationVariantTransition;
    }

    /**
     * The form inside the `variants.resolved` trace line (key order fixed).
     *
     * @return array{dimension: string, from: string, to: string}
     */
    public function toTraceArray(): array
    {
        return ['dimension' => $this->dimension, 'from' => $this->from, 'to' => $this->to];
    }
}
