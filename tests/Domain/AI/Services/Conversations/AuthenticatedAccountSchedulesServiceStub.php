<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** Replaces ONLY the read of the signed-in account; every decision about a schedule stays the production method. */
class AuthenticatedAccountSchedulesServiceStub extends AIConversationSchedulesService
{
    public ?Account $authenticatedAccount = null;

    protected function findAuthenticatedAccount(): ?Account
    {
        return $this->authenticatedAccount;
    }
}
