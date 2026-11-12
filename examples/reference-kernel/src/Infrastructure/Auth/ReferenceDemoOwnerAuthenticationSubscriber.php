<?php

declare(strict_types=1);

namespace AgentsReference\Infrastructure\Auth;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * DEMO ONLY — the account stand-in of the reference application: every request acts as ONE fixed demo owner
 * (`REFERENCE_DEMO_OWNER_ACCOUNT_ID`, default 1), no login, no token. A real application authenticates the caller
 * (DDD's bearer authenticators) and sets the account the same way: {@see AuthService::setAccount()}. The read rights
 * of the conversation and message repositories then confine every read to that account.
 */
class ReferenceDemoOwnerAuthenticationSubscriber implements EventSubscriberInterface
{
    public const string DEMO_OWNER_ACCOUNT_ID_ENV = 'REFERENCE_DEMO_OWNER_ACCOUNT_ID';

    public static function getSubscribedEvents(): array
    {
        // before the controller arguments are resolved, after the router
        return [KernelEvents::REQUEST => ['authenticateDemoOwner', 16]];
    }

    public function authenticateDemoOwner(RequestEvent $requestEvent): void
    {
        if (!$requestEvent->isMainRequest()) {
            return;
        }
        $demoOwnerAccountId = (int)(Config::getEnv(self::DEMO_OWNER_ACCOUNT_ID_ENV) ?: 1);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var Account|null $demoOwnerAccount */
            $demoOwnerAccount = Account::getService()->find($demoOwnerAccountId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        AuthService::instance()->setAccount($demoOwnerAccount);
    }
}
