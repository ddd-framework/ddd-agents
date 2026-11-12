<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\Skills\AISkills;

/**
 * The situation-dependent sections of an append-only conversation. The epoch's
 * system content carries the loaded skills and the purpose directive WITHOUT their conditional sections; the sections
 * that apply arrive as appended `# Situation` entries, and the entries of one epoch are read in history order:
 *
 * - the first entry of the epoch (kind `full`) carries the sections of every cut skill and of a cut directive for the
 *   call's situation, with the situation line;
 * - a later call whose situation differs from the newest entry's appends a `full` entry that REPLACES every earlier one —
 *   its header says so and it names the sections that no longer apply ("No longer applies (withdrawn): …");
 * - a later call that loaded a cut skill in the same situation appends an `added` entry with that skill's sections only.
 *
 * An earlier design kept the first call's sections in the system content and appended the new ones as "Situation changed"; the
 * model followed both (in a measured run, 88 % of the calls carried stale sections). Now no stale section stands in
 * the system content, and a superseded entry is named as withdrawn by the entry that replaces it.
 *
 * Mutable state of ONE walk over the epoch's recorded entries ({@see AgentAppendOnlyCall::buildFor()}); pure.
 */
class AgentAppendedSituation
{
    /** The entry carries every cut skill and the cut directive and replaces every earlier entry of the epoch. */
    public const string ENTRY_KIND_FULL = 'full';

    /** The entry carries the sections of the cut skills loaded now, in the newest entry's situation. */
    public const string ENTRY_KIND_ADDED = 'added';

    /** The name an entry records for the purpose directive's sections (beside the skill names). */
    public const string PURPOSE_DIRECTIVE_NAME = '#purposeDirective';

    /** Leads the first entry of an epoch. */
    public const string HEADER_FIRST = '# Situation — the situation-dependent sections of your instructions that apply now';

    /** Leads an entry that replaces the earlier ones. */
    public const string HEADER_REPLACES = '# Situation updated — this entry replaces every earlier "# Situation" entry; only the sections below apply now';

    /** Leads an entry that adds the sections of skills loaded now. */
    public const string HEADER_ADDED = '# Situation — the situation-dependent sections of the skills loaded now; they add to the newest "# Situation" entry';

    /** Leads the line naming the sections a replacing entry withdraws. */
    public const string WITHDRAWN_LINE_PREFIX = 'No longer applies (withdrawn): ';

    /** Whether the epoch carries an entry so far. */
    public bool $hasEntry = false;

    /** The situation of the newest entry; null = none, or an entry rendered without variants. */
    public ?AIConversationVariants $entryVariants = null;

    /** @var string[] The skills (and {@see PURPOSE_DIRECTIVE_NAME}) whose sections the entries so far carry. */
    public array $coveredNames = [];

    /** @var array<string, string> The sections in effect after the entries so far: prefixed id => label. */
    public array $sectionLabelsInEffect = [];

    /**
     * The names whose sections are situation-dependent for a call: the cut purpose directive first, then the cut skills
     * among `$loadedSkillNames` in loaded order.
     *
     * @param string[] $loadedSkillNames
     * @return string[]
     */
    public static function findCutNames(AgentModelInput $agentModelInput, AISkills $resolvedSkills, array $loadedSkillNames): array
    {
        $cutNames = $agentModelInput->purposeDirectiveHasConditionalSections() ? [self::PURPOSE_DIRECTIVE_NAME] : [];
        foreach ($loadedSkillNames as $loadedSkillName) {
            $loadedSkill = $resolvedSkills->findByName($loadedSkillName);
            if ($loadedSkill !== null && $loadedSkill->getSections()->hasConditionalSections()) {
                $cutNames[] = $loadedSkillName;
            }
        }
        return $cutNames;
    }

