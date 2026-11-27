<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * The configuration of AgentConnections as one typed object (`AI.agentConnections`,
 * config/app/AI/agentConnections.php). Read through {@see self::current()}; nothing is cached across processes.
 */
class AgentConnectionsConfiguration extends ValueObject
{
    public const string CONFIG_KEY = 'AI.agentConnections';

    public const string DEFAULT_STAGE = 'local';

    public const string SLACK_INBOUND_EVENTS_API = 'EVENTS_API';
    public const string SLACK_INBOUND_SOCKET_MODE = 'SOCKET_MODE';

    /** @var array<string, mixed> The raw configuration */
    protected array $configuration = [];

    public static function current(): static
    {
        $agentConnectionsConfiguration = new static();
        $configuration = Config::get(self::CONFIG_KEY);
        $agentConnectionsConfiguration->configuration = is_array($configuration) ? $configuration : [];
        return $agentConnectionsConfiguration;
    }

    /** @param array<string, mixed> $configuration a configuration of a test */
    public static function fromArray(array $configuration): static
    {
        $agentConnectionsConfiguration = new static();
        $agentConnectionsConfiguration->configuration = $configuration;
        return $agentConnectionsConfiguration;
    }

    public function isEnabled(): bool
    {
        return (bool)($this->configuration['enabled'] ?? false);
    }

    public function isAdapterEnabled(string $provider): bool
    {
        // the test double never runs in production, whatever the configuration says
        if ($provider === AgentConnection::PROVIDER_FAKE && $this->isProductionStage()) {
            return false;
        }
        return $this->isEnabled() && (bool)($this->configuration['adapters'][$provider]['enabled'] ?? false);
    }

    public function getAdapterSettings(string $provider): AgentConnectionAdapterSettings
    {
        $configuredSettings = $this->configuration['adapters'][$provider] ?? [];
        return AgentConnectionAdapterSettings::fromConfiguration(is_array($configuredSettings) ? $configuredSettings : []);
    }

    /** @return array<string, class-string> provider => adapter class */
    public function getAdapterClasses(): array
    {
        $adapterClasses = $this->configuration['adapters']['classes'] ?? [];
        return is_array($adapterClasses) ? $adapterClasses : [];
    }

    /** @return string[] the providers whose adapter is switched on */
    public function getEnabledProviders(): array
    {
        $enabledProviders = [];
        foreach (array_keys($this->getAdapterClasses()) as $provider) {
            if ($this->isAdapterEnabled((string)$provider)) {
                $enabledProviders[] = (string)$provider;
            }
        }
        return $enabledProviders;
    }

    /** The stage this process runs in: the process environment first, then the env files */
    public function getCurrentStage(): string
    {
        $variableName = (string)($this->configuration['stageEnvironmentVariable'] ?? 'APP_STAGE');
        $stage = static::readEnvironmentValue($variableName);
        return $stage !== null && $stage !== '' ? $stage : self::DEFAULT_STAGE;
    }

    public function isProductionStage(): bool
    {
        return $this->getCurrentStage() === (string)($this->configuration['productionStage'] ?? 'prod');
    }

    /** @return string[] lower-case addresses that may receive sends outside production */
    public function getNonProductionAllowedRecipients(): array
    {
        $variableName = (string)($this->configuration['nonProductionAllowedRecipientsEnvironmentVariable'] ?? '');
        $allowedRecipients = [];
        foreach (explode(',', (string)static::readEnvironmentValue($variableName)) as $configuredRecipient) {
            $allowedRecipient = mb_strtolower(trim($configuredRecipient));
            if ($allowedRecipient !== '') {
                $allowedRecipients[] = $allowedRecipient;
            }
        }
        return $allowedRecipients;
    }

    public function getCredentialsKeyEnvironmentVariable(): string
    {
        return (string)($this->configuration['credentialsKeyEnvironmentVariable'] ?? 'AGENT_CONNECTIONS_CREDENTIALS_KEY');
    }

    public function getPublicBaseUrl(): string
    {
        $variableName = (string)($this->configuration['publicBaseUrlEnvironmentVariable'] ?? 'PUBLIC_URL');
        return rtrim((string)static::readEnvironmentValue($variableName), '/');
    }

    public function getSettingsPagePath(): string
    {
        return (string)($this->configuration['settingsPagePath'] ?? '/');
    }

    public function getLimit(string $limitName, int $fallback): int
    {
        return (int)($this->configuration['limits'][$limitName] ?? $fallback);
    }


    /** The value of an environment variable: the process environment first, then the env files; null when unset */
    public static function readEnvironmentValue(string $variableName): ?string
    {
        if ($variableName === '') {
            return null;
        }
        $processValue = getenv($variableName);
        if ($processValue !== false) {
            return (string)$processValue;
        }
        $fileValue = Config::getEnv($variableName);
        if ($fileValue === null || $fileValue === false) {
            return null;
        }
        $fileValue = (string)$fileValue;
        // an unresolved secret-manager reference (a prefix the application names) is no value
        foreach ((array)(Config::get(static::CONFIG_KEY . '.unresolvedSecretReferencePrefixes') ?? []) as $referencePrefix) {
            if ((string)$referencePrefix !== '' && str_starts_with($fileValue, (string)$referencePrefix)) {
                return null;
            }
        }
        return $fileValue;
    }
}
