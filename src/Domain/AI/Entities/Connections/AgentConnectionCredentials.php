<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Infrastructure\Services\Service;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionCredentials;
use DDD\Domain\AI\Services\Connections\AgentConnectionCredentialSetsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The secret part of ONE connection, in a table of its own and hidden from every API answer and from the
 * model. The secret payload (tokens, a password) is encrypted at rest with the stage's key
 * ({@see AgentConnectionCredentialSetsService::storeSecrets()}); a host system secret (a Slack bot
 * token) stays in the environment and the row holds only the variable's name.
 *
 * @property AgentConnectionCredentialSets $parent
 * @method AgentConnectionCredentialSets getParent()
 * @method static AgentConnectionCredentialSetsService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['agentConnectionId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionCredentials::class)]
class AgentConnectionCredentials extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string TYPE_OAUTH = 'OAUTH';
    public const string TYPE_PASSWORD = 'PASSWORD';
    public const string TYPE_ENVIRONMENT_REFERENCE = 'ENVIRONMENT_REFERENCE';

    /**
     * The set is AgentConnectionCredentialSets: the plural of "Credentials" is the name itself, so the inflector
     * convention would answer this class and getService() would recurse.
     */
    /**
     * The name's plural is the name itself, so the framework's lookup through self::getEntitySetClass() finds the
     * entity instead of its set; the service comes from the set directly. The set class is not overridden: the table
     * name is derived from it.
     */
    public static function getService(): ?Service
    {
        return AgentConnectionCredentialSets::getService();
    }

    public ?int $agentConnectionId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentConnection $agentConnection;

    #[Length(max: 32)]
    #[Choice(choices: [self::TYPE_OAUTH, self::TYPE_PASSWORD, self::TYPE_ENVIRONMENT_REFERENCE])]
    public ?string $credentialType = null;

    /** @var string|null The encrypted JSON payload (never in an answer, a log or the model's context) */
    #[HideProperty]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $encryptedPayload = null;

    /** @var string|null The fingerprint of the key the payload was encrypted with (a copied row of another stage differs) */
    #[HideProperty]
    #[Length(max: 64)]
    public ?string $keyFingerprint = null;

    /** @var DateTime|null When the access token expires; refreshed shortly before */
    public ?DateTime $accessTokenExpiresAt = null;

    /** @var string|null ENVIRONMENT_REFERENCE: the name of the variable that holds the host secret */
    #[Length(max: 128)]
    public ?string $environmentVariableName = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(($this->agentConnectionId ?? 0));
    }
}
