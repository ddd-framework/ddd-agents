<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations;

/**
 * Who is driving an {@see AIConversation} turn. A plain value-const holder (not an Entity / native enum) to
 * match the project's {@see AIConversationRunState} / `AIModel::*` style. The scope gates the admin-only seams in
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::driveConversation()} — impersonation,
 * budget-bypass, model/tier pick, truncate/branch, and the synchronous "wait for the reply" path — none of which a
 * customer turn may use. Both scopes share the SAME loop, async dispatch, run-claim, fan-in and realtime signal.
 */
class AIConversationDriveScope
{
    /**
     * ADMIN console: the HTTP caller is an admin, so the drive IMPERSONATES the owning account of the conversation's
     * space (rights deactivated → resolve the space's owning account → `AuthService::setAccount`), MAY lift the
     * budget (`budgetBypass`), pick the model, truncate/branch, and run synchronously. Reads ANY account's conversation.
     */
    public const string ADMIN = 'ADMIN';

    /**
     * CUSTOMER webapp: the caller IS the account (`AuthService::getAccount()`) — NO impersonation, NO
     * rights-bypass (the entity rights layer confines the customer to its own conversations), budget ALWAYS enforced,
     * agent-default model, no truncate/branch, and ALWAYS async (a customer never blocks the HTTP request).
     */
    public const string CUSTOMER = 'CUSTOMER';
}
