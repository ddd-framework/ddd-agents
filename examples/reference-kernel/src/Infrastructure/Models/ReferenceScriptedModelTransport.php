<?php

declare(strict_types=1);

namespace AgentsReference\Infrastructure\Models;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * DEMO ONLY — the model of the reference application: hand-written answers instead of a model provider. Installed as
 * the Argus transport ({@see \DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations::$transportRecording}), so EVERY
 * Argus HTTP call of the process — the agent's model calls and any other provider call — is answered here and none
 * reaches the network. No key of any provider is needed or read.
 *
 * Each answer of the answers file (`recordings/reference-conversation.json`) names the conditions of the request it
 * answers: the endpoint, the role and a text of the last message that is not a platform signal, a tool the request
 * offers. The first answer whose conditions all hold is returned. Unlike the recorded-run player of the parent class,
 * an answer is chosen by what the request says, not by its position, so the conversation can span several HTTP
 * requests (each request installs a fresh player). A request no answer matches fails like an unrecorded call of the
 * parent class; its body is written to the log directory (`unanswered-*.json`) so the next answer can be written.
 */
class ReferenceScriptedModelTransport extends ArgusTransportRecording
{
    /** @var array[] The hand-written answers, in matching order */
    protected array $scriptedAnswers = [];

    /** The directory the answered and unanswered requests are logged to (null = no log) */
    protected ?string $requestLogDirectory = null;

    /**
     * @param string $answersFile The JSON answers file
     * @param string|null $requestLogDirectory Where every request is logged (`answered-*.json`, `unanswered-*.json`)
     */
    public static function fromAnswersFile(string $answersFile, ?string $requestLogDirectory): static
    {
        $referenceScriptedModelTransport = new static(self::MODE_PLAY);
        $answersFileContent = json_decode((string)@file_get_contents($answersFile), true);
        $referenceScriptedModelTransport->scriptedAnswers = is_array($answersFileContent['answers'] ?? null) ? array_values($answersFileContent['answers']) : [];
        $referenceScriptedModelTransport->requestLogDirectory = $requestLogDirectory;
        return $referenceScriptedModelTransport;
    }

    protected function play(RequestInterface $request, array $options = []): PromiseInterface
    {
        $endpoint = self::endpointOf($request);
        $requestBody = (string)$request->getBody();
        $decodedRequestBody = json_decode($requestBody, true);
        $decodedRequestBody = is_array($decodedRequestBody) ? $decodedRequestBody : [];
        foreach ($this->scriptedAnswers as $scriptedAnswer) {
            if (!$this->isAnswerForRequest((array)$scriptedAnswer, $endpoint, $decodedRequestBody)) {
                continue;
            }
            $this->logRequest('answered', (string)($scriptedAnswer['name'] ?? ''), $endpoint, $decodedRequestBody, $requestBody);
            return Create::promiseFor(new Response(
                200,
                ['Content-Type' => 'application/json'],
                (string)json_encode($scriptedAnswer['response'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE)
            ));
        }
        $this->logRequest('unanswered', '', $endpoint, $decodedRequestBody, $requestBody);
        return Create::rejectionFor(new RuntimeException("reference model: no hand-written answer for {$endpoint}"));
    }

    /**
     * Whether every condition of `$scriptedAnswer` holds for the request: `endpoint` (exact), `lastMessageRole` and
     * `lastMessageContains` (the last chat message that is not a platform signal), `lastUserMessageContains` (the last
     * owner message that is not a platform signal — which owner turn the call belongs to), `offersTool` (a tool name of
     * the request's `tools`).
     */
    protected function isAnswerForRequest(array $scriptedAnswer, string $endpoint, array $decodedRequestBody): bool
    {
        if (isset($scriptedAnswer['endpoint']) && $scriptedAnswer['endpoint'] !== $endpoint) {
            return false;
        }
        $lastMessage = $this->findLastMessageThatIsNoSignal($decodedRequestBody);
        if (isset($scriptedAnswer['lastMessageRole']) && ($lastMessage['role'] ?? null) !== $scriptedAnswer['lastMessageRole']) {
            return false;
        }
        if (isset($scriptedAnswer['lastMessageContains'])
            && !str_contains($this->readMessageText($lastMessage), (string)$scriptedAnswer['lastMessageContains'])) {
            return false;
        }
        if (isset($scriptedAnswer['lastUserMessageContains'])
            && !str_contains($this->readMessageText($this->findLastMessageThatIsNoSignal($decodedRequestBody, 'user')), (string)$scriptedAnswer['lastUserMessageContains'])) {
            return false;
        }
        if (isset($scriptedAnswer['offersTool']) && !in_array($scriptedAnswer['offersTool'], $this->readOfferedToolNames($decodedRequestBody), true)) {
            return false;
        }
        return true;
    }

    /**
     * @param string|null $role Only messages of this role (null = any)
     * @return array The last entry of `messages` (of `$role`) whose text does not start with a platform signal tag (`[SIGNAL:`)
     */
    protected function findLastMessageThatIsNoSignal(array $decodedRequestBody, ?string $role = null): array
    {
        $messages = is_array($decodedRequestBody['messages'] ?? null) ? $decodedRequestBody['messages'] : [];
        for ($position = count($messages) - 1; $position >= 0; $position--) {
            $message = (array)$messages[$position];
            if ($role !== null && ($message['role'] ?? null) !== $role) {
                continue;
            }
            if (!str_starts_with(ltrim($this->readMessageText($message)), '[SIGNAL:')) {
                return $message;
            }
        }
        return [];
    }

    protected function readMessageText(array $message): string
    {
        $content = $message['content'] ?? '';
        return is_string($content) ? $content : (string)json_encode($content, JSON_UNESCAPED_UNICODE);
    }

    /** @return string[] The function names of the request's `tools` */
    protected function readOfferedToolNames(array $decodedRequestBody): array
    {
        $offeredToolNames = [];
        foreach ((array)($decodedRequestBody['tools'] ?? []) as $offeredTool) {
            $offeredToolName = $offeredTool['function']['name'] ?? $offeredTool['name'] ?? null;
            if (is_string($offeredToolName)) {
                $offeredToolNames[] = $offeredToolName;
            }
        }
        return $offeredToolNames;
    }

    protected function logRequest(string $outcome, string $answerName, string $endpoint, array $decodedRequestBody, string $requestBody): void
    {
        if ($this->requestLogDirectory === null) {
            return;
        }
        if (!is_dir($this->requestLogDirectory)) {
            @mkdir($this->requestLogDirectory, 0775, true);
        }
        $logEntry = ['outcome' => $outcome, 'answer' => $answerName, 'endpoint' => $endpoint, 'request' => $decodedRequestBody ?: $requestBody];
        $logFile = sprintf('%s/%s-%s-%s.json', $this->requestLogDirectory, date('His'), substr(md5($requestBody . microtime()), 0, 6), $outcome);
        @file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