    /**
     * The kind of entry a call with `$callVariants` and the cut names `$cutNames` appends: `full` when the epoch has no
     * entry yet or the situation differs from the newest entry's, `added` when only cut skills are new; null when nothing
     * is owed.
     *
     * @param string[] $cutNames
     */
    public function decideEntryKindFor(?AIConversationVariants $callVariants, array $cutNames): ?string
    {
        if ($cutNames === []) {
            return null;
        }
        if (!$this->hasEntry || $callVariants?->toSelectorMap() !== $this->entryVariants?->toSelectorMap()) {
            return self::ENTRY_KIND_FULL;
        }
        return array_diff($cutNames, $this->coveredNames) !== [] ? self::ENTRY_KIND_ADDED : null;
    }

    /**
     * The names an entry of `$entryKind` carries: every cut name for a `full` entry, the uncovered ones for `added`.
     *
     * @param string[] $cutNames
     * @return string[]
     */
    public function selectEntryNames(string $entryKind, array $cutNames): array
    {
        return $entryKind === self::ENTRY_KIND_FULL ? $cutNames : array_values(array_diff($cutNames, $this->coveredNames));
    }

    /**
     * Render one entry and take it into the walk's state: the header, for a `full` entry the situation line and the
     * withdrawn sections, then one block per name with its conditional sections for `$entryVariants`.
     *
     * @param string[] $entryNames
     */
    public function renderEntryAndRecordIt(
        AgentModelInput $agentModelInput,
        AISkills $resolvedSkills,
        string $entryKind,
        array $entryNames,
        ?AIConversationVariants $entryVariants
    ): string {
        $entrySectionLabels = [];
        $entryBlocks = [];
        foreach ($entryNames as $entryName) {
            if ($entryName === self::PURPOSE_DIRECTIVE_NAME) {
                $blockText = $agentModelInput->renderPurposeDirectiveConditionalSectionsFor($entryVariants);
                $blockHeading = 'Conversation purpose ' . ($agentModelInput->purposeName ?? '');
                $entrySectionLabels += $agentModelInput->buildPurposeDirectiveSectionLabelsFor($entryVariants);
            } else {
                $entrySkill = $resolvedSkills->findByName($entryName);
                if ($entrySkill === null) {
                    continue;
                }
                $blockText = $entrySkill->renderConditionalSectionsFor($entryVariants);
                $blockHeading = $entryName;
                $entrySectionLabels += $entrySkill->buildConditionalSectionLabelsFor($entryVariants);
            }
            if ($blockText !== '') {
                $entryBlocks[] = '## ' . trim($blockHeading) . "\n" . $blockText;
            }
        }
        $headerLines = [];
        if ($entryKind === self::ENTRY_KIND_ADDED) {
            $headerLines[] = self::HEADER_ADDED;
        } else {
            $headerLines[] = $this->hasEntry ? self::HEADER_REPLACES : self::HEADER_FIRST;
            if ($entryVariants !== null && $entryVariants->count() > 0) {
                $headerLines[] = AgentModelInput::SITUATION_LINE_PREFIX . $entryVariants->toSelectorLine();
            }
            $withdrawnSectionLabels = array_values(array_diff_key($this->sectionLabelsInEffect, $entrySectionLabels));
            if ($this->hasEntry && $withdrawnSectionLabels !== []) {
                $headerLines[] = self::WITHDRAWN_LINE_PREFIX . implode('; ', $withdrawnSectionLabels);
            }
        }
        if ($entryKind === self::ENTRY_KIND_FULL) {
            $this->sectionLabelsInEffect = $entrySectionLabels;
            $this->coveredNames = $entryNames;
            $this->entryVariants = $entryVariants;
        } else {
            $this->sectionLabelsInEffect += $entrySectionLabels;
            $this->coveredNames = array_values(array_unique(array_merge($this->coveredNames, $entryNames)));
        }
        $this->hasEntry = true;
        return implode("\n", $headerLines) . ($entryBlocks !== [] ? "\n\n" . implode("\n\n", $entryBlocks) : '');
    }
}
