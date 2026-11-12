<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\CustomerSelection;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One AI model a CUSTOMER may pick in the agent chat's model picker — the customer-facing projection of an
 * {@see \DDD\Domain\AI\Entities\Models\Selection\AgentEligibleModel}. Carries ONLY customer vocabulary:
 * strength / speed as 1–5 levels and the credit-consumption multiplier — never the internal tier names
 * (CHEAP/STANDARD/PREMIUM) or raw $-per-token figures. Curated via the `agentCustomerSelection` block of a model in
 * the `AI.models` config; assembled by {@see AgentCustomerSelectableModels::fromAgentEligibleModels()}. An application
 * may put a same-path subclass under its own root namespace; the set creates its elements through `newInstance()`.
 *
 * @method AgentCustomerSelectableModels getParent()
 */
class AgentCustomerSelectableModel extends ValueObject
{
    /** @var string The AIModel name, e.g. 'ALIBABA.QWEN3_235B_INSTRUCT' — what startWithMessage/postMessage accept as aiModelName. */
    public string $name;

    /** @var string Customer-visible model name (a proper noun, never translated), e.g. 'Qwen 3'. */
    public string $displayName;

    /** @var string One-line customer-visible description. ENGLISH on the wire — the frontend translates it (the English string is the translation key). */
    public string $description;

    /** @var int Strength as a 1–5 level, banded from the catalog's agentic intelligence score (absolute bands, so adding a model later never re-scales the others). */
    public int $strengthLevel;

    /** @var int Speed as a 1–5 level, banded from the catalog's measured output tokens/sec (absolute bands). */
    public int $speedLevel;

    /** @var float Credit consumption relative to the CHEAPEST selectable model (= 1). A turn on this model burns the account's monthly AI budget ~this many times faster. */
    public float $creditsMultiplier;
}
