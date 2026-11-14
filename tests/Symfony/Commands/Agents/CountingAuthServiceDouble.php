<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;

/**
 * The framework's authentication service without an application's additions, counting the calls that set an account.
 * A test puts it behind `AuthService::instance()` so that an account built in memory can be authenticated in any host,
 * and so that it can tell whether a command touched the authentication at all.
 */
class CountingAuthServiceDouble extends AuthService
{
    public int $setAccountCallCount = 0;

    public function setAccount(?Account &$account): void
    {
        $this->setAccountCallCount++;
        parent::setAccount($account);
    }
}
