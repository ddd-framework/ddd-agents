<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationChildProfile;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilityOrigin;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Creates and seeds ONE delegation child — the step the model's `delegate` / a tool fan-out
 * ({@see AIConversationDelegationSpawnService}) and the platform's background operations
 * ({@see AIConversationPurposeBackgroundWorkService}) share; neither calls the other. The child inherits the parent's
 * scope, carries the anchor FK to the delegate call, joins the batch, and gets its task as the INITIATOR message with
 * the preloaded skills as the seed's capabilities. The {@see AgentDelegationChildProfile} names what differs per path.
 */
class AIConversationDelegationChildSeedService extends Service
{
    public function createAndSeedDelegationChild(
        AgentRun $agentRun,
        AIConversation $parentConversation,
        AIConversationMessage $delegateCallMessage,
        AgentDelegationChildProfile $childProfile,
        ?AIConversationDelegationBatch $delegationBatch,
        AgentTransientMessageIds $transientMessageIds,
        bool $childEager,
        int $currentUserTurnIndex,
        ?AgentConsoleStream $consoleStream
    ): AIConversation {
        $childConversation = $this->newConversation();
        $childConversation->inheritDelegationScopeFrom($parentConversation, $delegateCallMessage); // sets delegatedFromMessageId
        // The tool-declared child profile (ED3b) overrides what the child inherited: its own step budget and/or a pinned
        // model — the fan-out TOOL knows its children's workload, the parent conversation does not.
        if ($childProfile->maxStepsPerTurn !== null) {
            $childConversation->applyToolDeclaredStepBudget($childProfile->maxStepsPerTurn);
        }
        if ($childProfile->modelName !== null) {
            $childConversation->aiModelNameOverride = $childProfile->modelName;
        }
        // Fork-join membership: stamp the batch this child settles into, so the settle COUNT(*) finds it.
        if ($delegationBatch !== null) {
            $childConversation->delegationBatchId = $delegationBatch->id;
        }
        // The purpose is what routes a mechanical child's drive (zero model calls).
        if ($childProfile->purposeName !== null) {
            $childConversation->purposeName = $childProfile->purposeName;
        }
        // FK-on-the-CHILD model: the child carries the anchor FK, so persist the child ROW (bounded depth — the
        // default recursive update would re-upsert the copied account/space entities). The child's
        // delegatedFromMessageId is written by this update(0).
        if (!$childProfile->writesRowOnlyWhenParentPersisted || isset($parentConversation->id)) {
            $childConversation->update(0);
        }
        $delegateCallMessage->addDelegatedConversation($childConversation);

        // Seed the child with its task as the INITIATOR message (the orchestrator IS this child's user). The
        // orchestrator wrote it, not the owner: role USER, author AGENT ({@see AIConversationMessage::isOrchestratorTaskText()})
        // — it opens the child's turn, but it is never the owner's own words. The preloaded skills become
        // the seed's loadedCapabilities so their tools + instructions are live from turn 1. The shared tree counter
        // keeps ids tree-unique (framework identity is global class_id).
        $seedMessage = $this->messagesService()->appendNewMessage(
            $childConversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_TEXT,
            $childProfile->seedText,
            role: AIConversationMessage::ROLE_USER,
            authorType: AIConversationMessage::AUTHOR_AGENT
        );
        if ($childProfile->seedsCapabilitiesWithoutSkills || $childProfile->skillNames !== []) {
            // same entitlement guard as load_skills: a hallucinated/forced skill name must not smuggle a locked
            // skill into the child (the child shares the parent's space → same entitlement). The vision gate reads the
            // child's model where a fan-out may pin another one, else the parent's. Purpose binding is
            // judged on the PARENT: a child must not receive a purpose-bound skill the parent's purpose does not satisfy.
            $visionGateSubject = $childProfile->visionGateReadsTheChild ? $childConversation : $parentConversation;
            $entitledSkillNames = $parentConversation->findBoundSkillNames(
                $visionGateSubject->findVisionCapableSkillNames($parentConversation->findPermittedSkillNames($childProfile->skillNames)),
                $agentRun
            );
            $seedMessage->loadedCapabilities = $parentConversation->buildSeededCapabilities($entitledSkillNames, $childEager, LoadedCapabilityOrigin::atTurn(0, $currentUserTurnIndex));
        }
        $this->messagesService()->persistMessageUnderRun($agentRun, $seedMessage, $consoleStream);
        return $childConversation;
    }

    /**
     * Construction method for a blank conversation (the delegation child) — overridden in container-less unit tests to
     * reflection-construct it.
     */
    protected function newConversation(): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = AIConversationMessages::newInstance();
        return $conversation;
    }

    /** The messages service — resolved inline (DI rule); a test double replaces it. */
    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }
}
