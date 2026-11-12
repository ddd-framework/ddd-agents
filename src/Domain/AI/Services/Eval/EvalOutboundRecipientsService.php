<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\OutboundRecipients\EvalOutboundRecipientCheck;
use DDD\Domain\AI\Entities\Evals\OutboundRecipients\EvalRunOutboundScope;
use DDD\Domain\AI\Exceptions\Evals\EvalOutboundRecipientBlockedException;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use Psr\Log\LogLevel;
use Throwable;

/**
 * The ONE guard that keeps an eval run from reaching a real person or business. Every
 * outbound channel of the application asks it at its single send point (e-mail, SMS, the outbound phone call, the
 * in-app ring, push); a send a worker makes later is refused where it is QUEUED, while the eval context is still known;
 * a webhook worker re-checks the conversation's own eval marker.
 *
 * EVAL CONTEXT is any of:
 *  - a tool call of a conversation whose tree carries `runSettings.evalRun` is executing in this process
 *    ({@see EvalRunOutboundScope}, opened by `AgentToolCallExecutionService::executeToolCall()`), or the caller
 *    passes such a conversation itself;
 *  - the send is made on behalf of an eval account ({@see AgentEvalOutboundGuardHooksService::findConfiguredEvalAccountIds()})
 *    — the account of the send, the account owning its scope, or the authenticated account;
 *  - the send is made for a throwaway scope ({@see AgentEvalOutboundGuardHooksService::isThrowawayScope()}).
 *
 * In eval context every recipient must be an allow-listed test sink (config `AI.evalOutboundRecipients`: test inboxes,
 * test domains, test phone numbers, plus the application's additional numbers); a send naming anything else is
 * BLOCKED — nothing leaves —, recorded on the issues channel and answered with
 * {@see EvalOutboundRecipientCheck::buildBlockedNotice()}. Push, the in-app ring and webhooks have no test sink: there
 * every eval send is blocked. Outside eval context nothing changes. The scope a caller passes is the application's
 * scope entity; the core reads only its id and asks the hooks for the rest.
 */
class EvalOutboundRecipientsService extends Service
{
    public const string CONFIG_KEY = 'AI.evalOutboundRecipients';

    public const string CHANNEL_EMAIL = 'e-mail';

    public const string CHANNEL_SMS = 'SMS';

    public const string CHANNEL_PHONE_CALL = 'phone call';

    public const string CHANNEL_APP_CALL_RING = 'in-app call ring';

    public const string CHANNEL_PUSH_NOTIFICATION = 'push notification';

    public const string CHANNEL_A2A_WEBHOOK = 'A2A push webhook';

    /** @var string The masked recipient of a device channel (push, in-app ring) — a device has no address to show */
    public const string DEVICE_RECIPIENT_LABEL = 'the account\'s registered devices';

    /**
     * Opens the eval-run hold for one tool call of `$conversation` — when its tree carries `runSettings.evalRun`;
     * otherwise a hold that changes nothing. The caller closes it in a `finally`.
     */
    public function openEvalRunScopeForConversation(AIConversation $conversation): EvalRunOutboundScope
    {
        return $this->isConversationOfAnEvalRunTree($conversation)
            ? EvalRunOutboundScope::openedForEvalRun()
            : EvalRunOutboundScope::notOpened();
    }

    /** Whether the conversation, or the root of its delegation tree, carries `runSettings.evalRun`. */
    public function isConversationOfAnEvalRunTree(AIConversation $conversation): bool
    {
        try {
            /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
            $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
            return $aIConversationOwnerReportDeliveryService->belongsToAnEvalRunTree($conversation);
        } catch (Throwable) {
            // the root could not be read: the conversation's own marker still counts
            return (isset($conversation->runSettings) ? $conversation->runSettings->evalRun : null) === true;
        }
    }

    /**
     * Why a send is in eval context, or null when it is not ({@see EvalOutboundRecipientCheck}::EVAL_CONTEXT_REASON_*).
     *
     * @param int|null $accountId The account the send is made on behalf of
     * @param Entity|null $scope The scope the send is made for (the application's scope entity, opaque to the core)
     * @param bool $isEvalRunConversation The caller holds a conversation of an eval run tree
     */
    public function findEvalContextReason(?int $accountId, ?Entity $scope, bool $isEvalRunConversation = false): ?string
    {
        if ($isEvalRunConversation || EvalRunOutboundScope::isAnyScopeOpen()) {
            return EvalOutboundRecipientCheck::EVAL_CONTEXT_REASON_EVAL_RUN;
        }
        $evalAccountIds = $this->findConfiguredEvalAccountIds();
        foreach ([$accountId, $this->findScopeAccountId($scope), $this->findAuthAccountId()] as $candidateAccountId) {
            if ($candidateAccountId !== null && in_array($candidateAccountId, $evalAccountIds, true)) {
                return EvalOutboundRecipientCheck::EVAL_CONTEXT_REASON_EVAL_ACCOUNT;
            }
        }
        if ($this->isThrowawayScope($scope)) {
            return EvalOutboundRecipientCheck::EVAL_CONTEXT_REASON_THROWAWAY_SCOPE;
        }
        return null;
    }

