<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Context;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One labelled, colour-codable piece of an agent model call's context — e.g. the base prompt, one
 * loaded-skill block, the compaction summary, the tool schemas, or one wire message. Built ON DEMAND for admin
 * observability; NEVER persisted. The segment RENDERS ITS OWN human label ({@see self::getLabel()}) from its
 * {@see $type} + the typed provenance fields — the endpoint/FE never reconstructs the label.
 *
 * @phpstan-consistent-constructor
 */
class AgentContextSegment extends ValueObject
{
    public AgentContextSegmentType $type;

    /** The segment's content (the slice of the composed system string, or the wire JSON of a tool/message). */
    public string $text = '';

    /** Token cost of this segment. From an already-stamped exact figure where one exists, else a chars/4 estimate (then {@see $tokenCountIsEstimate} is true). */
    public ?int $tokenCount = null;

    /** True when {@see $tokenCount} is a chars/4 estimate rather than an exact API/stamped figure — the FE marks it `≈`. */
    public bool $tokenCountIsEstimate = false;

    /**
     * @var string[]|null Provenance for {@see AgentContextSegmentType::LOADED_SKILLS}: the conditional skill sections
     *      this segment rendered, skill-prefixed (`Setup.Profile[businessClass=walkIn]`).
     */
    public ?array $skillSectionIds = null;

    /**
     * @var array<string, string>|null Provenance for {@see AgentContextSegmentType::LOADED_SKILLS}: the variants the
     *      sections were selected by, `dimension => value(provenance)`.
     */
    public ?array $variants = null;

    /** @var string[]|null Provenance for {@see AgentContextSegmentType::LOADED_SKILLS}: the dotted skill names whose instructions this segment carries. */
    public ?array $skillNames = null;

    /** Provenance for {@see AgentContextSegmentType::COMPACTION_SUMMARY}: the cover nodes (each carries its level + covered message range) the summary was rendered from. */
    public ?AIConversationCompactionNodes $compactionNodes = null;

    /** Provenance for a wire message segment / an already-fetched read: the originating {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage} index in the conversation. */
    public ?int $sourceMessageIndex = null;

    /** @var string|null PURPOSE_DIRECTIVE provenance: the purpose name. */
    public ?string $purposeName = null;

    /** @var string|null PURPOSE_DIRECTIVE provenance: the purpose this one inherits its plan and rail from, if any. */
    public ?string $purposeInheritsFromName = null;

    public static function of(AgentContextSegmentType $type, string $text): static
    {
        $segment = new static();
        $segment->type = $type;
        $segment->text = $text;
        return $segment;
    }

    /**
     * The human label for this segment, derived from its type + provenance — the object renders itself (the FE shows
     * this verbatim). Compaction names its cover nodes by level + range ("Compaction summary · L2 [0–11] · L1 [12–17]");
     * loaded-skills names the skills.
     */
    public function getLabel(): string
    {
        return match ($this->type) {
            AgentContextSegmentType::BASE_PROMPT => 'System prompt',
            AgentContextSegmentType::SUB_AGENT_DIRECTIVE => 'Sub-agent directive',
            AgentContextSegmentType::PURPOSE_DIRECTIVE => 'Conversation purpose directive'
                . ($this->purposeName !== null && $this->purposeName !== '' ? ': ' . $this->purposeName : '')
                . ($this->purposeInheritsFromName !== null && $this->purposeInheritsFromName !== ''
                    ? ' (inherits ' . $this->purposeInheritsFromName . ')'
                    : ''),
            AgentContextSegmentType::SCHEDULED_RUN_DIRECTIVE => 'Scheduled run directive',
            AgentContextSegmentType::TIME_RULE => 'Time rule (' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ' zone)',
            AgentContextSegmentType::OWNER_PRESENCE => 'Owner presence (who the agent is talking to)',
            AgentContextSegmentType::MEMORY_DIRECTIVES => 'Standing instructions (memory)',
            AgentContextSegmentType::EPISODIC_HISTORY => 'Recent sessions (episodic history)',
            AgentContextSegmentType::DAY_CONTEXT => 'Day context (what the application noted for the day)',
            AgentContextSegmentType::LOCAL_SITUATION => 'Local situation (what goes on around the space)',
            AgentContextSegmentType::PRODUCT_CAPABILITIES => 'Product plan & capabilities',
            AgentContextSegmentType::RESTRICTED_CAPABILITIES => 'Restricted for this user (account rights)',
            AgentContextSegmentType::AI_BUDGET_AWARENESS => 'AI budget awareness',
            AgentContextSegmentType::SKILL_CATALOG => 'Skill catalog (' . count($this->skillNames ?? []) . ' offered)',
            AgentContextSegmentType::META_TOOL_SCHEMAS => 'Meta tool schemas (' . count($this->skillNames ?? []) . ')',
            AgentContextSegmentType::LOADED_SKILLS => ($this->skillNames === null || $this->skillNames === []
                ? 'Loaded skills'
                : 'Loaded skills: ' . implode(', ', $this->skillNames))
                . ($this->skillSectionIds !== null && $this->skillSectionIds !== []
                    ? ' · sections: ' . implode(', ', $this->skillSectionIds)
                    : ''),
            AgentContextSegmentType::QUERY_OPTIONS_SYNTAX => 'QueryOptions syntax',
            AgentContextSegmentType::SHARED_PARAMS => 'Shared request parameters',
            AgentContextSegmentType::ALREADY_FETCHED_READS => 'Data already fetched (reuse)',
            AgentContextSegmentType::TASK_PLAN => 'Task plan',
            AgentContextSegmentType::ANSWER_CONTRACT => 'Answer contract (completed writes)',
            AgentContextSegmentType::COMPACTION_SUMMARY => 'Compaction summary' . $this->describeCompactionNodes(),
            AgentContextSegmentType::TOOL_SCHEMAS => 'Skill tool schemas (' . count($this->skillNames ?? []) . ')',
            AgentContextSegmentType::USER_MESSAGE => 'User message',
            AgentContextSegmentType::ASSISTANT_MESSAGE => 'Assistant reply',
            AgentContextSegmentType::TOOL_CALL => 'Tool call',
            AgentContextSegmentType::TOOL_RESULT => 'Tool result',
            AgentContextSegmentType::SIGNAL => 'Signal',
            AgentContextSegmentType::REPLY_LANGUAGE_CUE => 'Reply-language cue',
        };
    }

    /** " · L2 [0–11] · L0 [12–17]" for the compaction cover nodes, or '' when none. */
    protected function describeCompactionNodes(): string
    {
        if ($this->compactionNodes === null || $this->compactionNodes->count() === 0) {
            return '';
        }
        $parts = [];
        foreach ($this->compactionNodes->getElements() as $compactionNode) {
            $parts[] = sprintf(
                'L%d [%d–%d]',
                $compactionNode->level ?? 0,
                $compactionNode->coveredFromMessageIndex ?? 0,
                $compactionNode->coveredToMessageIndex ?? 0
            );
        }
        return ' · ' . implode(' · ', $parts);
    }
}
