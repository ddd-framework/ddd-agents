<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReasons;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalScratchFiles;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolAttributeCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalScopeWithoutOwnedAgentSpaceException;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use RuntimeException;

/**
 * What an application adds to one evaluation run: the scope of the run, how a new conversation is attached to it and
 * when its agent space is stored, what the application's tools left behind and still hold, what it knows about whether
 * a case applies, the arguments the platform fills on a tool call of the space, the channel of a simulated owner
 * device, the memory curation after a turn, the tools that write to a real external profile, the entity a probe call
 * of the scope is billed to, how a schedule an evaluation creates is attached to the scope and the records a fixture
 * names by reference ({@see resolveRecordReference()}), and each case's own workspace ({@see prepareWorkspaceOfCase()},
 * {@see cleanUpWorkspaceOfCase()}).
 *
 * The defaults know only what the module knows: the scope is a stored agent space named by the case or the caller,
 * a conversation gets the scope's stored space, the module never creates a space, a harness tool call gets no argument
 * of the platform's (the space rides on its provenance), no record reference resolves, every scope is eligible, a withheld skill is described by the
 * offer step that withheld it, no requirement is unmet, nothing is torn down, released, prepared or curated, and the
 * external-write tools are the tools that declare an outward action.
 *
 * An application puts a same-path subclass under its own root namespace (and an alias of this id to it in its service
 * configuration); the evaluation runners resolve this service only through
 * `DDDService::instance()->getService(AgentEvalRunHooksService::class)`, at every use.
 *
 * Report lines: a method that takes a progress reporter reports one line per step it took, without indentation, in
 * the form `<step>: [ERROR] <text>` (the marker only for a failed step: a suite reads the marker, never the text). The
 * caller decides how a line is printed.
 */
class AgentEvalRunHooksService extends Service
{
    /**
     * The scope of a run of `$agentEvalCase`: its agent space, the account that owns it, and the application's own
     * values. Called by the runner before anything else of a case (also before the applicability check); the runner
     * authenticates the scope's owner account itself. Reads only: no row is written, no account is authenticated.
     *
     * The default: the stored agent space the case names in `agentSpaceId`, else `$fallbackAgentSpaceId`, read with
     * the rights restrictions lifted (the harness is an operator), and its owner
     * ({@see AgentSpacesService::findOwnerAccountOfAgentSpace()}).
     *
     * @param AgentEvalCase $agentEvalCase the case to run
     * @param int|null $fallbackAgentSpaceId the agent space the caller names for cases that name none
     * @return AgentEvalScope
     * An application that keeps its own scope keys in a case (a space named by a record of its own) resolves them
     * here: a run that names an account asks this method first too ({@see resolveEvalScopeWithFallbackAccount()}).
     *
     * @throws AgentEvalScopeWithoutOwnedAgentSpaceException when the case and the caller name no agent space or its
     *                          owner is not known (a run that names an account then runs for that account)
     * @throws RuntimeException when the space is not stored, or any failure of an application's own resolution
     */
    public function resolveEvalScope(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId = null): AgentEvalScope
    {
        $agentSpaceId = $this->resolveAgentSpaceIdOfCase($agentEvalCase, $fallbackAgentSpaceId);
        if ($agentSpaceId === null) {
            throw new AgentEvalScopeWithoutOwnedAgentSpaceException("The case '{$agentEvalCase->getId()}' names no agent space and the caller gave none.");
        }
        $agentSpacesService = $this->getAgentSpacesService();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentSpace = $agentSpacesService->find($agentSpaceId);
            $ownerAccount = $agentSpace instanceof AgentSpace ? $agentSpacesService->findOwnerAccountOfAgentSpace($agentSpace) : null;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if (!$agentSpace instanceof AgentSpace) {
            throw new RuntimeException("Agent space {$agentSpaceId} not found");
        }
        if (!$ownerAccount instanceof Account) {
            throw new AgentEvalScopeWithoutOwnedAgentSpaceException("Could not resolve the account that owns agent space {$agentSpaceId}");
        }
        return AgentEvalScope::forAgentSpace($agentSpace, $ownerAccount);
    }