    /** Whether a send is in eval context ({@see findEvalContextReason()}). */
    public function isEvalContext(?int $accountId, ?Entity $scope, bool $isEvalRunConversation = false): bool
    {
        return $this->findEvalContextReason($accountId, $scope, $isEvalRunConversation) !== null;
    }

    /**
     * Checks the recipients of one e-mail; a blocked check is already recorded on the issues channel.
     *
     * @param string[] $emailAddresses Every recipient of the mail (to, cc, bcc)
     */
    public function checkEmailRecipients(array $emailAddresses, ?int $accountId, ?Entity $scope, bool $isEvalRunConversation = false): EvalOutboundRecipientCheck
    {
        $evalContextReason = $this->findEvalContextReason($accountId, $scope, $isEvalRunConversation);
        if ($evalContextReason === null) {
            return EvalOutboundRecipientCheck::forNotEvalContext(self::CHANNEL_EMAIL);
        }
        $blockedRecipientsMasked = [];
        foreach ($emailAddresses as $emailAddress) {
            // one recipient string may carry a whole list ("a@x, b@y"): every address in it is checked on its own,
            // and a string that names no address at all is no test inbox either
            $listedEmailAddresses = $this->splitRecipientList($emailAddress);
            if ($listedEmailAddresses === []) {
                $blockedRecipientsMasked[] = EvalOutboundRecipientCheck::maskEmailAddress($emailAddress);
            }
            foreach ($listedEmailAddresses as $listedEmailAddress) {
                if (!$this->isAllowListedTestEmailAddress($listedEmailAddress)) {
                    $blockedRecipientsMasked[] = EvalOutboundRecipientCheck::maskEmailAddress($listedEmailAddress);
                }
            }
        }
        return $this->recordIfBlocked(
            EvalOutboundRecipientCheck::forEvalContext(self::CHANNEL_EMAIL, $evalContextReason, $blockedRecipientsMasked),
            $accountId,
            $scope
        );
    }

    /**
     * Checks the recipient number of one SMS or phone call; a blocked check is already recorded on the issues channel.
     *
     * @param string $channel {@see CHANNEL_SMS} or {@see CHANNEL_PHONE_CALL}
     */
    public function checkPhoneRecipient(string $channel, string $phoneNumber, ?int $accountId, ?Entity $scope, bool $isEvalRunConversation = false): EvalOutboundRecipientCheck
    {
        $evalContextReason = $this->findEvalContextReason($accountId, $scope, $isEvalRunConversation);
        if ($evalContextReason === null) {
            return EvalOutboundRecipientCheck::forNotEvalContext($channel);
        }
        $blockedRecipientsMasked = $this->isAllowListedTestPhoneNumber($phoneNumber)
            ? []
            : [EvalOutboundRecipientCheck::maskPhoneNumber($phoneNumber)];
        return $this->recordIfBlocked(
            EvalOutboundRecipientCheck::forEvalContext($channel, $evalContextReason, $blockedRecipientsMasked),
            $accountId,
            $scope
        );
    }

    /**
     * Checks a send to the account's devices (push, in-app ring): a device belongs to a real person and has no test
     * sink, so in eval context it is always blocked (and recorded on the issues channel).
     *
     * @param string $channel {@see CHANNEL_PUSH_NOTIFICATION} or {@see CHANNEL_APP_CALL_RING}
     */
    public function checkDeviceRecipient(string $channel, ?int $accountId, ?Entity $scope, bool $isEvalRunConversation = false): EvalOutboundRecipientCheck
    {
        $evalContextReason = $this->findEvalContextReason($accountId, $scope, $isEvalRunConversation);
        if ($evalContextReason === null) {
            return EvalOutboundRecipientCheck::forNotEvalContext($channel);
        }
        return $this->recordIfBlocked(
            EvalOutboundRecipientCheck::forEvalContext($channel, $evalContextReason, [self::DEVICE_RECIPIENT_LABEL]),
            $accountId,
            $scope
        );
    }

