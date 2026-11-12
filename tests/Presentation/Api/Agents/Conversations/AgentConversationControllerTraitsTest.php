<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationSummary;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationRunStateGetResponseDto;
use DDD\Presentation\Base\Router\Routes\Delete;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Patch;
use DDD\Presentation\Base\Router\Routes\Post;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The agent conversation controller traits as a consuming application mounts them: a fixture controller that only
 * `use`s them gets the complete chat surface (the routes below, relative to its own `#[Route]`), the start declares no
 * route (how a start names the space it works for is the application's own), every hook has a working default, and the run-state read
 * delivers exactly what the web client's polling default needs.
 */
class AgentConversationControllerTraitsTest extends TestCase
{
    protected const array EXPECTED_ROUTES = [
        'listAllConversations' => ['GET', '/listAllConversations'],
        'getAIConversationMessages' => ['GET', '/{aiConversationId}/messages'],
        'getAIConversationActivity' => ['GET', '/{aiConversationId}/activity'],
        'searchAIConversationMessages' => ['GET', '/{aiConversationId}/messages/search'],
        'getAIConversationMessageContext' => ['GET', '/messages/{messageId}/context'],
        'searchAIConversations' => ['GET', '/search'],
        'getAIConversation' => ['GET', '/{aiConversationId}'],
        'getAIConversationRunState' => ['GET', '/{aiConversationId}/runState'],
        'postMessage' => ['POST', '/{aiConversationId}/postMessage'],
        'injectSignal' => ['POST', '/{aiConversationId}/injectSignal'],
        'retryFailedRun' => ['POST', '/{aiConversationId}/retry'],
        'patchTitle' => ['PATCH', '/{aiConversationId}/title'],
        'delete' => ['DELETE', '/{aiConversationId}'],
        'getSelectableAgentModels' => ['GET', '/selectable-models'],
    ];

    public function testAControllerUsingTheTraitsGetsTheChatRoutesAndTheStartStaysUnrouted(): void
    {
        $routes = [];
        foreach ((new ReflectionClass(AgentConversationTraitsFixtureController::class))->getMethods() as $reflectionMethod) {
            foreach ([Get::class => 'GET', Post::class => 'POST', Patch::class => 'PATCH', Delete::class => 'DELETE'] as $attributeClass => $verb) {
                foreach ($reflectionMethod->getAttributes($attributeClass) as $routeAttribute) {
                    $routes[$reflectionMethod->getName()] = [$verb, $routeAttribute->newInstance()->path];
                }
            }
        }
        ksort($routes);
        $expectedRoutes = self::EXPECTED_ROUTES;
        ksort($expectedRoutes);
        self::assertSame($expectedRoutes, $routes);
        self::assertArrayNotHasKey('buildConversationStartedResponse', $routes, 'the start body must stay unrouted');
        self::assertTrue((new ReflectionMethod(AgentConversationTraitsFixtureController::class, 'buildConversationStartedResponse'))->isProtected());
    }

    public function testEveryIdRouteKeepsTheNumericRequirementSoStaticPathsAreNeverSwallowed(): void
    {
        foreach ((new ReflectionClass(AgentConversationTraitsFixtureController::class))->getMethods() as $reflectionMethod) {
            foreach ([Get::class, Post::class, Patch::class, Delete::class] as $attributeClass) {
                foreach ($reflectionMethod->getAttributes($attributeClass) as $routeAttribute) {
                    $route = $routeAttribute->newInstance();
                    if (str_contains((string)$route->path, '{aiConversationId}')) {
                        self::assertSame('\d+', $route->requirements['aiConversationId'] ?? null, $reflectionMethod->getName());
                    }
                }
            }
        }
    }

    public function testTheAccessGateIsOpenByDefaultAndTheSearchScopeIsAnEnvelopeScope(): void
    {
        $fixtureController = new AgentConversationTraitsFixtureController();
        $fixtureController->callAuthorizeConversationAccess();
        self::assertStringStartsWith('ENVELOPE.', AgentConversationTraitsFixtureController::AGENT_CONVERSATION_SEARCH_ENVELOPE_SCOPE);
    }

    public function testTheRunStateCarriesWhatThePollingDefaultNeeds(): void
    {
        $aIConversation = new AIConversation();
        $aIConversation->id = 4711;
        $aIConversation->runState = 'RUNNING';
        $aIConversation->agentTurnOwedAt = DateTime::fromString('2026-11-10 12:00:00');
        $aIConversation->lastMessageId = 99;
        $aIConversationSummary = new AIConversationSummary();
        $aIConversationSummary->title = 'Opening hours';
        $aIConversationSummary->text = 'The owner changes the opening hours.';
        $aIConversationSummary->manuallyEdited = true;
        $aIConversation->summary = $aIConversationSummary;

        $runState = AIConversationRunStateGetResponseDto::fromConversation($aIConversation);

        self::assertSame(4711, $runState->id);
        self::assertSame('RUNNING', $runState->runState);
        self::assertSame('2026-11-10 12:00:00', (string)$runState->agentTurnOwedAt);
        self::assertSame(99, $runState->lastMessageId);
        self::assertSame('Opening hours', $runState->title);
        self::assertTrue($runState->titleManuallyEdited);
        self::assertSame('The owner changes the opening hours.', $runState->summary);
    }

    public function testARunStateWithoutSummaryOrMessagesIsNullAndTriggersNoGeneration(): void
    {
        $aIConversation = new AIConversation();
        $aIConversation->id = 12;
        $aIConversation->runState = 'IDLE';

        $runState = AIConversationRunStateGetResponseDto::fromConversation($aIConversation);

        self::assertNull($runState->agentTurnOwedAt);
        self::assertNull($runState->lastMessageId);
        self::assertNull($runState->title);
        self::assertNull($runState->summary);
        self::assertFalse($runState->titleManuallyEdited);
        self::assertFalse(isset($aIConversation->summary), 'the read must not load the summary (a model call)');
    }

    public function testTheComputedLastMessageIdIsNeverSerializedUnlessFilled(): void
    {
        $unfilledAIConversation = new AIConversation();
        $unfilledAIConversation->id = 12;
        self::assertArrayNotHasKey('lastMessageId', (array)$unfilledAIConversation->toObject(forPersistence: false));
        $filledAIConversation = new AIConversation();
        $filledAIConversation->id = 13;
        $filledAIConversation->lastMessageId = 5;
        self::assertSame(5, ((array)$filledAIConversation->toObject(forPersistence: false))['lastMessageId'] ?? null);
        self::assertArrayNotHasKey('lastMessageId', (array)$filledAIConversation->toObject(cached: false), 'never persisted');
    }
}
