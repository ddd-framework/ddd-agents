<?php

declare(strict_types=1);

use DDD\Domain\AI\Entities\Skills\AISkill;

/**
 * The package's default skill → MCP-package binding, read as `Config::get('AI.skillPackages')` by
 * {@see AISkill::getPackageClassesBySkillName()}. It lists only the skills whose MCP packages ship with this package,
 * by package id ({@see \DDD\Domain\AI\Services\Skills\MCPPackageIdsService}); their skill files ship in
 * `config/app/AI/Skills/`.
 *
 * An application ships its own `config/app/AI/skillPackages.php`, which REPLACES this file whole (application config
 * roots are searched first): it must list these skills itself when it wants them offered (by class or by id).
 */
return [
    AISkill::ASSISTANT_MANDATES => ['agent.mandates'],
    AISkill::ASSISTANT_TASK_RUN_REPORTS => ['agent.taskRunReports'],
];
