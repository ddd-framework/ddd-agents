<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionPreservedFacts;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * In-memory fixtures of the compaction characterization (tests only, no database): conversations, message sets,
 * compaction nodes and node sets built WITHOUT their constructors, so no `#[LazyLoad]` relation is touched. The
 * helper names carry a `fixture` prefix so the trait can sit on both {@see \DDD\Tests\Agents\Support\UnitTestCase} and
 * {@see \DDD\Tests\Agents\Support\IntegrationTestCase} without colliding with the unit base's own reflection helpers.
 */
trait CompactionCharacterizationFixtures
{
    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    protected static function fixtureInstantiate(string $className): object
    {
        /** @var T $instance */
        $instance = (new ReflectionClass($className))->newInstanceWithoutConstructor();
        return $instance;
    }

    protected static function fixtureSetProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflectionClass = new ReflectionClass($object);
        while (!$reflectionClass->hasProperty($propertyName) && $reflectionClass->getParentClass() !== false) {
            $reflectionClass = $reflectionClass->getParentClass();
        }
        $reflectionProperty = new ReflectionProperty($reflectionClass->getName(), $propertyName);
        $reflectionProperty->setValue($object, $value);
    }

    /** Any method regardless of visibility. */
    protected static function fixtureInvoke(object $object, string $methodName, array $arguments = []): mixed
    {
        $reflectionMethod = new ReflectionMethod($object, $methodName);
        return $reflectionMethod->invokeArgs($object, $arguments);
    }

    /** A bare conversation: no messages, no settings, no model, no purpose; `compactionNodes` left uninitialized. */
    protected static function fixtureConversation(?int $conversationId = null): AIConversation
    {
        $conversation = self::fixtureInstantiate(AIConversation::class);
        if ($conversationId !== null) {
            $conversation->id = $conversationId;
        }
        return $conversation;
    }

    /**
     * A message set over $messages (elements set directly — `add()` takes its element by reference).
     *
     * @param AIConversationMessage[] $messages
     */
    protected static function fixtureMessageSet(array $messages): AIConversationMessages
    {
        $messageSet = self::fixtureInstantiate(AIConversationMessages::class);
        self::fixtureSetProperty($messageSet, 'elements', array_values($messages));
        self::fixtureSetProperty($messageSet, 'elementCount', count($messages));
        return $messageSet;
    }

    /** An assistant text row at $index carrying the model-counted whole-prompt footprint $inputTokens. */
    protected static function fixtureAssistantMessage(int $index, ?int $inputTokens = null): AIConversationMessage
    {
        $message = self::fixtureInstantiate(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->message = "reply $index";
        $message->toolCall = null;
        $message->inputTokens = $inputTokens;
        return $message;
    }

    /** A plain row at $index (no footprint) — the validator reads only its index. */
    protected static function fixtureIndexedMessage(int $index): AIConversationMessage
    {
        return self::fixtureAssistantMessage($index);
    }

    /**
     * A tool-call row at $index.
     *
     * @param array<string, mixed> $arguments
     */
    protected static function fixtureToolCallMessage(
        int $index,
        string $toolName,
        array $arguments = [],
        string $result = 'ok',
        ?string $status = AIConversationMessage::STATUS_COMPLETED
    ): AIConversationMessage {
        $message = self::fixtureInstantiate(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->result = $result;
        $toolCall->status = $status;
        $message->toolCall = $toolCall;
        return $message;
    }

    /** A compaction node over [$from, $to] at $level (`fromRawMessages` = level 0, as the builders stamp it). */
    protected static function fixtureNode(int $level, int $from, int $to, ?int $aiConversationId = null): AIConversationCompactionNode
    {
        $node = self::fixtureInstantiate(AIConversationCompactionNode::class);
        $node->aiConversationId = $aiConversationId;
        $node->level = $level;
        $node->coveredFromMessageIndex = $from;
        $node->coveredToMessageIndex = $to;
        $node->summaryText = "L$level [$from-$to]";
        $node->fromRawMessages = $level === 0;
        return $node;
    }

    /** A level-0 node over [$from, $to] whose preserved facts carry $toolTelemetry (null = a node written before the slot). */
    protected static function fixtureNodeWithTelemetry(int $from, int $to, ?AIConversationCompactionToolTelemetry $toolTelemetry): AIConversationCompactionNode
    {
        $node = self::fixtureNode(0, $from, $to);
        $node->preservedFacts = new AIConversationCompactionPreservedFacts();
        $node->preservedFacts->toolTelemetry = $toolTelemetry;
        return $node;
    }

    /**
     * A node set over $nodes, of $setClassName (a double subclass may be passed).
     *
     * @template T of AIConversationCompactionNodes
     * @param AIConversationCompactionNode[] $nodes
     * @param class-string<T> $setClassName
     * @return T
     */
    protected static function fixtureNodeSet(array $nodes, string $setClassName = AIConversationCompactionNodes::class): AIConversationCompactionNodes
    {
        $nodeSet = self::fixtureInstantiate($setClassName);
        self::fixtureSetProperty($nodeSet, 'elements', array_values($nodes));
        self::fixtureSetProperty($nodeSet, 'elementCount', count($nodes));
        return $nodeSet;
    }

    /**
     * The (level, from, to) shape of each node, in the given order.
     *
     * @param AIConversationCompactionNode[] $nodes
     * @return array<int, array{0: int|null, 1: int|null, 2: int|null}>
     */
    protected static function fixtureShape(array $nodes): array
    {
        return array_values(array_map(
            static fn (AIConversationCompactionNode $node): array => [$node->level, $node->coveredFromMessageIndex, $node->coveredToMessageIndex],
            $nodes
        ));
    }
}
