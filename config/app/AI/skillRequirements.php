<?php

declare(strict_types=1);

/**
 * The skill requirement overlay, read as `Config::get('AI.skillRequirements')` by
 * {@see \DDD\Domain\AI\Services\Skills\AISkillsService::applySkillRequirementOverlay()}:
 *
 *     skill name => [
 *         'requiredFeatureFlags' => string[],       // all of them
 *         'anyOfRequiredFeatureFlags' => string[],  // at least one of them
 *         'requiredAccountRights' => string[],
 *         'requiresAccountBillingAccess' => bool,
 *     ]
 *
 * united with the skill file's own frontmatter at load time. The package's skills carry no gate of their own: a gate
 * names an application's feature flag or account right, so the application puts it here. An application ships its
 * own file at this path, which REPLACES this one whole.
 */
return [];
