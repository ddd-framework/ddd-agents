<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\AI\MessageHandlers\AgentMessengerTransports;
use Symfony\Component\Yaml\Yaml;

/**
 * The transport contract an application that runs the agent's workers must meet, checked against ITS configuration:
 * every transport of {@see AgentMessengerTransports::ALL} is defined in the application's `messenger.yaml`, every
 * message of {@see AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS} is routed to its transport, and the
 * application's supervisor file runs a worker pool (`[program:<transport>]` consuming that transport) for each.
 *
 * Use it in one test class of the application (a trait, so the class keeps the application's own test base):
 * ```php
 * class AgentMessengerTransportsTest extends MyAppTestCase
 * {
 *     use MessengerTransportsHaveWorkerBlocksContract;
 *     protected function messengerConfigPath(): string { return '…/config/packages/messenger.yaml'; }
 *     protected function supervisorConfigPath(): string { return '…/config/supervisor/workers.conf'; }
 * }
 * ```
 * The test file is not autoloaded by an application (it is the package's test code): `require_once` it from the
 * package's install directory.
 */
trait MessengerTransportsHaveWorkerBlocksContract
{
    /** @return string The absolute path of the application's Messenger configuration (`framework.messenger.transports` / `routing`) */
    abstract protected function messengerConfigPath(): string;

    /** @return string The absolute path of the application's supervisor configuration (one `[program:<name>]` block per pool) */
    abstract protected function supervisorConfigPath(): string;

    public function testEveryAgentTransportIsDefined(): void
    {
        $transports = $this->readMessengerConfig()['transports'] ?? [];
        foreach (AgentMessengerTransports::ALL as $transportName) {
            self::assertArrayHasKey($transportName, $transports, "the agent transport '{$transportName}' is not defined in the Messenger configuration");
        }
    }

    public function testEveryAgentMessageIsRoutedToItsTransport(): void
    {
        $routing = $this->readMessengerConfig()['routing'] ?? [];
        foreach (AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS as $messageClass => $transportName) {
            self::assertArrayHasKey($messageClass, $routing, "the agent message {$messageClass} is not routed");
            $routedTransports = (array)$routing[$messageClass];
            self::assertSame([$transportName], array_values($routedTransports), "the agent message {$messageClass} must go to '{$transportName}' only");
        }
    }

    public function testEveryAgentTransportHasAWorkerPool(): void
    {
        $supervisorFile = (string)file_get_contents($this->supervisorConfigPath());
        preg_match_all('/^\[program:([^\]]+)\]/m', $supervisorFile, $programMatches);
        foreach (AgentMessengerTransports::ALL as $transportName) {
            self::assertContains($transportName, $programMatches[1], "the agent transport '{$transportName}' has no [program:{$transportName}] block");
            self::assertStringContainsString("messenger:consume {$transportName} ", $supervisorFile, "the pool of '{$transportName}' must consume that transport");
        }
    }

    /**
     * @return array<string, mixed> The `framework.messenger` section of the application's Messenger configuration
     */
    protected function readMessengerConfig(): array
    {
        $messengerConfig = Yaml::parseFile($this->messengerConfigPath(), Yaml::PARSE_CUSTOM_TAGS);
        return $messengerConfig['framework']['messenger'] ?? [];
    }
}
