<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Mandates\Probes;

use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** The mandates service over accounts held in memory: the account of a grant is answered from `$storedAccountsById` and each id asked for is recorded. */
class AgentMandatesServiceWithStoredAccounts extends AgentMandatesService
{
    /** @var Account[] by id */
    public array $storedAccountsById = [];

    /** @var int[] the ids the account of a grant was asked for, in order */
    public array $accountIdsAskedFor = [];

    protected function findAccountOfMandateGrant(int $accountId): ?Account
    {
        $this->accountIdsAskedFor[] = $accountId;
        return $this->storedAccountsById[$accountId] ?? null;
    }
}
