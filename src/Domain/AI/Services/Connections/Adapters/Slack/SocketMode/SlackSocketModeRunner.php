<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Slack\SocketMode;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Services\Connections\Adapters\Slack\SlackAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionInboundDispatchService;
use DDD\Infrastructure\Services\DDDService;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

/**
 * Slack inbound by Socket Mode (local and dev only): opens the WebSocket with the app-level token, acknowledges every
 * envelope at once and hands its payload to the dispatch of the Events API route. Reconnects when Slack asks
 * (`disconnect`) or the socket drops. Needs `ratchet/pawl` (this namespace is gated on it,
 * {@see \DDD\Modules\Agents\AgentsModule::getNamespacesRequiringClasses()}); built with `new` by the console command.
 */
#[Exclude]
class SlackSocketModeRunner
{
    public const int RECONNECT_AFTER_FAILURE_SECONDS = 30;

    public const int RECONNECT_AFTER_CLOSE_SECONDS = 2;

    protected LoopInterface $loop;

    protected bool $stopRequested = false;

    protected ?WebSocket $webSocket = null;

    public function __construct(protected SlackAgentConnectionAdapter $slackAdapter, protected OutputInterface $output)
    {
        $this->loop = Loop::get();
    }

    public function run(int $maximumMinutes): void
    {
        if (function_exists('pcntl_signal')) {
            $this->loop->addSignal(SIGTERM, [$this, 'stop']);
            $this->loop->addSignal(SIGINT, [$this, 'stop']);
        }
        if ($maximumMinutes > 0) {
            $this->loop->addTimer($maximumMinutes * 60, [$this, 'stop']);
        }
        $this->connect();
        $this->loop->run();
    }

    public function stop(): void
    {
        $this->stopRequested = true;
        $this->webSocket?->close();
        $this->loop->stop();
    }

    public function connect(): void
    {
        if ($this->stopRequested) {
            return;
        }
        try {
            $socketUrl = $this->slackAdapter->openSocketModeUrl();
        } catch (Throwable $openFailure) {
            $this->output->writeln('<error>apps.connections.open failed: ' . $openFailure->getMessage() . '</error>');
            $this->loop->addTimer(static::RECONNECT_AFTER_FAILURE_SECONDS, [$this, 'connect']);
            return;
        }
        (new Connector($this->loop))($socketUrl)->then([$this, 'onConnected'], [$this, 'onConnectFailed']);
    }

    public function onConnected(WebSocket $webSocket): void
    {
        $this->webSocket = $webSocket;
        $this->output->writeln('Socket Mode connected.');
        $webSocket->on('message', [$this, 'onMessage']);
        $webSocket->on('close', [$this, 'onClose']);
    }

    public function onConnectFailed(Throwable $connectFailure): void
    {
        $this->output->writeln('<error>Socket Mode connect failed: ' . $connectFailure->getMessage() . '</error>');
        $this->loop->addTimer(static::RECONNECT_AFTER_FAILURE_SECONDS, [$this, 'connect']);
    }

    public function onClose(): void
    {
        $this->webSocket = null;
        if ($this->stopRequested) {
            return;
        }
        $this->output->writeln('Socket Mode closed; reconnecting.');
        $this->loop->addTimer(static::RECONNECT_AFTER_CLOSE_SECONDS, [$this, 'connect']);
    }

    public function onMessage(MessageInterface|string $message): void
    {
        $envelope = json_decode((string)$message, true);
        if (!is_array($envelope)) {
            return;
        }
        if (isset($envelope['envelope_id'])) {
            $this->webSocket?->send((string)json_encode(['envelope_id' => $envelope['envelope_id']]));
        }
        if (($envelope['type'] ?? '') === 'disconnect') {
            $this->webSocket?->close();
            return;
        }
        if (!in_array($envelope['type'] ?? '', ['events_api', 'interactive'], true) || !is_array($envelope['payload'] ?? null)) {
            return;
        }
        try {
            /** @var AgentConnectionInboundDispatchService $agentConnectionInboundDispatchService */
            $agentConnectionInboundDispatchService = DDDService::instance()->getService(AgentConnectionInboundDispatchService::class);
            $agentConnectionInboundDispatchService->acceptSocketModePayload(AgentConnection::PROVIDER_SLACK, $envelope['payload']);
        } catch (Throwable $dispatchFailure) {
            // one envelope never stops the loop; the failure is shown and the next envelope is handled
            $this->output->writeln('<error>Dispatch failed: ' . $dispatchFailure->getMessage() . '</error>');
        }
    }
}
