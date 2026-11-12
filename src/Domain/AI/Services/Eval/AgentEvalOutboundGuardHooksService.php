<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Services\Service;

/**
 * The application's part of the eval outbound guard ({@see EvalOutboundRecipientsService}): which accounts are eval
 * accounts, which account owns a scope, whether a scope is a throwaway set-up, how a phone number is normalized and
 * which further numbers are test sinks. The defaults know no eval account, no scope owner, no throwaway scope, normalize
 * a number to its digits and `+`, and add no number — so without an application the guard blocks only inside an
 * eval run tree. An application puts a same-path subclass under its own root namespace; the core resolves this service
 * only through `DDDService::instance()->getService(AgentEvalOutboundGuardHooksService::class)`.
 */
class AgentEvalOutboundGuardHooksService extends Service
{
    /**
     * The accounts whose sends are always in eval context. The default: none.
     *
     * @return int[]
     */
    public function findConfiguredEvalAccountIds(): array
    {
        return [];
    }

    /**
     * The id of the account owning the scope a send is made for, null when unknown. The default: unknown.
     *
     * @param Entity $scope The application's scope entity the caller passed
     */
    public function findScopeAccountId(Entity $scope): ?int
    {
        return null;
    }

    /**
     * Whether the scope belongs to a throwaway set-up (made for a test and marked for deletion). The default: never.
     *
     * @param Entity $scope The application's scope entity the caller passed
     */
    public function isThrowawayScope(Entity $scope): bool
    {
        return false;
    }

    /** The phone number in the form the allow-list is compared in. The default: digits and `+` only. */
    public function normalizePhoneNumber(string $phoneNumber): string
    {
        return preg_replace('/[^0-9+]/', '', $phoneNumber) ?? '';
    }

    /**
     * Test numbers beside the configured `allowedPhoneNumbersE164` (a shared test line of the application). The
     * default: none.
     *
     * @return string[]
     */
    public function findAdditionalAllowedPhoneNumbers(): array
    {
        return [];
    }
}