    /**
     * The scope of a run of `$agentEvalCase` for a run that names an account (`--accountId`): first the scope hook of
     * every run, {@see resolveEvalScope()} — so an application's own scope keys decide here too; only where it finds no
     * space with a known owner ({@see AgentEvalScopeWithoutOwnedAgentSpaceException}) does the named account step in:
     * a case without a space (neither its own nor `$fallbackAgentSpaceId`) runs at account level for that account, a
     * space without an owner (shared by several accounts) runs for `$fallbackAccountId`. Any other failure of the hook
     * stops the case. Without `$fallbackAccountId` it is {@see resolveEvalScope()} unchanged. Reads only, with the
     * rights restrictions lifted (the harness is an operator).
     *
     * @param AgentEvalCase $agentEvalCase the case to run
     * @param int|null $fallbackAgentSpaceId the agent space the caller names for cases that name none
     * @param int|null $fallbackAccountId the account the caller names for a run at account level or in a space without an owner
     * @return AgentEvalScope
     * @throws RuntimeException when the named space or account is not stored, the space has no owner and no account is
     *                          named, or an application's own resolution failed
     */
    public function resolveEvalScopeWithFallbackAccount(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId, ?int $fallbackAccountId): AgentEvalScope
    {
        if ($fallbackAccountId === null) {
            return $this->resolveEvalScope($agentEvalCase, $fallbackAgentSpaceId);
        }
        try {
            return $this->resolveEvalScope($agentEvalCase, $fallbackAgentSpaceId);
        } catch (AgentEvalScopeWithoutOwnedAgentSpaceException) {
            // no space with a known owner: the named account steps in below
        }
        $agentSpaceId = $this->resolveAgentSpaceIdOfCase($agentEvalCase, $fallbackAgentSpaceId);
        $agentSpacesService = $this->getAgentSpacesService();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $fallbackAccount = $this->findAccountOfEvalScope($fallbackAccountId);
            $agentSpace = $agentSpaceId !== null ? $agentSpacesService->find($agentSpaceId) : null;
            $ownerAccount = $agentSpace instanceof AgentSpace ? $agentSpacesService->findOwnerAccountOfAgentSpace($agentSpace) : null;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if (!$fallbackAccount instanceof Account) {
            throw new RuntimeException("Account {$fallbackAccountId} not found");
        }
        if ($agentSpaceId === null) {
            return AgentEvalScope::forAgentSpace(null, $fallbackAccount);
        }
        if (!$agentSpace instanceof AgentSpace) {
            throw new RuntimeException("Agent space {$agentSpaceId} not found");
        }
        return AgentEvalScope::forAgentSpace($agentSpace, $ownerAccount instanceof Account ? $ownerAccount : $fallbackAccount);
    }

    /**
     * The agent space a case runs in: the space it names by reference (`"agentSpaceId": "{{ref:agentSpace:<name>}}"`,
     * resolved by {@see resolveAgentSpaceReference()}), else the number it names, else `$fallbackAgentSpaceId`.
     *
     * @param AgentEvalCase $agentEvalCase
     * @param int|null $fallbackAgentSpaceId the agent space the caller names for cases that name none
     * @return int|null null when neither the case nor the caller names a space
     * @throws RuntimeException when the case names a space by reference the application does not resolve (the case
     *                          fails with that message, it never falls back to another space)
     */
    public function resolveAgentSpaceIdOfCase(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId = null): ?int
    {
        $agentSpaceName = $agentEvalCase->getAgentSpaceReferenceName();
        if ($agentSpaceName === null) {
            return $agentEvalCase->getAgentSpaceId() ?? $fallbackAgentSpaceId;
        }
        $agentSpaceId = $this->resolveAgentSpaceReference($agentSpaceName);
        if ($agentSpaceId === null || $agentSpaceId <= 0) {
            throw new RuntimeException(sprintf(
                "The case '%s' names the agent space {{ref:%s:%s}}, which does not resolve: no agent space of that name "
                . '(the application resolves the name in AgentEvalRunHooksService::resolveAgentSpaceReference()).',
                $agentEvalCase->getId(),
                AgentEvalCase::AGENT_SPACE_REFERENCE_KIND,
                $agentSpaceName
            ));
        }
        return $agentSpaceId;
    }

