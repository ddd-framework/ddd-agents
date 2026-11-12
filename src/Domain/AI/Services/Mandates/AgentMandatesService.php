<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Mandates;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantArguments;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantOutcome;
use DDD\Domain\AI\Entities\Mandates\AgentMandateSourceConversation;
use DDD\Domain\AI\Entities\Mandates\AgentMandates;
use DDD\Domain\AI\Repo\Argus\Mandates\ArgusAgentMandateApproval;
use DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandate;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandates;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Mandates: the owner's standing authorizations for consequential agent actions (see {@see AgentMandate}).
 * This service owns the gate lookup ({@see findActiveMandateFor} — also callable from WORKERS at fire time,
 * no conversation context needed), the revocation, and the ONE_INSTRUCTION consumption (atomic compare-and-set —
 * two concurrent executions must not both ride one single-use mandate).
 *
 * @method AgentMandate find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentMandates findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentMandate getEntityRepoClassInstance()
 * @method DBAgentMandates getEntitySetRepoClassInstance()
 */
class AgentMandatesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = AgentMandate::class;

    /** @var int Display cap for the triggering message's excerpt in the source-provenance VO. */
    public const int SOURCE_MESSAGE_EXCERPT_CHARS = 280;

    /**
     * Resolve where a mandate was granted — the provenance treatment of an application's memories: the triggering message's
     * excerpt + time from {@see AgentMandate::$sourceUserMessageId}, the conversation title/summary from
     * {@see AgentMandate::$sourceConversationId}. Best-effort — every part degrades to null; both loads run
     * through their rights-gated services.
     */
    public function loadSourceConversation(AgentMandate $agentMandate): ?AgentMandateSourceConversation
    {
        if (!isset($agentMandate->sourceConversationId) && !isset($agentMandate->sourceUserMessageId)) {
            return null;
        }
        $sourceConversation = new AgentMandateSourceConversation();
        $sourceConversation->conversationId = $agentMandate->sourceConversationId;

        if (isset($agentMandate->sourceUserMessageId)) {
            try {
                $sourceMessage = \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::getService()
                    ->find($agentMandate->sourceUserMessageId);
                if ($sourceMessage !== null) {
                    $sourceConversation->conversationId ??= $sourceMessage->aiConversationId ?? null;
                    $sourceConversation->messageCreatedAt = $sourceMessage->getCreatedTime();
                    $messageText = $sourceMessage->message ?? '';
                    if ($messageText !== '') {
                        $sourceConversation->messageExcerpt = mb_strlen($messageText) > self::SOURCE_MESSAGE_EXCERPT_CHARS
                            ? rtrim(mb_substr($messageText, 0, self::SOURCE_MESSAGE_EXCERPT_CHARS)) . '…'
                            : $messageText;
                    }
                }
            } catch (\Throwable) {
                // best-effort — the conversation title below may still resolve
            }
        }

        if ($sourceConversation->conversationId !== null) {
            try {
                $conversation = AIConversation::getService()->find($sourceConversation->conversationId);
                if ($conversation instanceof AIConversation) {
                    $sourceConversation->conversationTitle = $conversation->summary?->title;
                    $sourceConversation->conversationSummary = $conversation->summary?->text;
                }
            } catch (\Throwable) {
                // best-effort — excerpt + date already carry the essentials
            }
        }
        return $sourceConversation;
    }

    /**
     * The gate lookup: the newest ACTIVE, time-valid mandate of account `$accountId` that APPLIES to an action of
     * class `$actionClass` in `$agentSpace`.
     *
     * - With a space: a mandate bound to that space, or an account-wide one (no space on the mandate).
     * - Without a space (an action at account level): ONLY an account-wide mandate. A mandate bound to a space never
     *   authorizes an action in another space or at account level.
     *
     * The account and the space condition ({@see self::buildSqlConditionOfMandatesApplyingTo()}) are filtered in the
     * same statement. Time validity is evaluated in PHP on the candidate rows
     * ({@see AgentMandate::isCurrentlyValid()} is the single truth for the time fold).
     *
     * @param int $accountId the account the action runs for
     * @param AgentSpace|null $agentSpace the space the action targets; null = the action works at account level
     * @param string $actionClass one of the `AgentMandate::ACTION_*` constants
     * @return AgentMandate|null for a space that is not stored: an account-wide mandate only
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findActiveMandateFor(int $accountId, ?AgentSpace $agentSpace, string $actionClass): ?AgentMandate
    {
        $agentSpaceSqlCondition = $this->buildSqlConditionOfMandatesApplyingTo($agentSpace);
        $this->markExpiredMandates($accountId);
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.accountId = :accountId")
            ->andWhere("{$baseModelAlias}.actionClass = :actionClass")
            ->andWhere("{$baseModelAlias}.status = :status")
            ->setParameter('accountId', $accountId)
            ->setParameter('actionClass', $actionClass)
            ->setParameter('status', AgentMandate::STATUS_ACTIVE);
        $this->applyAgentSpaceSqlCondition($queryBuilder, $agentSpaceSqlCondition, $baseModelAlias);
        // Registry cache OFF: the gate re-checks within ONE process right after a grant — a cached empty
        // result from the pre-grant refusal must not mask the just-persisted mandate (the stale-identity-map
        // class; reproduced: grant COMPLETED, next gate check in the same turn still refused).
        $agentMandates = $repoClass->find($queryBuilder, false);
        $newestValidMandate = null;
        foreach ($agentMandates->getElements() as $agentMandate) {
            if (!$agentMandate->isCurrentlyValid()) {
                continue;
            }
            if ($newestValidMandate === null || ($agentMandate->id ?? 0) > ($newestValidMandate->id ?? 0)) {
                $newestValidMandate = $agentMandate;
            }
        }
        return $newestValidMandate;
    }

    /**
     * The condition on the space of the mandates that APPLY to `$agentSpace`: bound to it, or account-wide; without
     * a space the account-wide mandates only. A caller always filters the account in the same statement.
     *
     * @param AgentSpace|null $agentSpace null = an action at account level
     * @return AgentSpaceSqlCondition for a space that is not stored: the account-wide mandates only
     */
    protected function buildSqlConditionOfMandatesApplyingTo(?AgentSpace $agentSpace): AgentSpaceSqlCondition
    {
        return AgentSpaceSqlCondition::forAgentSpaceOrAccountWide($agentSpace);
    }

    /**
     * Adds `$agentSpaceSqlCondition` and its bound value to `$queryBuilder`.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param AgentSpaceSqlCondition $agentSpaceSqlCondition
     * @param string $baseModelAlias the alias of the mandate model in the query
     * @return void
     */
    protected function applyAgentSpaceSqlCondition(DoctrineQueryBuilder $queryBuilder, AgentSpaceSqlCondition $agentSpaceSqlCondition, string $baseModelAlias): void
    {
        $queryBuilder->andWhere($agentSpaceSqlCondition->getConditionDql($baseModelAlias));
        foreach ($agentSpaceSqlCondition->getParameters() as $parameterName => $parameterValue) {
            $queryBuilder->setParameter($parameterName, $parameterValue);
        }
    }

    /**
     * Throws unless a NEW mandate of account `$accountId` may be bound to `$agentSpace`; returns quietly when it
     * may. Asked before the approval check runs and before the mandate is stored: the database does not enforce that
     * the space of a mandate is one its account may use.
     *
     * @param AgentSpace $agentSpace
     * @param int $accountId the account the mandate is granted for
     * @return void
     * @throws BadRequestException
     * @throws ForbiddenException when the account cannot be read or may not use the space
     * @throws InternalErrorException when `$agentSpace` is not stored — it is never read as "account-wide", which
     *                                would widen the mandate to every space of the account
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function requireAgentSpaceUsableForNewMandate(AgentSpace $agentSpace, int $accountId): void
    {
        if (!isset($agentSpace->id)) {
            throw new InternalErrorException('A mandate cannot be bound to an agent space without an id.');
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $account = $this->findAccountOfMandateGrant($accountId);
        if (!$account instanceof Account) {
            throw new ForbiddenException($agentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        }
        $agentSpacesService->requireAgentSpaceUsableByAccount($agentSpace, $account);
    }

    /**
     * The account a mandate is granted for: the space of a new mandate is checked against it and the approval check
     * is billed to it. Also the override point of the tests that run without a database.
     *
     * @param int $accountId
     * @return Account|null null = the account cannot be read
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findAccountOfMandateGrant(int $accountId): ?Account
    {
        return Account::getService()->find($accountId);
    }

    /**
     * The GRANT path: the transcript window is pulled
     * SERVER-side from the conversation's live message set (never supplied by the model, so a grant cannot be
     * forged by tool arguments), the approval check ({@see ArgusAgentMandateApproval}) verifies the customer's
     * consent against it, and only an approved verdict persists an ACTIVE {@see AgentMandate} carrying the
     * verbatim-quote evidence excerpt. A rejected verdict throws {@see BadRequestException} with the
     * model-actionable disapproval reason — the executor surfaces it as the tool error the agent reads.
     * The outcome carries the mandate, the ACTIVE mandates it superseded, or that an ACTIVE one-instruction mandate
     * of the same request already covered it.
     *
     * `$agentSpace` is the space the mandate is bound to; null grants an ACCOUNT-WIDE mandate (it covers every space
     * of the account and the account level). A mandate with a space is granted only when the account may use the
     * space ({@see self::requireAgentSpaceUsableForNewMandate()}, asked first). The space is written once, on the
     * new mandate.
     *
     * @throws BadRequestException
     * @throws ForbiddenException when the account may not use `$agentSpace`
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws \Doctrine\ORM\Exception\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function grantMandateFromDialogue(
        int $accountId,
        ?AgentSpace $agentSpace,
        AgentMandateGrantArguments $grantArguments,
        AIConversationMessages $conversationMessages,
        ?AIConversationMessage $sourceUserMessage = null,
        ?int $sourceConversationId = null,
        ?\DateTimeZone $dialogueTimezone = null,
    ): AgentMandateGrantOutcome {
        if ($agentSpace !== null) {
            $this->requireAgentSpaceUsableForNewMandate($agentSpace, $accountId);
        }
        $actionClass = $grantArguments->actionClass;
        $scopeType = $grantArguments->scopeType;
        $validUntil = $grantArguments->validUntil;
        $constraintsNote = $grantArguments->constraintsNote;
        // IDEMPOTENT within one request: a model granting per item anyway (a
        // weak-model habit seen in a live conversation) must not create a second row + a SUPERSEDED chain — an
        // ACTIVE ONE_INSTRUCTION of the same class, space and request already covers every call of that request,
        // so it IS the grant. Returned without a new approval-check round trip.
        if (($scopeType === AgentMandate::SCOPE_ONE_INSTRUCTION || $scopeType === AgentMandate::LEGACY_SCOPE_ONE_SHOT) && ($sourceUserMessage->id ?? null) !== null) {
            $scopeType = AgentMandate::SCOPE_ONE_INSTRUCTION;
            $existingMandateForRequest = $this->findActiveMandateFor($accountId, $agentSpace, $actionClass);
            if ($existingMandateForRequest !== null
                && $existingMandateForRequest->hasOneInstructionScope()
                && ($existingMandateForRequest->sourceConversationId ?? null) === $sourceConversationId
                && ($existingMandateForRequest->sourceUserMessageId ?? null) === $sourceUserMessage->id
            ) {
                return AgentMandateGrantOutcome::forAlreadyCovered($existingMandateForRequest);
            }
        }
        $actionClasses = AgentMandate::getActionClasses();
        if (!in_array($actionClass, $actionClasses, true)) {
            throw new BadRequestException(
                'Unknown mandate action class "' . $actionClass . '". Allowed: '
                . implode(', ', $actionClasses)
            );
        }
        if (!in_array($scopeType, AgentMandate::SCOPE_TYPES, true)) {
            throw new BadRequestException(
                'Unknown mandate scope type "' . $scopeType . '". Allowed: '
                . implode(', ', AgentMandate::SCOPE_TYPES)
                . '. Mandates are never quantity-based — convert a "next N times" wish into a time-limited or unlimited mandate.'
            );
        }
        if ($scopeType === AgentMandate::SCOPE_TIME_WINDOW && $validUntil === null) {
            throw new BadRequestException('A TIME_WINDOW mandate requires validUntil.');
        }
        $dialogueExcerptSource = $conversationMessages->buildUserVisibleDialogueExcerptEndingAtMessage(
            $sourceUserMessage->id ?? null
        );
        if ($dialogueExcerptSource === '') {
            throw new BadRequestException(
                'The conversation contains no customer dialogue to verify the mandate against; ask the customer explicitly first.'
            );
        }

        $argusAgentMandateApproval = new ArgusAgentMandateApproval();
        $argusAgentMandateApproval->requestedActionClass = $actionClass;
        $argusAgentMandateApproval->requestedActionClassDescription = AgentMandate::getActionClassDescription($actionClass);
        $argusAgentMandateApproval->requestedScopeType = $scopeType;
        $argusAgentMandateApproval->requestedValidUntil = $validUntil;
        $argusAgentMandateApproval->requestedConstraintsNote = $constraintsNote;
        $argusAgentMandateApproval->dialogueExcerptSource = $dialogueExcerptSource;
        $argusAgentMandateApproval->dialogueTimezone = $dialogueTimezone;
        $accountForAiBudgetHandling = $this->findAccountOfMandateGrant($accountId);
        $argusAgentMandateApproval->billAICostsTo(account: $accountForAiBudgetHandling);
        $argusAgentMandateApproval->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        if ($argusAgentMandateApproval->approved === null) {
            // the check gave no verdict (its response was not the verdict shape, or nothing) — once more before
            // the assistant has to deal with it
            $argusAgentMandateApproval->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        }
        if ($argusAgentMandateApproval->approved === null) {
            throw new InternalErrorException(
                'The mandate approval check did not complete — it gave no verdict on the customer\'s consent. Call grantMandate '
                . 'once more with the same arguments; if it fails again, tell the customer that the change could not be '
                . 'authorized right now and offer to try again later. Never say the customer\'s consent was rejected.'
            );
        }
        if (!$argusAgentMandateApproval->approved) {
            throw new BadRequestException(
                'Mandate not granted: ' . ($argusAgentMandateApproval->disapprovalReason
                    ?? 'the dialogue does not contain the customer\'s consent.')
            );
        }

        /** @var AgentMandate $agentMandate */
        $agentMandate = AgentMandate::newInstance();
        $agentMandate->accountId = $accountId;
        // written once, here: null = account-wide. A space without an id never reaches this line (refused above).
        $agentMandate->agentSpaceId = $agentSpace !== null ? (int)$agentSpace->id : null;
        $agentMandate->actionClass = $actionClass;
        $agentMandate->scopeType = $scopeType;
        $agentMandate->status = AgentMandate::STATUS_ACTIVE;
        $agentMandate->validFrom = new DateTime();
        $agentMandate->validUntil = $scopeType === AgentMandate::SCOPE_TIME_WINDOW ? $validUntil : null;
        $agentMandate->customerApprovalExcerpt = $argusAgentMandateApproval->customerApprovalExcerpt;
        $agentMandate->constraintsNote = $argusAgentMandateApproval->constraintsNote;
        $agentMandate->sourceConversationId = $sourceConversationId;
        $agentMandate->sourceUserMessageId = $sourceUserMessage->id ?? null;
        /** @var AgentMandate $grantedAgentMandate */
        $grantedAgentMandate = $this->update($agentMandate);
        return AgentMandateGrantOutcome::forGranted($grantedAgentMandate, $this->supersedeCoveredMandates($grantedAgentMandate));
    }

    /**
     * AUTO-SUPERSEDE (the memory-DIRECTIVE-supersede pattern made deterministic):
     * a fresh grant REPLACES every still-ACTIVE mandate of the same class whose coverage it includes
     * ({@see AgentMandate::isSupersededBy()} — same space, or the new grant is account-wide). The statement reads
     * the ACTIVE mandates of the account and class across all spaces (no space condition); the fold decides. The
     * replaced rows are never mutated in substance: status → SUPERSEDED + {@see AgentMandate::$supersededByMandateId}
     * pointing at the successor, so the chain (old grant → replacement) is a navigable first-class fact and
     * the history keeps every evidence excerpt forever. Runs AFTER the new grant persisted — an approval
     * rejection therefore never touches the standing mandate (no gap).
     *
     * @return AgentMandate[] the mandates this grant replaced (for the tool response's transparency note)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws \Doctrine\ORM\Exception\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    protected function supersedeCoveredMandates(AgentMandate $grantedAgentMandate): array
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.accountId = :accountId")
            ->andWhere("{$baseModelAlias}.actionClass = :actionClass")
            ->andWhere("{$baseModelAlias}.status = :status")
            ->andWhere("{$baseModelAlias}.id != :grantedMandateId")
            ->setParameter('accountId', $grantedAgentMandate->accountId)
            ->setParameter('actionClass', $grantedAgentMandate->actionClass)
            ->setParameter('status', AgentMandate::STATUS_ACTIVE)
            ->setParameter('grantedMandateId', $grantedAgentMandate->id);
        $activeSameClassMandates = $repoClass->find($queryBuilder, false);
        $supersededMandates = [];
        foreach ($activeSameClassMandates->getElements() as $activeMandate) {
            if (!$activeMandate->isSupersededBy($grantedAgentMandate)) {
                continue;
            }
            $activeMandate->status = AgentMandate::STATUS_SUPERSEDED;
            $activeMandate->supersededByMandateId = (int)$grantedAgentMandate->id;
            $this->update($activeMandate);
            $supersededMandates[] = $activeMandate;
        }
        return $supersededMandates;
    }

    /**
     * Deterministic status normalization, called before every list/gate lookup (a no-op when nothing is
     * stale). Two atomic statements, no LLM:
     *  1. a TIME_WINDOW mandate whose validUntil passed flips ACTIVE → EXPIRED (without this nothing ever
     *     sets STATUS_EXPIRED and every list surface showed dead windows as active);
     *  2. a ONE_INSTRUCTION still ACTIVE 24h after its grant flips ACTIVE → CONSUMED — the request it was granted
     *     for is long over; without this sweep an abandoned request (crash, step-cap death, never-retried
     *     failure) left a standing authorization the customer never gave (a live mandate stayed
     *     ACTIVE with no mechanism ever able to end it).
     * Both statements compare with the PHP clock, bound as a parameter: the columns are written from the PHP clock
     * (the grant stamps `validFrom` with it, `validUntil` is a PHP moment), and the database server's local time
     * (NOW()) ended a time window two hours early on a server running on Central European Summer Time.
     * Scoped to ONE account — the gate/list callers always know it; an unfiltered variant would take a
     * full-table row-lock pass on EVERY gated call (13-call batch = 13 table sweeps).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function markExpiredMandates(?int $accountId = null): void
    {
        $tableName = (DBAgentMandate::BASE_ORM_MODEL)::getTableName();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $accountPredicate = $accountId !== null ? ' AND `accountId` = :accountId' : '';
        $accountParameter = $accountId !== null ? ['accountId' => $accountId] : [];
        $now = new DateTime();
        $connection->executeStatement(
            "UPDATE `{$tableName}` SET `status` = :expired WHERE `status` = :active AND `scopeType` = :timeWindow AND `validUntil` IS NOT NULL AND `validUntil` < :now" . $accountPredicate,
            [
                'expired' => AgentMandate::STATUS_EXPIRED,
                'active' => AgentMandate::STATUS_ACTIVE,
                'timeWindow' => AgentMandate::SCOPE_TIME_WINDOW,
                'now' => $now->format(DateTime::SIMPLE),
            ] + $accountParameter
        );
        $connection->executeStatement(
            "UPDATE `{$tableName}` SET `status` = :consumed WHERE `status` = :active AND `scopeType` IN (:oneInstruction, :legacyOneShot) AND `validFrom` < :grantedBefore" . $accountPredicate,
            [
                'consumed' => AgentMandate::STATUS_CONSUMED,
                'active' => AgentMandate::STATUS_ACTIVE,
                'oneInstruction' => AgentMandate::SCOPE_ONE_INSTRUCTION,
                'legacyOneShot' => AgentMandate::LEGACY_SCOPE_ONE_SHOT,
                'grantedBefore' => (clone $now)->modify('-24 hours')->format(DateTime::SIMPLE),
            ] + $accountParameter
        );
    }

    /**
     * Turn-start close-out (the delegation carve-out's safety half): when a NEW
     * customer message starts a root turn, every still-ACTIVE ONE_INSTRUCTION of THIS conversation granted from an
     * EARLIER customer message flips to CONSUMED — one atomic statement, called once per turn from the agent
     * loop. Guarantees that while a request runs, only ITS OWN mandate can be ACTIVE in the conversation —
     * which is what lets delegation children skip the initiator comparison
     * ({@see AgentMandate::authorizesRequest()}) without ever riding a stale grant.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function closeOutOneInstructionMandatesFromEarlierRequests(
        int $accountId,
        int $rootConversationId,
        int $currentTurnInitiatorMessageId,
    ): void {
        $tableName = (DBAgentMandate::BASE_ORM_MODEL)::getTableName();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $connection->executeStatement(
            "UPDATE `{$tableName}` SET `status` = :consumed WHERE `status` = :active AND `scopeType` IN (:oneInstruction, :legacyOneShot) AND `accountId` = :accountId AND `sourceConversationId` = :rootConversationId AND `sourceUserMessageId` <> :currentInitiator",
            [
                'consumed' => AgentMandate::STATUS_CONSUMED,
                'active' => AgentMandate::STATUS_ACTIVE,
                'oneInstruction' => AgentMandate::SCOPE_ONE_INSTRUCTION,
                'legacyOneShot' => AgentMandate::LEGACY_SCOPE_ONE_SHOT,
                'accountId' => $accountId,
                'rootConversationId' => $rootConversationId,
                'currentInitiator' => $currentTurnInitiatorMessageId,
            ]
        );
    }

    /**
     * ONE_INSTRUCTION first-use tool binding — atomic stamp of the FIRST tool that rides the mandate; every later
     * pass requires the same tool ({@see AgentMandate::$coveredToolName}). The CAS writes an UNBOUND row only, so
     * "this call bound it" is one changed row on MariaDB and on PostgreSQL alike (MariaDB counts changed rows,
     * PostgreSQL matched rows — a predicate that also matched the row already bound to the same tool answered 0 on
     * one and 1 on the other). A row that is already bound is told apart by the read that follows: the same tool
     * (a crash + redelivery of the same call) re-binds idempotently, while a DIFFERENT tool of the same class
     * (deletePost consent must not cover publishPost) is refused by the gate.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function bindOneInstructionMandateToTool(AgentMandate $agentMandate, string $toolName): bool
    {
        if (!$agentMandate->hasOneInstructionScope() || !isset($agentMandate->id)) {
            return true;
        }
        $tableName = (DBAgentMandate::BASE_ORM_MODEL)::getTableName();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $bound = (int)$connection->executeStatement(
            "UPDATE `{$tableName}` SET `coveredToolName` = :toolName WHERE `id` = :id AND `status` = :active AND `coveredToolName` IS NULL",
            [
                'toolName' => $toolName,
                'active' => AgentMandate::STATUS_ACTIVE,
                'id' => $agentMandate->id,
            ]
        );
        if ($bound === 1) {
            $agentMandate->coveredToolName = $toolName;
            return true;
        }
        // 0 changed rows: the row is bound already (or not ACTIVE). Re-read to tell the same tool from another —
        // without this, every SECOND call of the same tool on the same mandate was refused as "bound to a
        // different action" (caught by mandate.batch.oneinstruction: 1 of 6 batch deletes passed, 5 refused
        // straight after the grant). The comparison is exact in PHP on both engines.
        $currentCoveredToolName = $connection->fetchOne(
            "SELECT `coveredToolName` FROM `{$tableName}` WHERE `id` = :id",
            ['id' => $agentMandate->id]
        );
        if ($currentCoveredToolName === $toolName) {
            $agentMandate->coveredToolName = $toolName;
            return true;
        }
        return false;
    }

    /**
     * Release the first-use binding a FAILED call left on a ONE_INSTRUCTION mandate — atomic compare-and-set of
     * coveredToolName back to NULL while the mandate is still ACTIVE and bound to exactly that key. A call that did
     * nothing binds nothing: the gate stamps the binding BEFORE the tool runs, and a tool that then throws
     * (e.g. it found nothing to act on) must not lock the customer's consent to the mechanics that
     * failed — the next tool of the same request takes the binding instead.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function releaseOneInstructionMandateBinding(AgentMandate $agentMandate, string $bindingKey): bool
    {
        if (!$agentMandate->hasOneInstructionScope() || !isset($agentMandate->id)) {
            return false;
        }
        $tableName = (DBAgentMandate::BASE_ORM_MODEL)::getTableName();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $released = (int)$connection->executeStatement(
            "UPDATE `{$tableName}` SET `coveredToolName` = NULL WHERE `id` = :id AND `status` = :active AND `coveredToolName` = :key",
            [
                'key' => $bindingKey,
                'active' => AgentMandate::STATUS_ACTIVE,
                'id' => $agentMandate->id,
            ]
        );
        if ($released === 1) {
            $agentMandate->coveredToolName = null;
            return true;
        }
        return false;
    }

    /**
     * Close out a ONE_INSTRUCTION mandate — atomic compare-and-set ACTIVE → CONSUMED; the affected-rows count says
     * whether THIS caller won (idempotent: the loser sees 0 rows and the mandate is closed either way).
     * Called by the gate when a gated call from a LATER customer request meets a ONE_INSTRUCTION bound to an
     * earlier one — never per executed call. Non-ONE_INSTRUCTION mandates are never
     * consumed.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function consumeOneInstructionMandate(AgentMandate $agentMandate): bool
    {
        if (!$agentMandate->hasOneInstructionScope() || !isset($agentMandate->id)) {
            return true;
        }
        $tableName = (DBAgentMandate::BASE_ORM_MODEL)::getTableName();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $won = (int)$connection->executeStatement(
            "UPDATE `{$tableName}` SET `status` = :consumed WHERE `id` = :id AND `status` = :active",
            [
                'consumed' => AgentMandate::STATUS_CONSUMED,
                'active' => AgentMandate::STATUS_ACTIVE,
                'id' => $agentMandate->id,
            ]
        );
        if ($won === 1) {
            $agentMandate->status = AgentMandate::STATUS_CONSUMED;
            return true;
        }
        return false;
    }

    /**
     * Revoke a mandate — deliberately WITHOUT any approval check (revocation is safety-directed; any
     * customer utterance suffices, and the list widget's revoke button lands here too).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws \Doctrine\ORM\Exception\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    /**
     * The agent's revoke (`revokeMandate`): the mandate read rights-scoped (another account's reads as missing;
     * the find throws on a miss when the caller set `throwErrors`), the account re-checked — belt-and-braces against
     * a future rights-bypass caller — and revoked at once. Null when the mandate is not of `$accountId`.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws \Doctrine\ORM\Exception\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function revokeMandateOfAccount(int $accountId, int $mandateId, ?int $revokedByMessageId): ?AgentMandate
    {
        $agentMandate = $this->find($mandateId);
        if ((int)$agentMandate->accountId !== $accountId) {
            return null;
        }
        return $this->revokeMandate($agentMandate, $revokedByMessageId);
    }

    public function revokeMandate(AgentMandate $agentMandate, ?int $revokedByMessageId = null): AgentMandate
    {
        $agentMandate->status = AgentMandate::STATUS_REVOKED;
        $agentMandate->revokedAt = new DateTime();
        $agentMandate->revokedByMessageId = $revokedByMessageId;
        /** @var AgentMandate $revokedAgentMandate */
        $revokedAgentMandate = $this->update($agentMandate);
        // What a revoke changes beyond the mandate itself is the application's (an application stops the standing
        // automation a revoked class authorized — the customer's withdrawal stops the
        // automation itself, not only future grants). Best-effort: an effect's failure must never block the revoke.
        try {
            /** @var AgentMandateRevocationEffectsService $agentMandateRevocationEffectsService */
            $agentMandateRevocationEffectsService = DDDService::instance()->getService(AgentMandateRevocationEffectsService::class);
            $agentMandateRevocationEffectsService->onMandateRevoked($revokedAgentMandate);
        } catch (\Throwable) {
            // best-effort — the revoke itself already persisted; the fire-time re-validation is the structural net
        }
        return $revokedAgentMandate;
    }

    /**
     * The mandates of account `$accountId`, newest first — the list surface for the agent and the widget. Every
     * status is listed. The account is always filtered.
     *
     * - `$agentSpace` null = NO space filter: every mandate of the account, bound to any space or account-wide (the
     *   account's own overview). This is the one read of mandates where "no space" does not narrow to the
     *   account-wide ones — it lists, it never authorizes; the gate asks {@see self::findActiveMandateFor()}.
     * - With a space: the view of that space — the mandates bound to it and the account-wide ones.
     *
     * @param int $accountId
     * @param AgentSpace|null $agentSpace null = no space filter (all mandates of the account)
     * @return AgentMandates
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findMandatesForAccount(int $accountId, ?AgentSpace $agentSpace = null): AgentMandates
    {
        $agentSpaceSqlCondition = $agentSpace !== null ? $this->buildSqlConditionOfMandatesApplyingTo($agentSpace) : null;
        $this->markExpiredMandates($accountId);
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.accountId = :accountId")
            ->setParameter('accountId', $accountId)
            ->addOrderBy("{$baseModelAlias}.id", 'DESC');
        if ($agentSpaceSqlCondition !== null) {
            $this->applyAgentSpaceSqlCondition($queryBuilder, $agentSpaceSqlCondition, $baseModelAlias);
        }
        // Registry cache OFF — the agent lists mandates right after granting/revoking in the same process.
        return $repoClass->find($queryBuilder, false);
    }
}
