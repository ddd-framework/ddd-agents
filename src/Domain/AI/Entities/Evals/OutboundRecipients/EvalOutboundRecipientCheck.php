<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\OutboundRecipients;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of {@see \DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService} for ONE outbound send: whether the
 * send happens in eval context, and which of its recipients are no allow-listed test sink. A send is blocked when it
 * is in eval context and names at least one such recipient; the whole send is then refused, never a part of it.
 * Recipients are only ever kept MASKED here — the check is logged and shown to the agent.
 *
 * @phpstan-consistent-constructor
 */
class EvalOutboundRecipientCheck extends ValueObject
{
    /** @var string Eval context: a tool call of a conversation whose tree carries `runSettings.evalRun` is executing */
    public const string EVAL_CONTEXT_REASON_EVAL_RUN = 'evalRun';

    /** @var string Eval context: the send is made on behalf of an account listed in `config/app/AI/evalAccounts.php` */
    public const string EVAL_CONTEXT_REASON_EVAL_ACCOUNT = 'evalAccount';

    /** @var string Eval context: the send is made for a throwaway scope (a scope the application created for a test run) */
    public const string EVAL_CONTEXT_REASON_THROWAWAY_SCOPE = 'throwawayScope';

    /** @var string The channel of the send — one of the CHANNEL_* constants of the guard service */
    public string $channel = '';

    /** @var bool Whether the send happens in eval context */
    public bool $isEvalContext = false;

    /** @var string|null Why the send is in eval context (one of the EVAL_CONTEXT_REASON_* constants); null outside it */
    public ?string $evalContextReason = null;

    /** @var string[] The recipients that are no test sink, masked ({@see maskEmailAddress()}, {@see maskPhoneNumber()}) */
    public array $blockedRecipientsMasked = [];

    /** A send outside eval context: never blocked. */
    public static function forNotEvalContext(string $channel): static
    {
        $evalOutboundRecipientCheck = new static();
        $evalOutboundRecipientCheck->channel = $channel;
        return $evalOutboundRecipientCheck;
    }

    /**
     * A send in eval context; `$blockedRecipientsMasked` empty = every recipient is a test sink.
     *
     * @param string[] $blockedRecipientsMasked
     */
    public static function forEvalContext(string $channel, string $evalContextReason, array $blockedRecipientsMasked): static
    {
        $evalOutboundRecipientCheck = new static();
        $evalOutboundRecipientCheck->channel = $channel;
        $evalOutboundRecipientCheck->isEvalContext = true;
        $evalOutboundRecipientCheck->evalContextReason = $evalContextReason;
        $evalOutboundRecipientCheck->blockedRecipientsMasked = array_values($blockedRecipientsMasked);
        return $evalOutboundRecipientCheck;
    }

    /** Whether the send must not happen. */
    public function isBlocked(): bool
    {
        return $this->isEvalContext && $this->blockedRecipientsMasked !== [];
    }

    /** The sentence the agent reads and the issues channel records: `test run: sending <channel> to <masked> blocked …`. */
    public function buildBlockedNotice(): string
    {
        return 'test run: sending ' . $this->channel . ' to ' . implode(', ', $this->blockedRecipientsMasked)
            . ' blocked — an eval run reaches only the test inboxes and the test phone line, nothing was sent';
    }

    /** `ab***@example.com` — the first two characters of the local part, the domain in full. */
    public static function maskEmailAddress(string $emailAddress): string
    {
        $trimmedEmailAddress = trim($emailAddress);
        $atPosition = strrpos($trimmedEmailAddress, '@');
        if ($atPosition === false) {
            return mb_substr($trimmedEmailAddress, 0, 2) . '***';
        }
        return mb_substr(substr($trimmedEmailAddress, 0, $atPosition), 0, 2) . '***' . substr($trimmedEmailAddress, $atPosition);
    }

    /** `+49***72` — the first three characters and the last two, never the digits between them. */
    public static function maskPhoneNumber(string $phoneNumber): string
    {
        $compactPhoneNumber = preg_replace('/\s+/', '', trim($phoneNumber)) ?? '';
        if (mb_strlen($compactPhoneNumber) <= 5) {
            return '***';
        }
        return mb_substr($compactPhoneNumber, 0, 3) . '***' . mb_substr($compactPhoneNumber, -2);
    }
}
