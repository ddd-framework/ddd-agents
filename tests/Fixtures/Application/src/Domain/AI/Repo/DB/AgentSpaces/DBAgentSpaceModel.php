<?php

declare(strict_types=1);

namespace App\Domain\AI\Repo\DB\AgentSpaces;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use App\Domain\Workshops\Repo\DB\Workshops\DBWorkshopModel;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AgentSpaces')]
class DBAgentSpaceModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentSpace';

	public const string TABLE_NAME = 'AgentSpaces';

	public const string ENTITY_CLASS = 'App\Domain\AI\Entities\AgentSpaces\AgentSpace';

	#[ORM\Column(type: 'integer')]
	public int $workshopId;

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'string')]
	public ?string $timezone;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBWorkshopModel::class)]
	#[ORM\JoinColumn(name: 'workshopId', referencedColumnName: 'id')]
	public DBWorkshopModel $workshop;

	#[ORM\ManyToOne(targetEntity: DBAccountModel::class)]
	#[ORM\JoinColumn(name: 'accountId', referencedColumnName: 'id')]
	public ?DBAccountModel $account;

}