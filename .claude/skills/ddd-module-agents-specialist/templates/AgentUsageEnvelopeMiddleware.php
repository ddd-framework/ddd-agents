<?php
// placeholder {{NAMESPACE}} sample: App\Symfony\Messenger\Middleware

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Infrastructure\Services\DDDService;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Host duty (workers): opens the usage envelope a CONSUMED message declares with `WithAIEnvelope` around its handler.
 *
 * The agent's messages declare their envelope scope on the class; neither the framework nor ddd-ai nor the package
 * ships the middleware that reads it. Without it the compaction, finalize, summary, scorecard, embedding, screening and
 * activity-line handlers run their model calls outside any envelope (the turn handler opens a fallback envelope of its
 * own): no call is attributed to an envelope, and the handlers' budget lifts (`grantBudgetBypassIfAnEnvelopeIsOpen()`)
 * do nothing, so a host budget gate refuses that maintenance work for an account over budget. The calls throw only when
 * the host's own model-call gate requires an envelope. Only a consumed message gets an envelope: at dispatch time the
 * handler has not run.
 *
 * Register it on the bus that consumes the agent's transports (see the transport block template).
 */
class AgentUsageEnvelopeMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $withAIEnvelope = $this->findWithAIEnvelopeOfMessage($message);
        if ($withAIEnvelope === null || $withAIEnvelope->disabled || $withAIEnvelope->scope === '' || !$envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }
        $accountId = $message instanceof AppMessage ? ($message->accountId ?? null) : null;
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        // the opening writes a usage row; the handler has not restored the message's account yet
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $usageEnvelopeContext = $aIUsageEnvelopesService->openEnvelope(
                $accountId,
                $withAIEnvelope->scope,
                $withAIEnvelope->initiatedBy ?? AIUsageInitiator::MESSENGER
            );
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $aIUsageEnvelopesService->closeEnvelope($usageEnvelopeContext);
        }
    }

    protected function findWithAIEnvelopeOfMessage(object $message): ?WithAIEnvelope
    {
        $attributes = (new ReflectionClass($message))->getAttributes(WithAIEnvelope::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return null;
        }
        /** @var WithAIEnvelope $withAIEnvelope */
        $withAIEnvelope = $attributes[0]->newInstance();
        return $withAIEnvelope;
    }
}