    /**
     * The id of the agent space a fixture names by reference (`{{ref:agentSpace:<name>}}`, in `agentSpaceId` or in any
     * other string of a case): the application's word for its spaces, e.g. the admin space of its evaluation account.
     * Resolved before the scope exists (the space IS the scope), so it gets no scope. The default: null — the module
     * names no spaces, so such a case fails until the application resolves it.
     *
     * @param string $agentSpaceName the name the token names, trimmed
     * @return int|null the agent space id; null = no space of that name
     */
    public function resolveAgentSpaceReference(string $agentSpaceName): ?int
    {
        return null;
    }

    /**
     * The account of another evaluation operator a recall seed names (`ownerOperatorKind`): the owner of a seeded
     * conversation the case's owner must never find. Default: none (the case does not apply).
     */
    public function resolveSeedOwnerAccountId(string $ownerOperatorKind): ?int
    {
        return null;
    }

    /**
     * The account a run names (`--accountId`), read as the caller reads it (the rights are lifted by the caller). An
     * application whose account class is its own overrides it. Null for an id without an account.
     */
    protected function findAccountOfEvalScope(int $accountId): ?Account
    {
        /** @var AccountsService $accountsService */
        $accountsService = DDDService::instance()->getService(AccountsService::class);
        $account = $accountsService->find($accountId);
        return $account instanceof Account ? $account : null;
    }

    /**
     * Attaches a NEW conversation the runner assembles in memory (the case's conversation, a seeded one, the probe
     * conversation of the applicability check) to the scope. Writes nothing: a run that stores nothing must not write
     * an agent space row. Called right after the conversation got its account, before its first message.
     *
     * The default: the scope's stored agent space, as the id and as the object, then the relations the agent-spaces
     * service attaches ({@see AgentSpacesService::attachAgentSpaceRelations()}); a scope without a stored space leaves
     * the conversation at account level.
     *
     * @param AIConversation $conversation a conversation without an id
     * @param AgentEvalScope $agentEvalScope
     * @return void
     */
    public function attachNewConversationToScope(AIConversation $conversation, AgentEvalScope $agentEvalScope): void
    {
        $storedAgentSpace = $agentEvalScope->getStoredAgentSpace();
        if ($storedAgentSpace === null) {
            return;
        }
        $conversation->agentSpaceId = $agentEvalScope->getAgentSpaceId();
        $conversation->agentSpace = $storedAgentSpace;
        $agentSpacesService = $this->getAgentSpacesService();
        $agentSpacesService->attachAgentSpaceRelations($conversation, false);
    }

    /**
     * Called right before the runner stores a conversation of the run for the first time (its row references the
     * space by id): the agent space of the scope must be stored now. An application that creates its spaces on first
     * use creates the space here, puts it on the conversation and records it on the scope
     * ({@see AgentEvalScope::recordStoredAgentSpace()}).
     *
     * The default creates nothing (the module never creates a space): a conversation without a space gets the scope's
     * stored space; a scope without one leaves the conversation at account level.
     *
     * @param AIConversation $conversation a conversation without an id, attached with {@see attachNewConversationToScope()}
     * @param AgentEvalScope $agentEvalScope
     * @return void
     */
    public function storeAgentSpaceOfScopeBeforeConversationIsStored(AIConversation $conversation, AgentEvalScope $agentEvalScope): void
    {
        if (isset($conversation->id) || isset($conversation->agentSpaceId)) {
            return;
        }
        $storedAgentSpace = $agentEvalScope->getStoredAgentSpace();
        if ($storedAgentSpace === null) {
            return;
        }
        $conversation->agentSpaceId = $agentEvalScope->getAgentSpaceId();
        $conversation->agentSpace = $storedAgentSpace;
    }

    /**
     * The stored agent space a harness tool call (a preparation, capture, restore or cleanup call, the read of a state
     * check) names in its provenance. Called before the first harness call of a case — only a case with a `restore`
     * block or a state check makes such calls ({@see AgentEvalCase::hasHarnessToolCalls()}), so only such a case
     * stores its space when the run is transient. An application that creates its spaces on first use creates it
     * here and records it on the scope.
     *
     * The default: the scope's stored agent space; null (a call at account level) when it has none.
     *
     * @param AgentEvalScope $agentEvalScope
     * @return AgentSpace|null
     */
    public function findOrStoreAgentSpaceForHarnessToolCalls(AgentEvalScope $agentEvalScope): ?AgentSpace
    {
        return $agentEvalScope->getStoredAgentSpace();
    }

