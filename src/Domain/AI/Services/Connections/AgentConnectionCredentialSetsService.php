<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCredentials;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCredentialSets;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionCredentials;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Encrypt;
use DDD\Infrastructure\Services\DDDService;

/**
 * The credentials of the connections: encrypted at rest with the stage's key, decrypted only inside an adapter call.
 *
 * The key is the environment variable `AI.agentConnections.credentialsKeyEnvironmentVariable`; one per stage, so a
 * production row copied into a dev database cannot be decrypted there (its fingerprint differs and the read refuses).
 * Without a key nothing is stored: a connect fails closed with a named error, never with a plain-text fallback.
 *
 * Every read and write here runs with the rights restrictions switched off: the credentials table is never readable
 * under rights ({@see \DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionCredentials}).
 *
 * @method AgentConnectionCredentials|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionCredentialSets findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionCredentials update(AgentConnectionCredentials &$entity)
 */
class AgentConnectionCredentialSetsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionCredentials::class;

    public const string MISSING_KEY = 'AgentConnections cannot store credentials: the encryption key %s is not set in this stage. Ask the operators to provide it.';

    public const string FOREIGN_KEY = 'The credentials of this connection were encrypted in another stage and cannot be read here. Connect it again in this stage.';

    /**
     * Stores the secret payload of a connection, encrypted; replaces what the connection had.
     *
     * @throws AgentConnectionRefusedException when the stage has no key
     */
    public function storeSecrets(
        AgentConnection $agentConnection,
        string $credentialType,
        AgentConnectionSecretPayload $secretPayload,
        ?int $accessTokenExpiresAtUnixTime = null,
        ?string $environmentVariableName = null
    ): AgentConnectionCredentials {
        $encryptionKey = $this->requireEncryptionKey();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnectionCredentials = $this->findOfConnection($agentConnection) ?? AgentConnectionCredentials::newInstance();
            $agentConnectionCredentials->agentConnectionId = (int)$agentConnection->id;
            $agentConnectionCredentials->credentialType = $credentialType;
            $agentConnectionCredentials->encryptedPayload = (string)Encrypt::encrypt((string)json_encode($secretPayload->toEncryptableArray()), $encryptionKey);
            $agentConnectionCredentials->keyFingerprint = static::fingerprintOfKey($encryptionKey);
            $agentConnectionCredentials->accessTokenExpiresAt = $accessTokenExpiresAtUnixTime !== null ? DateTime::fromTimestamp($accessTokenExpiresAtUnixTime) : null;
            $agentConnectionCredentials->environmentVariableName = $environmentVariableName;
            $agentConnectionCredentials->update();
            return $agentConnectionCredentials;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The decrypted secrets of a connection.
     *
     * @throws AgentConnectionRefusedException no credentials, no key, or a payload of another stage
     */
    public function readSecrets(AgentConnection $agentConnection): AgentConnectionSecretPayload
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnectionCredentials = $this->findOfConnection($agentConnection);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if (!$agentConnectionCredentials instanceof AgentConnectionCredentials) {
            throw new AgentConnectionRefusedException('This connection has no credentials. Connect it again.');
        }
        if ($agentConnectionCredentials->credentialType === AgentConnectionCredentials::TYPE_ENVIRONMENT_REFERENCE) {
            $secretValue = AgentConnectionsConfiguration::readEnvironmentValue((string)$agentConnectionCredentials->environmentVariableName);
            if ($secretValue === null || $secretValue === '') {
                throw new AgentConnectionRefusedException('The system secret of this connection (' . $agentConnectionCredentials->environmentVariableName . ') is not set in this stage.');
            }
            return new AgentConnectionSecretPayload(['token' => $secretValue]);
        }
        $encryptionKey = $this->requireEncryptionKey();
        if ($agentConnectionCredentials->keyFingerprint !== static::fingerprintOfKey($encryptionKey)) {
            throw new AgentConnectionRefusedException(static::FOREIGN_KEY);
        }
        $decryptedPayload = Encrypt::decrypt((string)$agentConnectionCredentials->encryptedPayload, $encryptionKey);
        $values = is_string($decryptedPayload) ? json_decode($decryptedPayload, true) : null;
        if (!is_array($values)) {
            throw new AgentConnectionRefusedException(static::FOREIGN_KEY);
        }
        return new AgentConnectionSecretPayload($values);
    }

    /** When the stored access token expires; null = unknown or none */
    public function findAccessTokenExpiry(AgentConnection $agentConnection): ?int
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnectionCredentials = $this->findOfConnection($agentConnection);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $agentConnectionCredentials?->accessTokenExpiresAt?->getTimestamp();
    }

    /** Deletes the credentials of a connection (disconnect); the audit stays */
    public function deleteSecrets(AgentConnection $agentConnection): void
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $this->findOfConnection($agentConnection)?->delete();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function hasEncryptionKey(): bool
    {
        $encryptionKey = AgentConnectionsConfiguration::readEnvironmentValue(AgentConnectionsConfiguration::current()->getCredentialsKeyEnvironmentVariable());
        return $encryptionKey !== null && strlen($encryptionKey) >= 16;
    }

    protected function findOfConnection(AgentConnection $agentConnection): ?AgentConnectionCredentials
    {
        $repository = AgentConnectionCredentials::getRepoClassInstance();
        $alias = $repository::getBaseModelAlias();
        $queryBuilder = $repository::createQueryBuilder(true);
        $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id);
        $agentConnectionCredentials = $repository->find($queryBuilder, false);
        return $agentConnectionCredentials instanceof AgentConnectionCredentials ? $agentConnectionCredentials : null;
    }

    /** @throws AgentConnectionRefusedException */
    /** The key of stored secrets (and of the PKCE verifier in an OAuth state); refuses without one */
    public function requireEncryptionKey(): string
    {
        $variableName = AgentConnectionsConfiguration::current()->getCredentialsKeyEnvironmentVariable();
        $encryptionKey = AgentConnectionsConfiguration::readEnvironmentValue($variableName);
        if ($encryptionKey === null || strlen($encryptionKey) < 16) {
            throw new AgentConnectionRefusedException(sprintf(static::MISSING_KEY, $variableName));
        }
        return $encryptionKey;
    }

    public static function fingerprintOfKey(string $encryptionKey): string
    {
        return substr(hash('sha256', 'agent-connections|' . $encryptionKey), 0, 32);
    }
}