    /**
     * Checks a POST to an external agent's webhook (the A2A push notification of a task): an external endpoint has no
     * test sink, so in eval context it is always blocked (and recorded on the issues channel). Only the webhook's host
     * is shown.
     */
    public function checkWebhookRecipient(string $webhookUrl, ?int $accountId, ?Entity $scope, bool $isEvalRunConversation = false): EvalOutboundRecipientCheck
    {
        $evalContextReason = $this->findEvalContextReason($accountId, $scope, $isEvalRunConversation);
        if ($evalContextReason === null) {
            return EvalOutboundRecipientCheck::forNotEvalContext(self::CHANNEL_A2A_WEBHOOK);
        }
        $webhookHost = parse_url($webhookUrl, PHP_URL_HOST);
        return $this->recordIfBlocked(
            EvalOutboundRecipientCheck::forEvalContext(
                self::CHANNEL_A2A_WEBHOOK,
                $evalContextReason,
                [is_string($webhookHost) && $webhookHost !== '' ? $webhookHost : 'the webhook']
            ),
            $accountId,
            $scope
        );
    }

    /**
     * Throws when the check is blocked — the form for a channel whose caller reports the outcome to the agent.
     *
     * @throws EvalOutboundRecipientBlockedException
     */
    public function throwIfBlocked(EvalOutboundRecipientCheck $evalOutboundRecipientCheck): void
    {
        if ($evalOutboundRecipientCheck->isBlocked()) {
            throw new EvalOutboundRecipientBlockedException($evalOutboundRecipientCheck->buildBlockedNotice());
        }
    }

