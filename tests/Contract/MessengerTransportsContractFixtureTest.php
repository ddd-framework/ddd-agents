<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\AI\MessageHandlers\AgentMessengerTransports;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The transport contract against a minimal application configuration (`Fixtures/`), and the package's own consistency:
 * every agent handler consumes the transport its message is routed to.
 */
class MessengerTransportsContractFixtureTest extends TestCase
{
    use MessengerTransportsHaveWorkerBlocksContract;

    protected function messengerConfigPath(): string
    {
        return __DIR__ . '/Fixtures/messenger.yaml';
    }

    protected function supervisorConfigPath(): string
    {
        return __DIR__ . '/Fixtures/workers.conf';
    }

    public function testEveryHandlerConsumesTheTransportItsMessageIsRoutedTo(): void
    {
        $handlerFiles = glob(dirname(__DIR__, 2) . '/src/Domain/AI/MessageHandlers/*Handler.php') ?: [];
        $checkedMessageClasses = [];
        foreach ($handlerFiles as $handlerFile) {
            $handlerClass = 'DDD\\Domain\\AI\\MessageHandlers\\' . basename($handlerFile, '.php');
            $handlerReflection = new ReflectionClass($handlerClass);
            if ($handlerReflection->isAbstract()) {
                continue;
            }
            $handlerAttributes = $handlerReflection->getAttributes(AsMessageHandler::class);
            self::assertCount(1, $handlerAttributes, "{$handlerClass} declares one AsMessageHandler");
            $fromTransport = $handlerAttributes[0]->newInstance()->fromTransport;
            $messageType = $handlerReflection->getMethod('__invoke')->getParameters()[0]->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $messageType);
            $messageClass = $messageType->getName();
            self::assertArrayHasKey($messageClass, AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS, "{$messageClass} has a routing entry");
            self::assertSame(AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS[$messageClass], $fromTransport, "{$handlerClass} consumes the transport of its message");
            $checkedMessageClasses[] = $messageClass;
        }
        sort($checkedMessageClasses);
        $routedMessageClasses = array_keys(AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS);
        sort($routedMessageClasses);
        self::assertSame($routedMessageClasses, $checkedMessageClasses, 'every routed message has exactly one agent handler');
    }

    public function testEveryRoutedTransportIsAnAgentTransport(): void
    {
        foreach (AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS as $messageClass => $transportName) {
            self::assertTrue(class_exists($messageClass), "{$messageClass} exists");
            self::assertContains($transportName, AgentMessengerTransports::ALL);
        }
        self::assertSame(count(AgentMessengerTransports::ALL), count(array_unique(AgentMessengerTransports::ALL)));
    }
}