    /**
     * The arguments the platform fills on every tool call made for the scope's agent space, by argument name: a tool
     * of an application that names its target by its own argument (the id of the entity the space stands for) gets it
     * here. The runner puts them in front of the arguments of every harness tool call (a key here is never overwritten
     * by the case, {@see \DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall::buildExecutedArguments()}), and a
     * caller hands them to the expectation check as the arguments the platform filled on an agent call that left them
     * out ({@see \DDD\Domain\AI\Entities\Evals\AgentEvalExpectation::evaluateConversation()}).
     *
     * The default: none — the module's tools take the agent space from the provenance of the call.
     *
     * @param AgentEvalScope $agentEvalScope
     * @return array<string, mixed>
     */
    public function getHarnessToolCallArguments(AgentEvalScope $agentEvalScope): array
    {
        return [];
    }

    /**
     * The value of a record a fixture names by `{{ref:<kind>:<name>}}` ({@see \DDD\Domain\AI\Entities\Evals\AgentEvalRecordReference}),
     * in the scope of the run: typically the id of the record of that kind with that name in the scope's agent space
     * (or account). An int stays an int where the token is a whole string of the case; inside a longer text it is
     * written as text. Called once per kind and name per case, before the case runs.
     *
     * The default: null — the module knows no record kinds, so a case with a reference is not applicable until the
     * application resolves it.
     *
     * @param AgentEvalScope $agentEvalScope the scope the case runs in
     * @param string $recordKind the kind the token names (the application's word)
     * @param string $recordName the name the token names, trimmed
     * @return string|int|null the value; null = no such record in this scope
     */
    public function resolveRecordReference(AgentEvalScope $agentEvalScope, string $recordKind, string $recordName): string|int|null
    {
        return null;
    }

    /**
     * Closes what a STORED conversation of the run (and its delegation children) left of the application's own work
     * and nothing else restores: records it opened that hold a resource for every later conversation, objects it
     * created. Called by the runner's teardown after it closed the schedules and mandates of the tree, for every
     * stored conversation — of an agent space or account-level (`agentSpaceId` unset) — never for a transient one (it
     * leaves no mark on what it created). Never fails the case: a failed step is reported and the next one runs.
     *
     * The default: nothing.
     *
     * @param AIConversation $conversation the stored root of the driven tree
     * @param int[] $conversationIdsOfTree the ids of the conversation and of every delegation child
     * @param AIConversationProgressReporterInterface $progressReporter takes one `restore teardown: …` line per step
     * @return string[] what was closed, one line each (without the `restore teardown:` prefix and the error marker)
     */
    public function tearDownHostResidueOfConversation(
        AIConversation $conversation,
        array $conversationIdsOfTree,
        AIConversationProgressReporterInterface $progressReporter
    ): array {
        return [];
    }

    /**
     * Prepares the case's OWN workspace before the agent runs: creates the workspace `$workspaceKey` empty and writes
     * the case's `scratchFiles` into it. Called by the runner once per case and repetition, before the conversation is
     * assembled, only when the application gives workspaces at all — every run gets a key, the application decides
     * whether it has a workspace for it. The key is `eval-<case>-r<repetition>-<runToken>` (lower case, `[a-z0-9-]`):
     * unique per run, so parallel cases and repetitions never share files or a shell. Never fails the case: a failed
     * step is reported.
     *
     * The default: nothing (the module owns no workspace store).
     *
     * @param AgentEvalScope $agentEvalScope the scope of the case
     * @param string $workspaceKey the case's workspace, as the conversation's tools receive it
     *     ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance::$workspaceKey})
     * @param AgentEvalScratchFiles $agentEvalScratchFiles the files to put in, paths relative to the workspace root
     * @param AIConversationProgressReporterInterface $progressReporter takes one `workspace: …` line per step
     * @return string[] what was prepared, one line each
     */
    public function prepareWorkspaceOfCase(
        AgentEvalScope $agentEvalScope,
        string $workspaceKey,
        AgentEvalScratchFiles $agentEvalScratchFiles,
        AIConversationProgressReporterInterface $progressReporter
    ): array {
        return [];
    }

