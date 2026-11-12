<?php

declare(strict_types=1);

use DDD\Domain\AI\Entities\Models\AIModel;

/**
 * The supervisor of the agent — the package default, read by
 * {@see \DDD\Domain\AI\Services\Conversations\SupervisorCheckpointService} as `Config::get('AI.supervisor')`. An
 * application replaces this file whole with its own `config/app/AI/supervisor.php` (keys are never merged).
 *
 * `enabled` (the GLOBAL switch, off by default): false = no checkpoint is dispatched for a conversation that is not
 * explicitly armed by a per-conversation run-cap override above zero; true = every purpose that declares a
 * `supervisorCadence` is supervised.
 *
 * `modelName` + `reasoningEffort`: the default model and effort of every checkpoint; `stepReasoningEffort` the effort of
 * a step beat. `policyClassForConversationsWithoutAPurpose` (optional): the supervisor policy of a supervised
 * conversation without a purpose — missing = the base policy.
 */
return [
    'enabled' => false,
    'modelName' => AIModel::MODEL_OPENAI_GPT6_LUNA,
    'reasoningEffort' => 'medium',
    'stepReasoningEffort' => 'low',
];
