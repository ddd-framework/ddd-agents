<?php

declare(strict_types=1);

/**
 * The context-retention defaults of the agent — the package default, read by
 * {@see \DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings} as `Config::get('AI.contextRetention')`.
 * An application replaces this file whole with its own `config/app/AI/contextRetention.php` (keys are never merged).
 * A non-empty `AGENT_UNLOAD_GRANULARITY` environment variable (`skill` / `tool`) overrides `unloadGranularity`.
 *
 * `unloadGranularity`: `skill` = a skill leaves with all its tools at once; `tool` = the per-tool cost-benefit score.
 * `skillLoadingStrategy`: `systemPromptSwap` (loaded skills render in the system content) or `conversationAppend`
 * (append-only skill rows inside a prefix epoch, Responses API only; a Chat Completions model falls back to the swap).
 * `rebuildEpochOnSituationChange`, `stateChangeDelivery` (`everyCall` / `onceWhenChanged`),
 * `appendOnlyReachableToolSetCeilingTokens` and `previousReasoningCapTokens`: see the settings class.
 */
return [
    'unloadGranularity' => 'skill',
    'skillLoadingStrategy' => 'conversationAppend',
    'rebuildEpochOnSituationChange' => true,
    'stateChangeDelivery' => 'onceWhenChanged',
    'appendOnlyReachableToolSetCeilingTokens' => 55000,
    'previousReasoningCapTokens' => 1000,
];