    /**
     * Whether the address is a test inbox: ONE well-formed address (`filter_var` FILTER_VALIDATE_EMAIL — a list, a
     * display name, a header fragment or any other malformed string is none) whose domain (after the `@`) is an
     * allow-listed one.
     */
    public function isAllowListedTestEmailAddress(string $emailAddress): bool
    {
        $normalizedEmailAddress = mb_strtolower(trim($emailAddress));
        if (filter_var($normalizedEmailAddress, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $atPosition = strrpos($normalizedEmailAddress, '@');
        if ($atPosition === false || $atPosition === 0) {
            return false;
        }
        foreach ($this->findAllowedEmailAddresses() as $allowedEmailAddress) {
            if ($normalizedEmailAddress === mb_strtolower(trim($allowedEmailAddress))) {
                return true;
            }
        }
        $emailDomain = substr($normalizedEmailAddress, $atPosition + 1);
        foreach ($this->findAllowedEmailDomains() as $allowedEmailDomain) {
            if ($emailDomain === mb_strtolower(trim($allowedEmailDomain))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The addresses one recipient string names — split at commas, semicolons and whitespace (line breaks included),
     * empty parts dropped. Each part is checked on its own; a malformed part stays in the list and is blocked there.
     *
     * @return string[]
     */
    protected function splitRecipientList(string $recipientString): array
    {
        $listedEmailAddresses = preg_split('/[\s,;]+/u', trim($recipientString)) ?: [];
        return array_values(array_filter($listedEmailAddresses, static fn (string $listedEmailAddress): bool => $listedEmailAddress !== ''));
    }

    /** Whether the number is a test number: the shared test line or an allow-listed one (compared normalized). */
    public function isAllowListedTestPhoneNumber(string $phoneNumber): bool
    {
        $normalizedPhoneNumber = $this->normalizePhoneNumber($phoneNumber);
        if ($normalizedPhoneNumber === '') {
            return false;
        }
        foreach ($this->findAllowedPhoneNumbers() as $allowedPhoneNumber) {
            if ($this->normalizePhoneNumber($allowedPhoneNumber) === $normalizedPhoneNumber) {
                return true;
            }
        }
        return false;
    }

    /** A blocked check goes to the issues channel (masked recipients only); every check is handed back unchanged. */
    protected function recordIfBlocked(EvalOutboundRecipientCheck $evalOutboundRecipientCheck, ?int $accountId, ?Entity $scope): EvalOutboundRecipientCheck
    {
        if (!$evalOutboundRecipientCheck->isBlocked()) {
            return $evalOutboundRecipientCheck;
        }
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable(
                new EvalOutboundRecipientBlockedException($evalOutboundRecipientCheck->buildBlockedNotice()),
                LogLevel::WARNING,
                $this->buildBlockedCheckLogFields($evalOutboundRecipientCheck, $accountId, $scope),
                section: IssuesLogService::LOG_MESSAGE_SECTION_CLI
            );
        } catch (Throwable) {
            // the block itself never depends on the log
        }
        return $evalOutboundRecipientCheck;
    }

    /**
     * The fields a blocked check is recorded with on the issues channel (masked recipients only). The entity the
     * send was made for is named by its id alone; an application adds the fields of its own entity in a subclass.
     *
     * @param EvalOutboundRecipientCheck $evalOutboundRecipientCheck
     * @param int|null $accountId
     * @param Entity|null $scope
     * @return array<string, mixed>
     */
    protected function buildBlockedCheckLogFields(EvalOutboundRecipientCheck $evalOutboundRecipientCheck, ?int $accountId, ?Entity $scope): array
    {
        return [
            'evalOutbound.channel' => $evalOutboundRecipientCheck->channel,
            'evalOutbound.reason' => $evalOutboundRecipientCheck->evalContextReason,
            'evalOutbound.blockedRecipients' => implode(', ', $evalOutboundRecipientCheck->blockedRecipientsMasked),
            'accountId' => $accountId,
            'evalOutbound.scopeEntityId' => isset($scope->id) ? (int)$scope->id : null,
        ];
    }

    /** @return int[] The configured eval accounts — the override point of the container-less tests. */
    protected function findConfiguredEvalAccountIds(): array
    {
        return $this->getEvalOutboundGuardHooksService()->findConfiguredEvalAccountIds();
    }

    /** The authenticated account's id, null without one — the override point of the container-less tests. */
    protected function findAuthAccountId(): ?int
    {
        try {
            $authAccount = AuthService::instance()->getAccount();
        } catch (Throwable) {
            return null;
        }
        return isset($authAccount->id) ? (int)$authAccount->id : null;
    }

    /** The account owning the scope (lazy reads on purpose), null when it cannot be read. */
    protected function findScopeAccountId(?Entity $scope): ?int
    {
        if ($scope === null) {
            return null;
        }
        try {
            return $this->getEvalOutboundGuardHooksService()->findScopeAccountId($scope);
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether the scope belongs to a throwaway set-up of the application (marked for deletion for life). */
    protected function isThrowawayScope(?Entity $scope): bool
    {
        if ($scope === null) {
            return false;
        }
        try {
            return $this->getEvalOutboundGuardHooksService()->isThrowawayScope($scope);
        } catch (Throwable) {
            return false;
        }
    }

    /** The application's part of the guard ({@see AgentEvalOutboundGuardHooksService}), resolved through the container. */
    protected function getEvalOutboundGuardHooksService(): AgentEvalOutboundGuardHooksService
    {
        /** @var AgentEvalOutboundGuardHooksService $agentEvalOutboundGuardHooksService */
        $agentEvalOutboundGuardHooksService = DDDService::instance()->getService(AgentEvalOutboundGuardHooksService::class);
        return $agentEvalOutboundGuardHooksService;
    }

    /** @return string[] */
    protected function findAllowedEmailDomains(): array
    {
        $evalOutboundRecipientsConfig = Config::get(self::CONFIG_KEY);
        $allowedEmailDomains = is_array($evalOutboundRecipientsConfig) ? ($evalOutboundRecipientsConfig['allowedEmailDomains'] ?? []) : [];
        return array_values(array_filter(is_array($allowedEmailDomains) ? $allowedEmailDomains : [], 'is_string'));
    }

    /** @return string[] single inboxes allowed beside the test domains (the owner's own inbox to read eval reports) */
    protected function findAllowedEmailAddresses(): array
    {
        $evalOutboundRecipientsConfig = Config::get(self::CONFIG_KEY);
        $allowedEmailAddresses = is_array($evalOutboundRecipientsConfig) ? ($evalOutboundRecipientsConfig['allowedEmailAddresses'] ?? []) : [];
        return array_values(array_filter(is_array($allowedEmailAddresses) ? $allowedEmailAddresses : [], 'is_string'));
    }

    /** @return string[] the configured test numbers plus the application's additional ones (a shared test line) */
    protected function findAllowedPhoneNumbers(): array
    {
        $evalOutboundRecipientsConfig = Config::get(self::CONFIG_KEY);
        $allowedPhoneNumbers = is_array($evalOutboundRecipientsConfig) ? ($evalOutboundRecipientsConfig['allowedPhoneNumbersE164'] ?? []) : [];
        $allowedPhoneNumbers = array_values(array_filter(is_array($allowedPhoneNumbers) ? $allowedPhoneNumbers : [], 'is_string'));
        foreach ($this->getEvalOutboundGuardHooksService()->findAdditionalAllowedPhoneNumbers() as $additionalAllowedPhoneNumber) {
            if (is_string($additionalAllowedPhoneNumber) && trim($additionalAllowedPhoneNumber) !== '') {
                $allowedPhoneNumbers[] = $additionalAllowedPhoneNumber;
            }
        }
        return $allowedPhoneNumbers;
    }

    protected function normalizePhoneNumber(string $phoneNumber): string
    {
        try {
            return $this->getEvalOutboundGuardHooksService()->normalizePhoneNumber($phoneNumber);
        } catch (Throwable) {
            return preg_replace('/[^0-9+]/', '', $phoneNumber) ?? '';
        }
    }
}
