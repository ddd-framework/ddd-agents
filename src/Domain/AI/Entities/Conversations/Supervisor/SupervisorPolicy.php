<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SupervisorTools;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a supervisor checkpoint knows about the PURPOSE of the conversation it supervises:
 * the tool lists and texts of the briefing, the FACTS the platform computes, the reads of
 * the live state of the space and the purpose's rules in the acceptance chain. Built by
 * {@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::buildSupervisorPolicy()}; a supervised
 * purpose returns its own subclass from its own domain.
 *
 * This base knows no purpose: no tool digest, no widget list, no FACTS, no space-state reads, no purpose rule — every
 * step-beat steer must pin a write and rest on a FACTS line it does not have, every other intervention passes the
 * purpose rules. Where a rule of the chain refuses an intervention, the result written on the call is the policy's
 * too: a purpose that names its fact kinds, its mandatory question or the tool whose text quotes the owner says so in
 * its own words.
 */
class SupervisorPolicy extends ValueObject
{
    /** The headline of the live-state section of a briefing whose policy names none of its own. */
    public const string DEFAULT_AGENT_SPACE_STATE_HEADLINE = 'STORED STATE (read through the purpose\'s own read tools NOW — what is really stored)';

    public const string DEFAULT_STEP_WITHOUT_FACT_RESULT = 'NO INTERVENTION — a step beat acts only on a FACTS line at your evidence index, with the write that fixes it; everything else is the settled audit\'s';

    public const string DEFAULT_OWNER_QUOTE_MISSING_RESULT = 'NO INTERVENTION — an intervention that hands on the owner\'s words quotes them verbatim from an owner row ("…"); this quote stands in no owner row';

    public const string DEFAULT_QUESTION_NOT_MANDATORY_RESULT = 'NO INTERVENTION — a question to the owner is demanded only for a mandatory question never asked; every other question is the agent\'s';

    /**
     * @return string[] The record kinds a `record:<kind>` evidence of a settled-beat decision may name: the kinds of
     *                  stored state the briefing shows. The base knows the task plan only.
     */
    public function getRecordKinds(): array
    {
        return [SupervisorTools::RECORD_PLAN];
    }

    /** The result written on a step-beat intervention that pins no allowed tool or rests on no FACTS line. */
    public function getStepWithoutFactResult(): string
    {
        return self::DEFAULT_STEP_WITHOUT_FACT_RESULT;
    }

    /** The result written on an intervention {@see interventionQuotesTheOwnerWhereRequired()} refuses. */
    public function getOwnerQuoteMissingResult(): string
    {
        return self::DEFAULT_OWNER_QUOTE_MISSING_RESULT;
    }

    /** The result written on an intervention {@see questionSteerIsAllowed()} refuses. */
    public function getQuestionNotMandatoryResult(): string
    {
        return self::DEFAULT_QUESTION_NOT_MANDATORY_RESULT;
    }

    /** @return string[] The skills whose tools the briefing's static TOOLS digest lists (none = no TOOLS section). */
    public function getToolDigestSkillNames(): array
    {
        return [];
    }

    /** The widget types a tool renders and the owner answers on — the briefing's WIDGETS section ('' = no section). */
    public function getOwnerWidgetTypesDigest(): string
    {
        return '';
    }

    /** @return string[] The reads the briefing's ledger renders in full (every other read is a count). */
    public function getGroundingReadToolNames(): array
    {
        return [];
    }

    /** @return string[] The tools a STEP-beat intervention never pins (a settled beat may). */
    public function getSettledOnlySteerToolNames(): array
    {
        return [];
    }

    /** @return string[] The FACTS line kinds a step-beat intervention may act on (`<KIND> [<index>]` at the line start). */
    public function getStepActionableFactKinds(): array
    {
        return [];
    }

    /** @return SupervisorAgentSpaceStateRead[] The reads of the space's live state for a checkpoint (none = no section). */
    public function getAgentSpaceStateReads(): array
    {
        return [];
    }

    /**
     * The headline of the briefing's section that carries the result of {@see getAgentSpaceStateReads()}. A purpose
     * whose reads show something the application has a name for returns that name; the supervisor's operating prompt
     * of the application refers to the section by it.
     */
    public function getAgentSpaceStateHeadline(): string
    {
        return self::DEFAULT_AGENT_SPACE_STATE_HEADLINE;
    }

    /** The FACTS section of a briefing up to the index ('' = no section). */
    public function buildFactsSection(AIConversation $supervisedConversation, int $asOfMessageIndex): string
    {
        return '';
    }

    /**
     * Whether a step-beat steer may pin this tool: a WRITE (a wrong value, a failed write) or a grounding read run on a
     * wrong seed — never a tool of {@see getSettledOnlySteerToolNames()}. A plain read is dramaturgy the running turn owns.
     */
    public function isStepSteerTool(string $toolName): bool
    {
        if ($toolName === '' || in_array($toolName, $this->getSettledOnlySteerToolNames(), true)) {
            return false;
        }
        return MCPToolEffectCatalog::changesStateForToolName($toolName) || in_array($toolName, $this->getGroundingReadToolNames(), true);
    }

    /**
     * Whether a STEP-beat intervention rests on a deterministic FACT of its briefing at one of its evidence indexes (a
     * line opening with a kind of {@see getStepActionableFactKinds()}, then `[<index>]`).
     *
     * @param int[] $evidenceIndexes
     */
    public function stepDecisionRestsOnAFact(array $evidenceIndexes, string $briefingText): bool
    {
        $stepActionableFactKinds = $this->getStepActionableFactKinds();
        if ($stepActionableFactKinds === []) {
            return false;
        }
        $kinds = implode('|', array_map(static fn (string $kind): string => preg_quote($kind, '/'), $stepActionableFactKinds));
        if (preg_match_all('/^(?:' . $kinds . ') \[(\d+)\]/m', $briefingText, $factMatches) < 1) {
            return false;
        }
        return array_intersect(array_map('intval', $factMatches[1]), array_map('intval', $evidenceIndexes)) !== [];
    }

    /**
     * Whether a step-beat intervention that pins no step-steer tool is allowed anyway (the purpose's question it may
     * demand on a step beat).
     *
     * @param array<int|string, mixed> $violatedCodes
     */
    public function questionSteerIsAllowedOnAStepBeat(string $expectedTool, array $violatedCodes): bool
    {
        return false;
    }

    /**
     * Whether an intervention's text satisfies the purpose's rule on quoting the owner (`$decisionArguments` = the
     * persisted arguments of the terminal call).
     *
     * @param array<string, mixed> $decisionArguments
     */
    public function interventionQuotesTheOwnerWhereRequired(array $decisionArguments, AIConversation $supervisedConversation, int $coveredMessageIndex): bool
    {
        return true;
    }

    /**
     * Whether an intervention may pin this tool under the purpose's question rules.
     *
     * @param array<int|string, mixed> $violatedCodes
     */
    public function questionSteerIsAllowed(string $expectedTool, array $violatedCodes): bool
    {
        return true;
    }
}