    /**
     * Removes the case's own workspace `$workspaceKey` with everything in it (files, the shell session, background
     * jobs). Called by the runner at the end of every case that got a workspace, after the case's teardown — also when
     * the run threw, and for a transient conversation. Never fails the case.
     *
     * The default: nothing.
     *
     * @param string $workspaceKey the key {@see prepareWorkspaceOfCase()} was called with
     * @param AIConversationProgressReporterInterface $progressReporter takes one `workspace: …` line per step
     * @return string[] what was removed, one line each
     */
    public function cleanUpWorkspaceOfCase(string $workspaceKey, AIConversationProgressReporterInterface $progressReporter): array
    {
        return [];
    }

    /**
     * Releases what the harness tool calls of a case opened without a conversation and did not close (a restore call
     * that took a hold the closing call was refused for). Called by the runner at the end of a case with harness
     * calls, after the restore, the after-steps and the undo calls — also when the run threw. Never fails the case.
     *
     * The default: nothing.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param DateTime $harnessCallsStartedAt when the first harness call of the case started
     * @param AIConversationProgressReporterInterface $progressReporter takes one `restore release: …` line per step
     * @return string[] what was released, one line each
     */
    public function releaseHostHoldsAfterHarnessCalls(
        AgentEvalScope $agentEvalScope,
        DateTime $harnessCallsStartedAt,
        AIConversationProgressReporterInterface $progressReporter
    ): array {
        return [];
    }

    /**
     * Null when the agent space of the scope is eligible for automation, else its state and the reason as the
     * operator reads them (a locked or suspended space runs nothing). Asked by the applicability check
     * ({@see \DDD\Domain\AI\Entities\Evals\AgentEvalApplicabilityFacts::$ineligibleScopeReason}).
     *
     * The default: every scope is eligible.
     *
     * @param AgentEvalScope $agentEvalScope
     * @return string|null
     */
    public function findIneligibleScopeReason(AgentEvalScope $agentEvalScope): ?string
    {
        return null;
    }

    /**
     * Why the agent space is not offered each skill of `$withheldSkills`, one sentence per skill, by skill name. Asked
     * by the applicability check with the probe conversation of the case (attached to the scope, never stored) and
     * its reachable skill catalog ({@see AIConversation::buildReachableSkillCatalog()}).
     *
     * The default: the first offer step that dropped the skill
     * ({@see OfferedSkillCatalog::$rejectedSkillNamesByStep}), or that no root skill that unlocks it is offered.
     *
     * @param AIConversation $probeConversation the probe conversation of the case
     * @param OfferedSkillCatalog $reachableSkillCatalog everything the probe conversation can reach
     * @param AISkills $withheldSkills the catalog skills the probe conversation cannot reach
     * @return array<string, string> skill name => reason
     */
    public function findWithheldSkillReasons(AIConversation $probeConversation, OfferedSkillCatalog $reachableSkillCatalog, AISkills $withheldSkills): array
    {
        $withheldStepBySkillName = [];
        foreach ($reachableSkillCatalog->rejectedSkillNamesByStep as $offerStep => $rejectedSkillNames) {
            foreach ($rejectedSkillNames as $rejectedSkillName) {
                $withheldStepBySkillName[(string)$rejectedSkillName] ??= (string)$offerStep;
            }
        }
        $withheldSkillReasons = [];
        foreach ($withheldSkills->getElements() as $withheldSkill) {
            $withheldStep = $withheldStepBySkillName[$withheldSkill->name] ?? null;
            $withheldSkillReasons[$withheldSkill->name] = $withheldStep !== null
                ? "the offer step '{$withheldStep}' withholds it"
                : 'no root skill that unlocks it is offered';
        }
        return $withheldSkillReasons;
    }

    /**
     * What the agent space cannot meet now of what `$agentEvalCase` requires, each as its not-applicable reason that
     * names the requirement ({@see AgentEvalNotApplicableReason::forUnmetRequirement()}), one per requirement name, in
     * the order of the case's requirements (the first one decides). The requirement names are
     * the application's: the case's `requires` ({@see AgentEvalCase::getRequirementNames()}) and whatever the
     * application derives from the case's expected tools. A case that tests the refusal at a used-up limit returns
     * none. Asked by the applicability check
     * ({@see \DDD\Domain\AI\Entities\Evals\AgentEvalApplicabilityFacts::$unmetRequirementReasons}); the reason keys
     * are the application's ({@see AgentEvalNotApplicableReason}).
     *
     * The default: none — the module knows no requirement.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalCase $agentEvalCase
     * @return AgentEvalNotApplicableReasons
     */
    public function findUnmetRequirementDetails(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase): AgentEvalNotApplicableReasons
    {
        return AgentEvalNotApplicableReasons::newInstance();
    }

