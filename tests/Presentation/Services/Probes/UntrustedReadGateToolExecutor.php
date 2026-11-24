<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolContentTrust;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\OwnerConsents\OwnerConsentsServiceThatAnswersAGivenReservation;

/** The executor with the untrusted tool names and the configured mode of a test. */
class UntrustedReadGateToolExecutor extends MCPToolExecutorService
{
    /** @var string[] */
    public array $untrustedToolNames = ['probeReadForeignText'];

    public string $writeAfterUntrustedReadMode = MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OWNER_CONSENT;

    public string $unseenUrlAfterUntrustedReadMode = MCPToolContentTrust::UNSEEN_URL_AFTER_UNTRUSTED_READ_REFUSE;

    /** The reservation the consent step answers; null = the package's own service */
    public ?OwnerConsentReservation $givenOwnerConsentReservation = null;

    protected function getOwnerConsentsService(): OwnerConsentsService
    {
        if ($this->givenOwnerConsentReservation === null) {
            return parent::getOwnerConsentsService();
        }
        $ownerConsentsService = new OwnerConsentsServiceThatAnswersAGivenReservation();
        $ownerConsentsService->givenReservation = $this->givenOwnerConsentReservation;
        return $ownerConsentsService;
    }

    protected function isUntrustedReadToolName(string $toolName): bool
    {
        return in_array($toolName, $this->untrustedToolNames, true);
    }

    protected function getWriteAfterUntrustedReadMode(): string
    {
        return $this->writeAfterUntrustedReadMode;
    }

    protected function getUnseenUrlAfterUntrustedReadMode(): string
    {
        return $this->unseenUrlAfterUntrustedReadMode;
    }
}
