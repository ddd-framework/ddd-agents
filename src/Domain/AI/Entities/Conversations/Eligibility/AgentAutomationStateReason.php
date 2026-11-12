<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Eligibility;

use DDD\Infrastructure\Services\DDDService;

/**
 * The vocabulary for "why the platform itself stopped an automation": stamped as `stateReason` on a schedule the state
 * sweep transitioned and as `suppressionReason` on a run conversation whose model call, report or mail was refused.
 * String constants (not an enum) because the values are persisted; they are stored data and never change. NULL in
 * either column = the owner's or the platform's own transition (a customer pause, a normal terminal) — the sweep never
 * touches such rows and automatic resumption never re-arms them.
 *
 * The scope below the account is the agent space. An application that has stored other words for it keeps them: it
 * redeclares {@see AGENT_SPACE_SUSPENDED}, {@see AGENT_SPACE_ENDED}, {@see ALL} and {@see RESUMABLE} in a same-path
 * subclass, and the package stamps and validates the values of THAT class
 * ({@see getReasonClassOfTheApplication()}).
 */
class AgentAutomationStateReason
{
    /** The agent space is locked — reversible, resumes by itself when the state clears. */
    public const string AGENT_SPACE_SUSPENDED = 'AGENT_SPACE_SUSPENDED';

    /** The agent space is gone or ended (also: a row names a space that no longer exists) — terminal. */
    public const string AGENT_SPACE_ENDED = 'AGENT_SPACE_ENDED';

    /** The account (or what the application counts as its subscription) is locked — reversible. */
    public const string ACCOUNT_SUSPENDED = 'ACCOUNT_SUSPENDED';

    /** The account is ended — terminal. */
    public const string ACCOUNT_ENDED = 'ACCOUNT_ENDED';

    /** The account was erased under a data-protection request — terminal, nothing ever resumes. */
    public const string ACCOUNT_ERASED = 'ACCOUNT_ERASED';

    /** @var string[] Every reason the platform stamps — the allowed values of both columns ({@see getAllOfTheApplication()}). */
    public const array ALL = [
        self::AGENT_SPACE_SUSPENDED,
        self::AGENT_SPACE_ENDED,
        self::ACCOUNT_SUSPENDED,
        self::ACCOUNT_ENDED,
        self::ACCOUNT_ERASED,
    ];

    /** @var string[] The reasons automatic resumption may clear — a stamped row on a space/account that recovered. */
    public const array RESUMABLE = [self::AGENT_SPACE_SUSPENDED, self::ACCOUNT_SUSPENDED];

    /**
     * The reason class of the running application: its same-path subclass when it has one, else this class.
     *
     * @return class-string<AgentAutomationStateReason>
     */
    public static function getReasonClassOfTheApplication(): string
    {
        /** @var class-string<AgentAutomationStateReason> $reasonClassOfTheApplication */
        $reasonClassOfTheApplication = DDDService::instance()->getContainerServiceClassNameForClass(self::class) ?? self::class;
        return $reasonClassOfTheApplication;
    }

    /**
     * Every reason the running application stamps ({@see ALL} of its reason class).
     *
     * @return string[]
     */
    public static function getAllOfTheApplication(): array
    {
        return (self::getReasonClassOfTheApplication())::ALL;
    }

    /**
     * Whether a stamped reason names a reversible state (the row may be re-armed once the state clears). Pure.
     *
     * @param string|null $stateReason
     * @return bool
     */
    public static function isResumable(?string $stateReason): bool
    {
        return $stateReason !== null && in_array($stateReason, static::RESUMABLE, true);
    }
}
