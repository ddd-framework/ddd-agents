<?php

declare(strict_types=1);

/**
 * The namespace groups of the agent's skills — the package default, read as `Config::get('AI.skillNamespaceGroups')`
 * by {@see \DDD\Domain\AI\Services\Skills\AISkillCatalogDocService}. Admin view only: the groups structure the general
 * skills on the admin skill screen; they change nothing about which skill the agent is offered or loads. An
 * application replaces this file whole with its own groups (its product domains after the agent's group).
 *
 * Kinds: `common` (functions above the product domains), `agent` (the agent itself), `domain` (one product domain).
 * A skill belongs to the group whose `skillNamePrefixes` entry is the LONGEST prefix of its name.
 */
return [
    [
        'name' => 'Agent',
        'kind' => 'agent',
        'shortDescription' => 'The agent itself: its capabilities (tasks, memory, communication, web, tools) and its own runs.',
        'description' => 'What the agent can do independent of a product domain — tasks and schedules, task-run reports, '
            . 'budget, mandates, settings, memory, communication with the owner, web search, page view and small tools — '
            . 'and the runs the platform starts by itself. Not here: work on the customer\'s own data.',
        'skillNamePrefixes' => [
            'Agent.',
            'Assistant.',
            'Memory.',
            'Communication.',
            'Web.',
        ],
    ],
];
