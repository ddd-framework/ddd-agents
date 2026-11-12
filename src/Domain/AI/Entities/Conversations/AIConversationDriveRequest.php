<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * What one drive of a conversation was asked for, and the decisions that follow from the request alone
 * (formerly inline in `AIConversationAgentService::driveConversation()`): the scope, the
 * owner's text and the invisible start signal as they will be stored, and whose budget lift the drive's work runs under.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationDriveRequest extends ValueObject
{
    /**
     * Hard cap on a conversation-START signal's text — deliberately larger than
     * {@see AIConversationMessage::MAX_SIGNAL_TEXT_CHARS}: a dashboard widget hands over its whole data/state as
     * invisible start context. Still bounded, because the SIGNAL sits in the live message tail and is re-billed into the
     * model context EVERY turn until compaction covers it (~4k tokens at this cap).
     */
    public const int MAX_START_SIGNAL_TEXT_CHARS = 16000;

    /** The ADMIN scope ({@see AIConversationDriveScope}); false = the CUSTOMER scope. */
    public bool $isAdmin = false;

    /** The owner's message, sanitized ({@see AIConversationMessage::sanitizeUserText()}); may be empty on a signal-only start. */
    public string $sanitizedUserText = '';

    /** The invisible START context, sanitized and capped to {@see MAX_START_SIGNAL_TEXT_CHARS}; null when none was sent. */
    public ?string $sanitizedStartSignalText = null;

    /** Whether the drive's work runs without the AI-budget check — persisted on the conversation for the async workers. */
    public bool $effectiveBudgetBypass = false;

    /** Why the drive's own work runs without the cap; null = the cap is enforced. Set by {@see decideBudgetBypass()}. */
    public ?AIBudgetBypassGrant $driveBudgetBypassGrant = null;

    public static function buildFor(string $scope, string $message, ?string $startSignalText): static
    {
        $driveRequest = new static();
        $driveRequest->isAdmin = $scope === AIConversationDriveScope::ADMIN;
        $driveRequest->sanitizedUserText = AIConversationMessage::sanitizeUserText($message);
        $driveRequest->sanitizedStartSignalText = $startSignalText !== null
            ? mb_substr(AIConversationMessage::sanitizeUserText($startSignalText), 0, self::MAX_START_SIGNAL_TEXT_CHARS)
            : null;
        return $driveRequest;
    }

    /**
     * CUSTOMER: reject an empty / whitespace-only submit up front — BEFORE any conversation is resolved or created — so a
     * blank submit can't persist a no-op USER message and burn a billable agent turn. A non-empty START signal alone
     * suffices (signal-only start: the agent's first turn answers the invisible context and the customer sees the agent
     * speak first). The ADMIN scope keeps its behaviour: the admin console always sends real text.
     *
     * @throws BadRequestException
     */
    public function assertTheCustomerSentSomethingToDrive(): void
    {
        if (!$this->isAdmin && !$this->hasOwnerText() && !$this->hasStartSignalText()) {
            throw new BadRequestException('A non-empty message is required to drive a conversation.');
        }
    }

    /** Whether the owner's message carries text (a signal-only start has none). */
    public function hasOwnerText(): bool
    {
        return trim($this->sanitizedUserText) !== '';
    }

    /** Whether an invisible START signal with text was sent. */
    public function hasStartSignalText(): bool
    {
        return trim($this->sanitizedStartSignalText ?? '') !== '';
    }

    /**
     * Decide whether the drive's work runs without the cap, and why. Three inputs:
     * - an admin OPERATING the session (the ADMIN scope, a full-access JWT admin, or an admin impersonating the customer)
     *   asked for it, or the conversation already carries the admin's earlier lift;
     * - a CONSOLE `--ignore-budget` run (the process grant, reason CONSOLE_OPTION — the only grant a console command
     *   sets): it must reach the conversation's persisted flag, so the ASYNC workers (turn / curate / compaction /
     *   summary) inherit it — the process grant does NOT cross the Messenger boundary. It is unreachable via
     *   HTTP / Messenger, so a real CUSTOMER turn still resolves to false here (a customer never inherits a stray lift);
     * - the lift of the work this drive runs INSIDE — a tool of a budgetBypass turn starting another run (a schedule
     *   run, a run of the application). System maintenance (the finalize chain) lifts only its own work — it never makes a
     *   spawned conversation exempt.
     */
    public function decideBudgetBypass(
        bool $adminIsOperatingSession,
        ?bool $budgetBypass,
        bool $existingConversationBudgetBypass,
        ?AIBudgetBypassGrant $budgetBypassGrantForTheProcess,
        ?AIBudgetBypassGrant $budgetBypassGrantOfSurroundingWork
    ): void {
        $consoleIgnoreBudget = $budgetBypassGrantForTheProcess?->reason === AIBudgetBypassGrant::REASON_CONSOLE_OPTION;
        if ($budgetBypassGrantOfSurroundingWork?->reason === AIBudgetBypassGrant::REASON_SYSTEM_MAINTENANCE) {
            $budgetBypassGrantOfSurroundingWork = null;
        }
        $adminBudgetBypass = $adminIsOperatingSession && ($budgetBypass ?? $existingConversationBudgetBypass);
        $this->effectiveBudgetBypass = $adminBudgetBypass || $consoleIgnoreBudget || $budgetBypassGrantOfSurroundingWork !== null;
        $this->driveBudgetBypassGrant = match (true) {
            $adminBudgetBypass => AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_ADMIN_REQUEST),
            $consoleIgnoreBudget => AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONSOLE_OPTION),
            default => $budgetBypassGrantOfSurroundingWork,
        };
    }
}
