<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use Throwable;

/**
 * What a population read of purposed runs covers (`findPurposedRuns` / `reviewPurposeCriteria`): the
 * purposes, a creation window of at most {@see MAX_WINDOW_DAYS} days, optional run states and an account / space
 * scope, whether eval runs count, the quiet window of the stall rule and the page. Every bound is applied once, by
 * {@see fromRequest()} — the read service trusts the filter it gets.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationPurposedRunsFilter extends ValueObject
{
    /** The word {@see describeScope()} names the space filter by (an application may name its own term). */
    protected const string AGENT_SPACE_SCOPE_LABEL = 'space';

    /** The window a read covers when the caller names none. */
    public const int DEFAULT_WINDOW_DAYS = 7;

    /** The longest window a read covers: a population read runs on the production database. */
    public const int MAX_WINDOW_DAYS = 31;

    /** The page a list returns when the caller names none, and its maximum (a row carries its open plan items). */
    public const int DEFAULT_TOP = 25;

    public const int MAX_TOP = 25;

    /** How long an IDLE run with an open plan must be quiet before the stall rule names it (default, minimum). */
    public const int DEFAULT_QUIET_MINUTES = 60;

    public const int MIN_QUIET_MINUTES = 5;

    /** @var string[] The purposes read; empty = every registered purpose. */
    public array $purposeNames = [];

    /** The window's start (creation moment of the run, inclusive). */
    public ?DateTime $createdFrom = null;

    /** The window's end (exclusive). */
    public ?DateTime $createdTo = null;

    /** @var string[] Only runs in these run states; empty = every state. */
    public array $runStates = [];

    public ?int $accountId = null;

    /**
     * @var int|null Only runs of the space with this id. null = NO filter by space: the runs of every space and the
     * runs at account level (an admin read across spaces) — never "only the runs without a space".
     */
    public ?int $agentSpaceId = null;

    /** Only runs the stall rule names ({@see AIConversationPurposedRunStallReason}). */
    public bool $onlyStalled = false;

    /** Also eval runs (`runSettings.evalRun`); by default they are left out — they are tests, not customers. */
    public bool $includeEvalRuns = false;

    /** The quiet window of the stall rule's open-plan reasons, minutes. */
    public int $quietMinutes = self::DEFAULT_QUIET_MINUTES;

    public int $top = self::DEFAULT_TOP;

    public int $skip = 0;

    /**
     * The bounded filter of one request. The window ends at `$createdTo` (default now) and starts `$windowDays` before
     * it, or at `$createdFrom` when given. A window longer than {@see MAX_WINDOW_DAYS} or in the wrong order is
     * refused, not cut — a silently shortened window would answer a different question.
     *
     * @param string[] $purposeNames
     * @param string[] $runStates
     * @param int|null $accountId only runs of this account; null = no filter by account
     * @param int|null $agentSpaceId only runs of the space with this id; null = NO filter by space (every space and
     *                               the account-level runs)
     * @throws BadRequestException
     */
    public static function fromRequest(
        array $purposeNames,
        ?string $createdFrom,
        ?string $createdTo,
        ?int $windowDays,
        array $runStates = [],
        ?int $accountId = null,
        ?int $agentSpaceId = null,
        bool $onlyStalled = false,
        bool $includeEvalRuns = false,
        ?int $quietMinutes = null,
        ?int $top = null,
        ?int $skip = null,
        ?DateTime $now = null
    ): static {
        $filter = new static();
        foreach ($purposeNames as $purposeName) {
            $purposeName = trim((string)$purposeName);
            if ($purposeName !== '' && !in_array($purposeName, $filter->purposeNames, true)) {
                $filter->purposeNames[] = $purposeName;
            }
        }
        $filter->createdTo = self::parseMoment($createdTo, 'createdTo') ?? ($now ?? new DateTime());
        $windowDays ??= self::DEFAULT_WINDOW_DAYS;
        if ($windowDays < 1 || $windowDays > self::MAX_WINDOW_DAYS) {
            throw new BadRequestException('windowDays must lie between 1 and ' . self::MAX_WINDOW_DAYS . '.');
        }
        $filter->createdFrom = self::parseMoment($createdFrom, 'createdFrom')
            ?? (clone $filter->createdTo)->modify('-' . $windowDays . ' days');
        if ($filter->createdFrom >= $filter->createdTo) {
            throw new BadRequestException('createdFrom must lie before createdTo.');
        }
        if ($filter->createdTo->getTimestamp() - $filter->createdFrom->getTimestamp() > self::MAX_WINDOW_DAYS * 86400) {
            throw new BadRequestException('The window may cover at most ' . self::MAX_WINDOW_DAYS . ' days.');
        }
        $knownRunStates = [AIConversationRunState::IDLE, ...AIConversationRunState::NON_TERMINAL, ...AIConversationRunState::TERMINAL];
        foreach ($runStates as $runState) {
            $runState = strtoupper(trim((string)$runState));
            if ($runState === '') {
                continue;
            }
            if (!in_array($runState, $knownRunStates, true)) {
                throw new BadRequestException("Unknown run state '{$runState}'.");
            }
            if (!in_array($runState, $filter->runStates, true)) {
                $filter->runStates[] = $runState;
            }
        }
        $filter->accountId = $accountId;
        $filter->agentSpaceId = $agentSpaceId;
        $filter->onlyStalled = $onlyStalled;
        $filter->includeEvalRuns = $includeEvalRuns;
        $filter->quietMinutes = max(self::MIN_QUIET_MINUTES, $quietMinutes ?? self::DEFAULT_QUIET_MINUTES);
        $filter->top = max(1, min(self::MAX_TOP, $top ?? self::DEFAULT_TOP));
        $filter->skip = max(0, $skip ?? 0);
        return $filter;
    }

    /** One line naming the scope a result covers — every result echoes it. */
    public function describeScope(): string
    {
        $scopeParts = [
            'purposes ' . ($this->purposeNames !== [] ? implode(', ', $this->purposeNames) : 'all'),
            'created ' . $this->createdFrom?->format('Y-m-d H:i') . ' to ' . $this->createdTo?->format('Y-m-d H:i') . ' (server time)',
        ];
        if ($this->runStates !== []) {
            $scopeParts[] = 'run states ' . implode(', ', $this->runStates);
        }
        if ($this->accountId !== null) {
            $scopeParts[] = 'account ' . $this->accountId;
        }
        if ($this->agentSpaceId !== null) {
            $scopeParts[] = static::AGENT_SPACE_SCOPE_LABEL . ' ' . $this->agentSpaceId;
        }
        if ($this->onlyStalled) {
            $scopeParts[] = 'stalled only';
        }
        $scopeParts[] = $this->includeEvalRuns ? 'eval runs included' : 'eval runs left out';
        $scopeParts[] = 'quiet window ' . $this->quietMinutes . ' min';
        return implode('; ', $scopeParts);
    }

    /** @throws BadRequestException */
    protected static function parseMoment(?string $moment, string $parameterName): ?DateTime
    {
        $moment = trim((string)$moment);
        if ($moment === '') {
            return null;
        }
        try {
            return new DateTime($moment);
        } catch (Throwable) {
            throw new BadRequestException("{$parameterName} is no date or moment: '{$moment}'.");
        }
    }
}
