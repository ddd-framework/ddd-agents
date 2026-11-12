<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Traits;

use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The OpenRouter `provider.sort` routing in {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait}: the
 * strategy is the per-class default declared on the `#[ArgusLanguageModel]` attribute
 * ({@see ArgusLanguageModel::$providerPrioritizationStrategy}), overridable at runtime via
 * {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait::setProviderPrioritizationStrategy()}. The strategy
 * maps to the sort (SPEED→throughput, LATENCY→latency, PRICE→price, DEFAULT/unset→no sort).
 */
class ArgusAILanguageModelTraitProviderRoutingTest extends UnitTestCase
{
    protected function egress(): ArgusAIAgentConversation
    {
        /** @var ArgusAIAgentConversation $egress */
        $egress = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        return $egress;
    }

    protected function resolve(ArgusAIAgentConversation $egress, ?string $strategy): ?array
    {
        $argusLanguageModel = new ArgusLanguageModel(providerPrioritizationStrategy: $strategy);
        return self::invokeMethod($egress, 'resolveOpenRouterProviderPreference', [$argusLanguageModel]);
    }

    public function testStrategyMapsToProviderSort(): void
    {
        self::assertSame(['sort' => 'throughput'], $this->resolve($this->egress(), ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED));
        self::assertSame(['sort' => 'latency'], $this->resolve($this->egress(), ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_LATENCY));
        self::assertSame(['sort' => 'price'], $this->resolve($this->egress(), ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_PRICE));
    }

    public function testDefaultAndUnsetUseOpenRouterDefaultRouting(): void
    {
        self::assertNull($this->resolve($this->egress(), ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_DEFAULT));
        self::assertNull($this->resolve($this->egress(), null));
    }

    public function testRuntimeOverrideWinsOverAttributeDefault(): void
    {
        $egress = $this->egress();
        $egress->setProviderPrioritizationStrategy(ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_PRICE);
        // The attribute would declare SPEED → the runtime override forces PRICE instead.
        self::assertSame(['sort' => 'price'], $this->resolve($egress, ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED));
        // Clearing the override falls back to the attribute default (SPEED → throughput).
        $egress->setProviderPrioritizationStrategy(null);
        self::assertSame(['sort' => 'throughput'], $this->resolve($egress, ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED));
    }

    public function testAgentConversationDeclaresSpeedByDefault(): void
    {
        /** @var ArgusLanguageModel $attribute */
        $attribute = self::invokeMethod($this->egress(), 'getArgusLanguageModelInstance');
        self::assertSame(ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED, $attribute->providerPrioritizationStrategy, 'the live agent turn defaults to SPEED routing');
        self::assertSame('throughput', $attribute->getOpenRouterProviderSort());
    }
}
