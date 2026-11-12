<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an MCP package of the ADMIN audience: its tools read or change data across every account, so the
 * executor runs them only for an admin with access to all resources — for EVERY caller, the HTTP admin mount and a
 * later in-process admin agent alike. The firewall's ROLE_ADMIN is not enough: an agency-scoped admin holds it too.
 *
 * Its tools are also exempt from the executor's automation eligibility gate: an admin inspects a locked, cancelled or
 * erased customer exactly when something went wrong there, and an admin tool without a space must not be judged by the
 * admin's own account ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService::dispatchTool()}).
 *
 *   #[MCPRequiresAdminWithAccessToAllResources]
 *   class AdminAIConversationsMCPPackage extends MCPPackage {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
readonly class MCPRequiresAdminWithAccessToAllResources
{
}
