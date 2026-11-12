<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDecisionResult;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentUse;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogueLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Widgets\OwnerQuestionConversationWidget;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\Conversations\OwnerConsentMissingException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentOwnerConsentDecision;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonValueKind;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The owner-consent check: the one place a tool asks whether the owner explicitly agreed to the step
 * it is about to take. FAIL-CLOSED — every path that is not a decision model's answer above the threshold is NOT a
 * consent, the site `off` included (after the switch there is no deterministic fallback, and the row kill switch
 * does not lift the gate). The order: no agent provenance → ungated (HTTP MCP and the harness's direct calls); a delegation child → refused (only the root can ask the owner); the site mode; a live owner; the
 * dialogue; the decision (through the one runner, {@see AgentDecisionCallsService::runDecisionBatch()}). Every checked
 * call writes one turn event (site `ownerConsent`).
 *
 * Only the site mode `on` decides. Obtained via `DDDService::instance()->getService(OwnerConsentsService::class)`.
 */
class OwnerConsentsService extends Service
{
    /** @var string The working-state key of the records by `<tool>:<owner row>` ({@see OwnerConsentUse::getKey()}) */
    protected const string USES_KEY = 'ownerConsentUses';

    /** @var string The working-state key of the tools' windows by tool name */
    protected const string WINDOWS_KEY = 'ownerConsentWindows';

    /** @var string The working-state key of the tools' refusal floors by tool name */
    protected const string REFUSED_THROUGH_KEY = 'ownerConsentRefusedThrough';

    /** @var string The SQL of the stored working state in the statements of this service */
    protected const string WORKING_STATE_COLUMN_SQL = '`agentWorkingState`';

    /**
     * The owner-consent step of a tool's write in one call: the tool's window and refusal floor are
     * read from the row, the decision model judges the owner rows after them, a decided refusal raises the floor,
     * and a consent is reserved for this call in one guarded statement. UNGATED (no provenance, a harness call) and
     * RESERVED allow the write; NOT_RESERVED does not — its {@see OwnerConsentReservation::buildModelFacingText()} is
     * what the model reads. Any failure in here is NOT_RESERVED (fail closed); a lost run claim still stops the turn.
     *
     * Call it AFTER the domain's own refusals that cost nothing (unknown ids, the build admission, the geocode) — a
     * refusal recorded for a call the domain refuses anyway would use up the owner's answer.
     *
     * @throws AgentRunClaimLostException
     */
    public function reserveOwnerConsentForStep(?AgentToolCallProvenance $callProvenance, OwnerConsentRequest $request): OwnerConsentReservation
    {
        $verdict = OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_ERROR);
        try {
            $conversation = $callProvenance?->conversation;
            $windowStart = $conversation instanceof AIConversation ? $this->findOwnerConsentWindowStart($conversation, $request->toolName) : -1;
            $refusedThrough = $conversation instanceof AIConversation ? $this->findOwnerConsentRefusedThrough($conversation, $request->toolName) : -1;
            $verdict = $this->runConsentCheck($callProvenance, $request, max($windowStart, $refusedThrough));
            if ($verdict->decidedBy === OwnerConsentVerdict::DECIDED_BY_UNGATED) {
                return OwnerConsentReservation::ungated($request, $verdict);
            }
            $toolCallId = $callProvenance?->toolCallId;
            if (!$conversation instanceof AIConversation || $verdict->decidedBy !== OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL
                || $verdict->consentingOwnerMessageIndex === null
            ) {
                return OwnerConsentReservation::notReserved($request, $verdict);
            }
            $runToken = $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation);
            if (!$verdict->consented) {
                $this->recordOwnerConsentRefusal($conversation, OwnerConsentUse::refusedFor($request, $verdict->consentingOwnerMessageIndex, $windowStart, $toolCallId), $runToken);
                return OwnerConsentReservation::notReserved($request, $verdict);
            }
            if ($toolCallId === null) {
                return OwnerConsentReservation::notReserved($request, OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_ERROR, $verdict->consentingOwnerMessageIndex));
            }
            $reservedUse = OwnerConsentUse::reservedFor($request, $verdict->consentingOwnerMessageIndex, $windowStart, $toolCallId);
            return $this->recordOwnerConsentReservation($conversation, $reservedUse, $runToken)
                ? OwnerConsentReservation::reserved($request, $verdict, $reservedUse)
                : OwnerConsentReservation::notReserved($request, $verdict);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost;
        } catch (Throwable $reservationError) {
            DDDService::instance()->getLogger()->warning('[OwnerConsent] reservation failed', ['tool' => $request->toolName, 'reason' => $reservationError::class . ': ' . mb_substr($reservationError->getMessage(), 0, 200)]);
            return OwnerConsentReservation::notReserved($request, $verdict->consented ? OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_ERROR) : $verdict);
        }
    }

    /**
     * {@see reserveOwnerConsentForStep()} for a tool that answers a missing consent as a gate refusal: throws
     * {@see OwnerConsentMissingException} (the model-facing text as its message) unless the write is allowed.
     *
     * @throws OwnerConsentMissingException
     * @throws AgentRunClaimLostException
     */
    public function requireOwnerConsentForStep(?AgentToolCallProvenance $callProvenance, OwnerConsentRequest $request): OwnerConsentReservation
    {
        $reservation = $this->reserveOwnerConsentForStep($callProvenance, $request);
        if (!$reservation->isWriteAllowed()) {
            throw new OwnerConsentMissingException($reservation->buildModelFacingText());
        }
        return $reservation;
    }

    /**
     * The write went through — or its outcome is unknown: the owner row never authorises the tool again. Nothing to do for
     * an ungated call.
     *
     * @throws AgentRunClaimLostException
     */
    public function markConsentUsed(?AgentToolCallProvenance $callProvenance, OwnerConsentReservation $reservation): void
    {
        $conversation = $callProvenance?->conversation;
        if ($reservation->reservedUse !== null && $conversation instanceof AIConversation) {
            $this->markOwnerConsentUseUsed($conversation, $reservation->reservedUse, $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation));
        }
    }

    /**
     * The write reported that it changed nothing: the same owner row may authorise the retry. Nothing to
     * do for an ungated call.
     *
     * @throws AgentRunClaimLostException
     */
    public function releaseConsent(?AgentToolCallProvenance $callProvenance, OwnerConsentReservation $reservation): void
    {
        $conversation = $callProvenance?->conversation;
        if ($reservation->reservedUse !== null && $conversation instanceof AIConversation) {
            $this->releaseOwnerConsentUse($conversation, $reservation->reservedUse, $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation));
        }
    }

    /**
     * The tool's owner-consent WINDOW as stored: the newest owner row a reservation or use of the tool
     * was recorded at, -1 when none — the value a reservation is guarded on ({@see recordOwnerConsentReservation()}).
     * Read from the row, never from a loaded copy. A wrong value can only narrow what a reservation may record: the
     * reservation is guarded on the stored window and the refusal floor, so a value read too low fails there.
     */
    public function findOwnerConsentWindowStart(AIConversation $conversation, string $toolName): int
    {
        return $this->findOwnerConsentWorkingStateNumber($conversation, self::buildWindowPath(), $toolName);
    }

    /**
     * The newest owner row the decision model REFUSED for the tool, -1 when none — a floor that only rises: no
     * reservation records at or below it, and a release never lowers it.
     */
    public function findOwnerConsentRefusedThrough(AIConversation $conversation, string $toolName): int
    {
        return $this->findOwnerConsentWorkingStateNumber($conversation, self::buildRefusedThroughPath(), $toolName);
    }

    /**
     * The number stored at `$toolPath` (a path whose last segment is the bound `:toolName`) for `$toolName`, -1 when
     * none.
     *
     * @throws \InvalidArgumentException when `$toolName` is no tool name
     */
    protected function findOwnerConsentWorkingStateNumber(AIConversation $conversation, JsonPath $toolPath, string $toolName): int
    {
        if (!isset($conversation->id)) {
            return -1;
        }
        $number = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT ' . EntityManagerFactory::getDialect()->jsonInt(self::WORKING_STATE_COLUMN_SQL, $toolPath)
            . ' FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` = :id',
            ['toolName' => OwnerConsentUse::requireToolName($toolName), 'id' => $conversation->id]
        );
        return is_numeric($number) ? (int)$number : -1;
    }

    /** The path of a tool's window; its last segment is the bound `:toolName`. */
    protected static function buildWindowPath(): JsonPath
    {
        return JsonPath::fromKeys(self::WINDOWS_KEY)->withParameter(':toolName');
    }

    /** The path of a tool's refusal floor; its last segment is the bound `:toolName`. */
    protected static function buildRefusedThroughPath(): JsonPath
    {
        return JsonPath::fromKeys(self::REFUSED_THROUGH_KEY)->withParameter(':toolName');
    }

    /** The path of one record; its last segment is the bound `:useKey` ({@see OwnerConsentUse::getKey()}). */
    protected static function buildUsePath(): JsonPath
    {
        return JsonPath::fromKeys(self::USES_KEY)->withParameter(':useKey');
    }

    /** The SQL of the window of the bound `:toolName` as a whole number; NULL when none is stored. */
    protected static function buildStoredWindowSql(): string
    {
        return EntityManagerFactory::getDialect()->jsonInt(self::WORKING_STATE_COLUMN_SQL, self::buildWindowPath());
    }

    /** The SQL of the refusal floor of the bound `:toolName`, -1 when none is stored. */
    protected static function buildStoredRefusedThroughOrMinusOneSql(): string
    {
        return 'COALESCE(' . EntityManagerFactory::getDialect()->jsonInt(self::WORKING_STATE_COLUMN_SQL, self::buildRefusedThroughPath()) . ', -1)';
    }

    /** The SQL of "no record is stored under the bound `:useKey`" (a missing path, also in a NULL column). */
    protected static function buildUseIsNotStoredSql(): string
    {
        return EntityManagerFactory::getDialect()->jsonType(self::WORKING_STATE_COLUMN_SQL, self::buildUsePath()) . ' IS NULL';
    }

    /**
     * The tool name and the record key of `$ownerConsentUse` as the bound parameters `:toolName` and `:useKey`.
     *
     * @return array<string, string>
     * @throws \InvalidArgumentException when the tool name is no tool name
     */
    protected static function buildToolNameAndUseKeyParameters(OwnerConsentUse $ownerConsentUse): array
    {
        return ['toolName' => OwnerConsentUse::requireToolName($ownerConsentUse->toolName), 'useKey' => $ownerConsentUse->getKey()];
    }

    /**
     * Reserves an owner consent for one write in ONE guarded statement: only while the tool's
     * window is still the one the decision read ({@see OwnerConsentUse::$windowStart}), the owner row lies above the
     * refusal floor, and no record of the same row exists — a stale window, a second call on the same row, forked calls
     * and a refused row cannot reserve. The window moves to the reserved row in the same statement. True = reserved.
     * Token-fenced: under a held claim a lost claim throws.
     *
     * @throws AgentRunClaimLostException
     */
    public function recordOwnerConsentReservation(AIConversation $conversation, OwnerConsentUse $reservedUse, ?string $runToken): bool
    {
        if (!isset($conversation->id) || $reservedUse->ownerMessageIndex <= $reservedUse->windowStart || $reservedUse->toolCallId === null) {
            return false;
        }
        // the window always moves (the owner row lies above it) and the record is new: a matched row is a changed row
        $reserved = $this->executeOwnerConsentStatement(
            $conversation,
            EntityManagerFactory::getDialect()->jsonUpdate(self::WORKING_STATE_COLUMN_SQL)->mergePatch(':slice')->toSql(),
            [
                'slice' => (string)json_encode([
                    self::USES_KEY => [$reservedUse->getKey() => $reservedUse->toRepositoryArray()],
                    self::WINDOWS_KEY => [$reservedUse->toolName => $reservedUse->ownerMessageIndex],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'COALESCE(' . self::buildStoredWindowSql() . ', -1) = :windowStart'
            . ' AND ' . self::buildStoredRefusedThroughOrMinusOneSql() . ' < :ownerMessageIndex'
            . ' AND ' . self::buildUseIsNotStoredSql(),
            self::buildToolNameAndUseKeyParameters($reservedUse) + [
                'windowStart' => $reservedUse->windowStart,
                'ownerMessageIndex' => $reservedUse->ownerMessageIndex,
            ],
            $runToken
        );
        if ($reserved) {
            $conversation->getOrCreateAgentWorkingState()->getOrCreateOwnerConsentUses()->replaceUse($reservedUse);
        }
        return $reserved;
    }

    /**
     * Records a decision-model REFUSAL of the tool on owner rows up to `$refusedUse->ownerMessageIndex`: the
     * refusal floor rises to that row (GREATEST — never lowered, not even by a release racing it), so the next decision
     * reads only the rows after it and no reservation can use them. Independent of the window and of a reservation of
     * the same row: neither can make the refusal disappear. True when the row changed (the floor rose or the entry was
     * added): the statement matches only a row it changes, so the answer is the same on MariaDB (changed rows) and on
     * PostgreSQL (matched rows).
     *
     * @throws AgentRunClaimLostException
     */
    public function recordOwnerConsentRefusal(AIConversation $conversation, OwnerConsentUse $refusedUse, ?string $runToken): bool
    {
        // the floor ALWAYS rises — also when a reservation holds the same owner row (a refused row must never authorise,
        // whatever happens to that reservation); the REFUSED entry is added only where the key is free. The merged
        // slice names the floors' object, so the floor of the tool has its parent before it is set.
        $recorded = $this->executeOwnerConsentStatement(
            $conversation,
            EntityManagerFactory::getDialect()->jsonUpdate(self::WORKING_STATE_COLUMN_SQL)
                ->mergePatch('CASE WHEN ' . self::buildUseIsNotStoredSql() . ' THEN :sliceWithRecord ELSE :sliceWithoutRecord END')
                ->set(self::buildRefusedThroughPath(), 'GREATEST(' . self::buildStoredRefusedThroughOrMinusOneSql() . ', :ownerMessageIndex)', JsonValueKind::NUMBER)
                ->toSql(),
            [
                'sliceWithRecord' => (string)json_encode([
                    self::USES_KEY => [$refusedUse->getKey() => $refusedUse->toRepositoryArray()],
                    self::REFUSED_THROUGH_KEY => (object)[],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'sliceWithoutRecord' => (string)json_encode([self::REFUSED_THROUGH_KEY => (object)[]], JSON_UNESCAPED_SLASHES),
                'ownerMessageIndex' => $refusedUse->ownerMessageIndex,
            ],
            // nothing to change: the entry is stored and the floor is at or above the row already
            '(' . self::buildUseIsNotStoredSql() . ' OR ' . self::buildStoredRefusedThroughOrMinusOneSql() . ' < :ownerMessageIndex)',
            self::buildToolNameAndUseKeyParameters($refusedUse),
            $runToken
        );
        if ($recorded) {
            $conversation->getOrCreateAgentWorkingState()->getOrCreateOwnerConsentUses()->replaceUse($refusedUse);
        }
        return $recorded;
    }

    /**
     * Marks a RESERVED use USED — the write went through, or its outcome is unknown: the owner row never authorises the
     * tool again. Guarded on the record still being RESERVED by the same tool call.
     *
     * @throws AgentRunClaimLostException
     */
    public function markOwnerConsentUseUsed(AIConversation $conversation, OwnerConsentUse $reservedUse, ?string $runToken): bool
    {
        $usedUse = $reservedUse->withState(OwnerConsentUse::STATE_USED);
        $marked = $this->executeOwnerConsentStatement(
            $conversation,
            EntityManagerFactory::getDialect()->jsonUpdate(self::WORKING_STATE_COLUMN_SQL)->mergePatch(':slice')->toSql(),
            ['slice' => (string)json_encode([self::USES_KEY => [$reservedUse->getKey() => $usedUse->toRepositoryArray()]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            self::buildOwnerConsentReservedByTheCallGuard(),
            $this->buildOwnerConsentReservationParameters($reservedUse),
            $runToken
        );
        if ($marked) {
            $conversation->getOrCreateAgentWorkingState()->getOrCreateOwnerConsentUses()->replaceUse($usedUse);
        }
        return $marked;
    }

    /**
     * Releases a RESERVED use — the write reported that it changed nothing: the record goes, and while the tool's window
     * still stands on the released row it returns to the window the reservation STORED, so the same owner row authorises
     * the retry. A window a later record moved stays; the refusal floor is never touched. Guarded on the record still
     * being RESERVED by the same tool call.
     *
     * @throws AgentRunClaimLostException
     */
    public function releaseOwnerConsentUse(AIConversation $conversation, OwnerConsentUse $reservedUse, ?string $runToken): bool
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $storedWindowSql = self::buildStoredWindowSql();
        // the record is removed, so a matched row is a changed row
        $released = $this->executeOwnerConsentStatement(
            $conversation,
            $sqlDialect->jsonUpdate(self::WORKING_STATE_COLUMN_SQL)
                ->set(
                    self::buildWindowPath(),
                    'CASE WHEN ' . $storedWindowSql . ' = ' . $sqlDialect->jsonInt(self::WORKING_STATE_COLUMN_SQL, self::buildUsePath()->withKey('ownerMessageIndex'))
                    . ' THEN ' . $sqlDialect->jsonInt(self::WORKING_STATE_COLUMN_SQL, self::buildUsePath()->withKey('windowStart'))
                    . ' ELSE ' . $storedWindowSql . ' END',
                    JsonValueKind::NUMBER
                )
                ->remove(self::buildUsePath())
                ->toSql(),
            ['toolName' => OwnerConsentUse::requireToolName($reservedUse->toolName)],
            self::buildOwnerConsentReservedByTheCallGuard(),
            $this->buildOwnerConsentReservationParameters($reservedUse),
            $runToken
        );
        if ($released) {
            $conversation->getOrCreateAgentWorkingState()->getOrCreateOwnerConsentUses()->removeUseWithKey($reservedUse->getKey());
        }
        return $released;
    }

    /** The guard of a use's conclusion: the record under the bound `:useKey` is still RESERVED, by the same tool call. */
    protected static function buildOwnerConsentReservedByTheCallGuard(): string
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        return $sqlDialect->jsonText(self::WORKING_STATE_COLUMN_SQL, self::buildUsePath()->withKey('state')) . ' = :reserved'
            . ' AND ' . $sqlDialect->jsonText(self::WORKING_STATE_COLUMN_SQL, self::buildUsePath()->withKey('toolCallId')) . ' = :toolCallId';
    }

    /**
     * The parameters of {@see buildOwnerConsentReservedByTheCallGuard()}, the record key `:useKey` included.
     *
     * @return array<string, string>
     */
    protected function buildOwnerConsentReservationParameters(OwnerConsentUse $reservedUse): array
    {
        return [
            'useKey' => $reservedUse->getKey(),
            'reserved' => OwnerConsentUse::STATE_RESERVED,
            'toolCallId' => (string)$reservedUse->toolCallId,
        ];
    }

    /**
     * One guarded write of the owner-consent keys: `SET agentWorkingState = <$newStateExpression>` where the row matches
     * `$guard`; true when it matched and changed. A guard matches only a row the expression changes, so the count is
     * the same on MariaDB (changed rows) and on PostgreSQL (matched rows). Token-fenced like the other working-state
     * slices.
     *
     * @param array<string, int|string> $expressionParameters
     * @param array<string, int|string> $guardParameters
     * @throws AgentRunClaimLostException
     */
    protected function executeOwnerConsentStatement(
        AIConversation $conversation,
        string $newStateExpression,
        array $expressionParameters,
        string $guard,
        array $guardParameters,
        ?string $runToken
    ): bool {
        if (!isset($conversation->id)) {
            return false;
        }
        $parameters = $expressionParameters + $guardParameters + ['id' => $conversation->id];
        $fenceClause = '';
        if ($runToken !== null) {
            $fenceClause = ' AND `runToken` = :runToken';
            $parameters['runToken'] = $runToken;
        }
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET ' . self::WORKING_STATE_COLUMN_SQL . ' = ' . $newStateExpression
            . ' WHERE `id` = :id AND ' . $guard . $fenceClause,
            $parameters
        );
        if ($affectedRows === 0 && $runToken !== null && !$this->runClaimsService()->holdsRunClaim($conversation, $runToken)) {
            throw new AgentRunClaimLostException('Run-claim lost before an owner-consent record — another worker owns this conversation.');
        }
        return $affectedRows === 1;
    }

    /** The run claim the owner-consent records are fenced on. */
    protected function runClaimsService(): AIConversationRunClaimsService
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService;
    }

    /**
     * The check itself for {@see reserveOwnerConsentForStep()}: ungated without agent provenance, else the decision and
     * one turn event.
     *
     * @throws AgentRunClaimLostException
     */
    protected function runConsentCheck(?AgentToolCallProvenance $callProvenance, OwnerConsentRequest $request, int $afterOwnerMessageIndex): OwnerConsentVerdict
    {
        $conversation = $callProvenance?->conversation;
        if ($callProvenance === null || $callProvenance->isEvalHarnessCall || !$conversation instanceof AIConversation || !isset($conversation->id)) {
            return OwnerConsentVerdict::ungated();
        }
        $startedAt = hrtime(true);
        try {
            $ownerConsentDecisionResult = $this->decide($callProvenance, $conversation, $request, $afterOwnerMessageIndex);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost;
        } catch (Throwable $checkError) {
            $this->logCheckFailedInOurCode($checkError);
            $ownerConsentDecisionResult = OwnerConsentDecisionResult::create(OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_ERROR));
        }
        $this->recordTurnEvent(
            $conversation,
            $request,
            $ownerConsentDecisionResult->verdict,
            $ownerConsentDecisionResult->dialogue,
            $ownerConsentDecisionResult->callOutcome,
            (int)round((hrtime(true) - $startedAt) / 1e6)
        );
        return $ownerConsentDecisionResult->verdict;
    }

    /**
     * The decision path past the ungated check; the result carries the dialogue and the call outcome for the turn event.
     * Our own error after the dialogue was built is a failed verdict WITH that dialogue (the event counts its lines).
     *
     * @throws AgentRunClaimLostException
     */
    protected function decide(
        AgentToolCallProvenance $callProvenance,
        AIConversation $conversation,
        OwnerConsentRequest $request,
        int $afterOwnerMessageIndex
    ): OwnerConsentDecisionResult {
        if ($conversation->isDelegation()) {
            return OwnerConsentDecisionResult::create(OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_DELEGATION_CHILD));
        }
        $decisionModelSettings = AgentDecisionModelSettings::current();
        $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_OWNER_CONSENT, $conversation);
        // only `on` decides — `off` and `shadow` are no consent (there is no other mechanism any more)
        if ($siteMode !== AgentDecisionModelSettings::MODE_ON) {
            return OwnerConsentDecisionResult::create(OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_SITE_OFF));
        }
        if (!$conversation->isUserPresentRun() || $conversation->findPurpose()?->usesSynthesizedOwnerText === true) {
            return OwnerConsentDecisionResult::create(OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_NO_LIVE_OWNER));
        }
        $messages = $callProvenance->conversationMessages ?? (isset($conversation->messages) ? $conversation->messages : null);
        if (!$messages instanceof AIConversationMessages) {
            return OwnerConsentDecisionResult::create(OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_NO_OWNER_ROW));
        }
        $dialogue = $this->buildOwnerConsentDialogue($messages, $afterOwnerMessageIndex, $conversation->getAgentWorkingState());
        if (!$dialogue->hasOwnerLine()) {
            return OwnerConsentDecisionResult::create(OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_NO_OWNER_ROW), $dialogue);
        }
        try {
            $channel = $conversation->isOwnerOnThePhoneLine($messages->getElements())
                ? ArgusAgentOwnerConsentDecision::CHANNEL_PHONE
                : ArgusAgentOwnerConsentDecision::CHANNEL_CHAT;
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            return $this->decideDialogue(
                $request,
                $dialogue,
                $channel,
                $agentSpacesService->findLoadedBillingEntityOfConversation($conversation),
                isset($conversation->account) ? $conversation->account : null,
                OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL
            );
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost;
        } catch (Throwable $checkError) {
            $this->logCheckFailedInOurCode($checkError);
            return OwnerConsentDecisionResult::create(OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_ERROR), $dialogue);
        }
    }

    protected function logCheckFailedInOurCode(Throwable $checkError): void
    {
        DDDService::instance()->getLogger()->warning('[OwnerConsent] check failed in our code', ['reason' => $checkError::class . ': ' . mb_substr($checkError->getMessage(), 0, 200)]);
    }

    /**
     * The dialogue the owner-consent check reads from the rows of `$messages`: the eligible owner rows
     * AFTER `$afterMessageIndex` (the newest used consent of the tool; -1 for none), the newest
     * {@see OwnerConsentDialogue::MAX_OWNER_LINES} of them, each with the assistant text right before it and, when it is
     * the first owner text after an owner-question widget, that widget — in conversation order. Eligible: the owner's own
     * TEXT rows (typed, a pill answer, a phone transcript), never a row the platform wrote (admin console, a relayed call
     * task) or one the injection check blocked ({@see AgentWorkingState}), never a signal or an agent-authored
     * row. The widget is the newest owner-question widget before the row, keyed or not, read through the shown-widget
     * view. Empty when no eligible row exists. Pure — the live check and the probe
     * (the host's owner-consent replay command) build the same dialogue.
     */
    public function buildOwnerConsentDialogue(AIConversationMessages $messages, int $afterMessageIndex, ?AgentWorkingState $agentWorkingState): OwnerConsentDialogue
    {
        $sortedRows = $messages->getRowsSortedByIndex();
        $ownerRows = [];
        foreach ($sortedRows as $row) {
            $rowIndex = $row->indexInConversation ?? null;
            if ($rowIndex === null || $rowIndex <= $afterMessageIndex || !$row->isOwnerText() || trim((string)($row->message ?? '')) === '') {
                continue;
            }
            if ($agentWorkingState !== null
                && (in_array($rowIndex, $agentWorkingState->platformWrittenOwnerMessageIndexes, true)
                    || $agentWorkingState->isOwnerMessageSecurityFiltered($rowIndex))
            ) {
                continue;
            }
            $ownerRows[] = $row;
        }
        $shownConversationWidgets = $messages->buildShownWidgets();
        $dialogue = new OwnerConsentDialogue();
        foreach (array_slice($ownerRows, -OwnerConsentDialogue::MAX_OWNER_LINES) as $ownerRow) {
            $ownerRowIndex = (int)$ownerRow->indexInConversation;
            $precedingAssistantRow = null;
            foreach ($sortedRows as $row) {
                if (($row->indexInConversation ?? PHP_INT_MAX) >= $ownerRowIndex) {
                    break;
                }
                if ($row->isAssistantText() && trim((string)($row->message ?? '')) !== '') {
                    $precedingAssistantRow = $row;
                }
            }
            if ($precedingAssistantRow !== null) {
                $assistantLine = OwnerConsentDialogueLine::create(
                    OwnerConsentDialogueLine::SPEAKER_ASSISTANT,
                    (int)$precedingAssistantRow->indexInConversation,
                    AIConversationMessage::stripPreCallMessageMarker(trim((string)$precedingAssistantRow->message))
                );
                $dialogue->add($assistantLine);
            }
            // the widget the owner row answers, with or without a questionKey: a keyless one carries the explained step too
            $answeredQuestionWidget = AskOwnerTool::findNewestShownOwnerQuestionWidget($shownConversationWidgets, null, $ownerRowIndex, acceptKeylessWidgets: true);
            $answeredOwnerQuestionWidget = $answeredQuestionWidget?->buildWidget();
            if ($answeredQuestionWidget !== null && $answeredOwnerQuestionWidget instanceof OwnerQuestionConversationWidget
                && AskOwnerTool::isFirstOwnerTextAfterWidget($answeredQuestionWidget, $ownerRowIndex)
            ) {
                $widgetLine = OwnerConsentDialogueLine::create(
                    OwnerConsentDialogueLine::SPEAKER_WIDGET,
                    (int)$answeredQuestionWidget->hostIndexInConversation,
                    self::composeOwnerQuestionWidgetLineText($answeredOwnerQuestionWidget)
                );
                $dialogue->add($widgetLine);
            }
            $ownerLine = OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_OWNER, $ownerRowIndex, trim((string)$ownerRow->message));
            $dialogue->add($ownerLine);
        }
        $dialogue->sort(static fn (OwnerConsentDialogueLine $first, OwnerConsentDialogueLine $second): int => $first->messageIndex <=> $second->messageIndex);
        return $dialogue;
    }

    /**
     * The dialogue line of the widget an owner row answers: its own question — a yes under a widget that asked something
     * else is no yes to the explained step — with its catalogue key and its option labels. Pure.
     */
    protected static function composeOwnerQuestionWidgetLineText(OwnerQuestionConversationWidget $ownerQuestionWidget): string
    {
        $questionKey = $ownerQuestionWidget->getQuestionKey();
        $questionText = trim((string)$ownerQuestionWidget->question);
        return 'widget' . ($questionKey !== null ? ' "' . $questionKey . '"' : '') . ($questionText !== '' ? ' asks: ' . $questionText : '')
            . ' · options: ' . implode(' | ', $ownerQuestionWidget->getOptionLabels());
    }

    /**
     * The decision itself over a built dialogue — no site gate, no turn event; the caller supplies the envelope (the live
     * tool runs inside the turn's, the probe opens one). Shared by the live check and the probe
     * (the host's owner-consent replay command), so both send the same request.
     *
     * @param OwnerConsentRequest $request the step, its target and its consequence
     * @param OwnerConsentDialogue $dialogue the dialogue; must hold an owner line
     * @param string $channel {@see ArgusAgentOwnerConsentDecision::CHANNEL_CHAT} or `CHANNEL_PHONE`
     * @param Entity|null $billingEntity the billing entity of the conversation's space the call is booked on
     * @param Account|null $budgetAccount the account the call is booked on when there is no billing entity
     * @param string $decidedBy {@see OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL} or `DECIDED_BY_SHADOW`
     * @param float|null $timeoutSeconds the call's timeout; null = the decision settings' (the probe allows a cold start)
     * @return OwnerConsentDecisionResult the verdict, the dialogue and how the call went (for the caller's telemetry)
     */
    public function decideDialogue(
        OwnerConsentRequest $request,
        OwnerConsentDialogue $dialogue,
        string $channel,
        ?Entity $billingEntity,
        ?Account $budgetAccount,
        string $decidedBy,
        ?float $timeoutSeconds = null
    ): OwnerConsentDecisionResult {
        $ownerMessageIndex = $dialogue->getNewestOwnerMessageIndex();
        if ($ownerMessageIndex === null) {
            return OwnerConsentDecisionResult::create(OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_NO_OWNER_ROW), $dialogue);
        }
        $decisionModelSettings = AgentDecisionModelSettings::current();
        $argusDecision = ArgusAgentOwnerConsentDecision::forRequest($request, $dialogue, $channel);
        $argusDecision->billingEntity = $billingEntity;
        $argusDecision->budgetAccount = $budgetAccount;
        $outcome = $this->decisionCallsService()->runDecisionBatch(
            ArgusAgentDecisions::forChild($argusDecision, AgentDecisionModelSettings::SITE_OWNER_CONSENT),
            $argusDecision,
            $timeoutSeconds ?? $decisionModelSettings->timeoutSeconds,
            $decisionModelSettings->breakerSeconds,
            AgentDecisionModelSettings::SITE_OWNER_CONSENT
        );
        $failure = match ($outcome->outcome) {
            AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN => OwnerConsentVerdict::FAILURE_BREAKER,
            AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED => OwnerConsentVerdict::FAILURE_TRANSPORT,
            AgentDecisionCallOutcome::OUTCOME_REFUSED => OwnerConsentVerdict::FAILURE_REFUSED,
            AgentDecisionCallOutcome::OUTCOME_ERROR => OwnerConsentVerdict::FAILURE_ERROR,
            default => $argusDecision->decided ? null : OwnerConsentVerdict::FAILURE_UNPARSABLE,
        };
        $this->decisionCallsService()->closeCircuitBreakerAfter($outcome, $argusDecision->decided);
        if ($failure !== null) {
            return OwnerConsentDecisionResult::create(OwnerConsentVerdict::failed($failure, $ownerMessageIndex), $dialogue, $outcome);
        }
        $verdict = OwnerConsentVerdict::decided(
            $request,
            (float)$argusDecision->stepExplainedProbability,
            (float)$argusDecision->ownerAgreedProbability,
            $ownerMessageIndex,
            $decidedBy
        );
        return OwnerConsentDecisionResult::create($verdict, $dialogue, $outcome);
    }

    /**
     * One turn event per checked call — telemetry only, never fails the check.
     *
     * @throws AgentRunClaimLostException the run claim was lost while the event was written
     */
    protected function recordTurnEvent(
        AIConversation $conversation,
        OwnerConsentRequest $request,
        OwnerConsentVerdict $verdict,
        ?OwnerConsentDialogue $dialogue,
        ?AgentDecisionCallOutcome $outcome,
        int $elapsedMs
    ): void {
        try {
            $event = AIConversationTurnEvent::newInstance();
            $event->aiConversationId = (int)$conversation->id;
            $event->initiatorMessageIndex = isset($conversation->messages) ? $conversation->messages->getNewestInitiatorMessageIndex() : null;
            $event->evaluationMessageIndex = $verdict->consentingOwnerMessageIndex;
            $event->kind = AIConversationTurnEvent::KIND_DECISION;
            $event->site = AgentDecisionModelSettings::SITE_OWNER_CONSENT;
            $event->subject = mb_substr($request->toolName . ' ' . $request->targetKey, 0, 180);
            $event->evalRun = isset($conversation->runSettings) ? ($conversation->runSettings->evalRun ?? null) : null;
            $event->elapsedMs = $elapsedMs;
            $event->setPayloadArray([
                'decidedBy' => $verdict->getDecisionStamp(),
                'applied' => $verdict->decidedBy === OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL,
                'consented' => $verdict->consented,
                'stepExplainedProbability' => $verdict->stepExplainedProbability,
                'ownerAgreedProbability' => $verdict->ownerAgreedProbability,
                'minimumProbability' => $request->minimumProbability,
                'consentingOwnerMessageIndex' => $verdict->consentingOwnerMessageIndex,
                'failure' => $verdict->failure,
                'actionKind' => $request->actionKind,
                'targetLine' => mb_substr($request->targetLine, 0, 200),
                'dialogueLines' => $dialogue !== null ? $dialogue->count() : 0,
                'transportFailureReason' => $outcome?->failureReason,
            ]);
            /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
            $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            $aIConversationTurnEventsService->recordEvent($event, isset($conversation->runToken) ? $conversation->runToken : null);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost;
        } catch (Throwable) {
            // telemetry only
        }
    }

    protected function decisionCallsService(): AgentDecisionCallsService
    {
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        return $agentDecisionCallsService;
    }
}