    /**
     * Prepares the conversation for the channel of the simulated owner device before each agent turn the runner
     * drives (a device on a phone call makes the conversation behave as if a call were answered on it:
     * {@see AIConversation::simulateLiveAnsweredCall()} with the application's channel type).
     *
     * The default: nothing.
     *
     * @param AIConversation $conversation the conversation about to be driven
     * @param OwnerDevice|null $simulatedOwnerDevice the device the run's owner messages carry; null = none
     * @return void
     */
    public function prepareConversationChannelBeforeDrive(AIConversation $conversation, ?OwnerDevice $simulatedOwnerDevice): void
    {
    }

    /**
     * Runs the application's memory curation of the conversation in this process, right after a driven turn, when
     * the caller asked for inline curation (the worker that curates in production does not run in a console run). The
     * application opens its own usage envelope for it and keeps what the curation found for its own readers.
     *
     * The default: nothing.
     *
     * @param AIConversation $conversation the stored conversation of the turn that settled
     * @return void
     */
    public function curateMemoryAfterDrivenTurn(AIConversation $conversation): void
    {
    }

    /**
     * The tools whose completed call writes to a real external profile: blocked for the agent's run of every case
     * that does not opt in ({@see AgentEvalCase::resolveBlockedToolNames()}).
     *
     * The default: every tool of the catalog that declares an outward action ({@see MCPToolEffect::$isOutwardAction}).
     * That is "acts outward or needs the owner's explicit yes", which is not the same as "writes to a real external
     * profile": an application narrows or widens it to its own list of the tools that write to the real profiles its
     * evaluation spaces are connected to.
     *
     * @return string[]
     */
    public function getExternalWriteToolNames(): array
    {
        $outwardActionToolNames = [];
        foreach (array_keys(MCPToolAttributeCatalog::getToolMethodsByToolName()) as $toolName) {
            if (MCPToolEffectCatalog::isOutwardActionToolName((string)$toolName)) {
                $outwardActionToolNames[] = (string)$toolName;
            }
        }
        return $outwardActionToolNames;
    }

    /**
     * The entity within the owner's account a model call made for the scope is billed to, when the call belongs to no
     * conversation (a probe of a guard or another single model call of an evaluation command). Reads only.
     *
     * The default: the billing entity of the scope's agent space ({@see AgentSpace::findBillingEntity()}); null (the
     * account is billed) for a scope without a space or a space without one.
     *
     * @param AgentEvalScope $agentEvalScope
     * @return Entity|null
     */
    public function findBillingEntityOfScope(AgentEvalScope $agentEvalScope): ?Entity
    {
        $agentSpace = $agentEvalScope->getAgentSpace();
        return $agentSpace !== null ? $agentSpace->findBillingEntity() : null;
    }

    /**
     * Attaches a NEW schedule an evaluation command creates for the scope (before it is stored) to the scope, as a
     * conversation of the run is attached ({@see attachNewConversationToScope()}).
     *
     * The default: the scope's stored agent space, as the id and as the object; a scope without a stored space leaves
     * the schedule at account level (the module never creates a space).
     *
     * @param AIConversationSchedule $schedule a schedule without an id
     * @param AgentEvalScope $agentEvalScope
     * @return void
     */
    public function attachNewScheduleToScope(AIConversationSchedule $schedule, AgentEvalScope $agentEvalScope): void
    {
        $storedAgentSpace = $agentEvalScope->getStoredAgentSpace();
        if ($storedAgentSpace === null) {
            return;
        }
        $schedule->agentSpaceId = $agentEvalScope->getAgentSpaceId();
        $schedule->agentSpace = $storedAgentSpace;
    }

    /** The agent-spaces service, resolved through the container (an application's same-path subclass included). */
    protected function getAgentSpacesService(): AgentSpacesService
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService;
    }
}
