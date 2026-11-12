<?php

declare(strict_types=1);

/**
 * The purpose requirement overlay, read as `Config::get('AI.purposeRequirements')` by
 * {@see \DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService::getPurposeByName()}:
 *
 *     purpose name => ['requiredFeatureFlags' => string[]]
 *
 * united with the purpose file's own frontmatter at load time (after the `inheritsFrom` merge). The package's purposes
 * carry no gate; an application ships its own file at this path, which REPLACES this one whole.
 */
return [];
