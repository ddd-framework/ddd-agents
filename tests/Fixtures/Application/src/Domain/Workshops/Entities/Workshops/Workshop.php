<?php

declare(strict_types=1);

namespace App\Domain\Workshops\Entities\Workshops;

use App\Domain\Workshops\Repo\DB\Workshops\DBWorkshop;
use App\Domain\Workshops\Services\WorkshopsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\Accounts\Account;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Fixture application: a bicycle workshop, the thing the application's agent works for. It belongs to an account, and
 * that account can change (a workshop is handed over to another owner).
 *
 * @method static WorkshopsService getService()
 * @method static DBWorkshop getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkshop::class)]
class Workshop extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string The name of the workshop */
    #[NotNull]
    #[Length(max: 128)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public string $name;

    /** @var int|null Id of the account that owns the workshop today */
    public ?int $accountId = null;

    /** @var Account|null The account that owns the workshop today; deleting the account deletes its workshops */
    #[LazyLoad(addAsParent: true)]
    public ?Account $account;

    /** @var string|null The IANA time zone the workshop is in (e.g. `Europe/Vienna`) */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $timezone = null;
}
