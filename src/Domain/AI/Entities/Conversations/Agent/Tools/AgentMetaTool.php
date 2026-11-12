<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;

/**
 * One of the loop's own tools — answered by the loop, never by an MCP package. Each declares
 * its name, its effect for the readers that hold only a stored call ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog})
 * and its wire schema. The twelve are listed in {@see AgentMetaTools::TOOL_CLASSES}.
 *
 * Each implementation also carries `public const ?string DECLARED_EFFECT` (an {@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect}
 * type, or null for a tool that is neither a read nor a write).
 */
interface AgentMetaTool
{
    /** What the loop must do after this tool's call was dispatched; null for nothing. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest;

    /** The work this tool's call asks the dispatch to execute through its owning service; null when the tool answers its call itself. */
    public static function workRequest(ToolCall $toolCall): ?AgentToolCallWorkRequest;

    /** The tool's wire name (its `NAME` constant). */
    public function getToolName(): string;

    /**
     * The OpenAI-shaped schema the step offers.
     *
     * @return array<string, mixed>
     */
    public function toOpenAiToolSchema(): array;
}
