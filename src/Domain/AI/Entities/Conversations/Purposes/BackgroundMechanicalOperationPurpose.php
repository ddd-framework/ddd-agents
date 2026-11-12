<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Services\Conversations\Agent\AgentBackgroundOperationsService;
use DDD\Infrastructure\Services\DDDService;

/**
 * The `Background.MechanicalOperation` purpose: a delegation CHILD
 * conversation that executes ONE named operation WITHOUT any model call. A TOOL requests these via the
 * generic `backgroundOperations[]` field of its result (e.g. a tool that stores an address requests a check of
 * that address when it genuinely changed); the loop validates the requested names against the tool run below and
 * the operations the application names ({@see AgentBackgroundOperationsService::getSupportedOperationNames()}) and
 * spawns them into a BACKGROUND batch that never resumes the parent. This class owns the purpose's seed-text vocabulary: the child's seed
 * USER message carries a readable label line followed by the operation payload as one JSON line;
 * {@see buildOperationSeedText()} and {@see parseOperationSeedText()} are the two halves of that contract
 * (spawn side / child-drive side) and must only change together.
 */
class BackgroundMechanicalOperationPurpose extends AIConversationPurpose
{
    /** Execute ONE MCP tool ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallExecutionService::executeToolCall()}) — payload carries `toolName` + `toolArguments`. */
    public const string OPERATION_EXECUTE_TOOL = 'executeTool';

    /**
     * The child's seed USER message text for one mechanical operation: a human-readable label line (this is
     * what the parent's delegation-result slice shows as the task) followed by the machine payload as one
     * JSON line. The label derives from the payload — callers never pass free text.
     *
     * @param array<string, mixed> $toolArguments
     */
    public static function buildOperationSeedText(string $operation, ?string $toolName = null, array $toolArguments = []): string
    {
        // An operation the application gives no label labels itself by its name: a foreign label would mislead the
        // parent's delegation-result slice (the child drive fails an unknown operation with an honest verdict).
        $label = $operation === self::OPERATION_EXECUTE_TOOL
            ? 'Background job: run tool ' . ($toolName ?? '')
            : (static::findApplicationOperationLabel($operation) ?? 'Background job: ' . $operation);
        $payload = ['operation' => $operation];
        if ($toolName !== null) {
            $payload['toolName'] = $toolName;
        }
        if ($toolArguments !== []) {
            $payload['toolArguments'] = $toolArguments;
        }
        return $label . "\n" . json_encode($payload);
    }

    /** The label line the application gives one of its own operations; null for a name it gives none. */
    protected static function findApplicationOperationLabel(string $operation): ?string
    {
        /** @var AgentBackgroundOperationsService $agentBackgroundOperationsService */
        $agentBackgroundOperationsService = DDDService::instance()->getService(AgentBackgroundOperationsService::class);
        return $agentBackgroundOperationsService->getOperationLabel($operation);
    }

    /**
     * Parse a seed text built by {@see buildOperationSeedText()} back into the operation. Null on a
     * text that carries no parsable JSON payload line or names no operation — the child drive then fails the
     * job with a truthful verdict instead of guessing.
     *
     * @param string|null $seedText
     * @return BackgroundMechanicalOperation|null
     */
    public static function parseOperationSeedText(?string $seedText): ?BackgroundMechanicalOperation
    {
        $jsonStart = strpos($seedText ?? '', '{');
        if ($seedText === null || $jsonStart === false) {
            return null;
        }
        $payload = json_decode(substr($seedText, $jsonStart), true);
        if (!is_array($payload)) {
            return null;
        }
        return BackgroundMechanicalOperation::fromOperationPayload($payload);
    }
}
