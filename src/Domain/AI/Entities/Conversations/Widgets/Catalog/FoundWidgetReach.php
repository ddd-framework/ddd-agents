<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Catalog;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * How the model reaches one widget `findWidgets` found, in this conversation: written directly, through a
 * meta tool, through a skill that is loaded already or was loaded by the call, through a skill it may still load, or not
 * at all (its skill is gated away, or no skill brings it). The dispatch decides it; this object renders the line.
 *
 * @phpstan-consistent-constructor
 */
class FoundWidgetReach extends ValueObject
{
    /** The model writes the widget from what it has. */
    public const string TYPE_WRITE_DIRECTLY = 'writeDirectly';

    /** One of the loop's own tools writes it (`askOwner`). */
    public const string TYPE_THROUGH_META_TOOL = 'throughMetaTool';

    /** A skill that brings its data is loaded already. */
    public const string TYPE_SKILL_ALREADY_LOADED = 'skillAlreadyLoaded';

    /** The call loaded the skill that brings its data. */
    public const string TYPE_SKILL_LOADED_NOW = 'skillLoadedNow';

    /** A skill that brings its data passes the gates but was not loaded by this call (a better match took the load). */
    public const string TYPE_SKILL_TO_LOAD = 'skillToLoad';

    /** No skill that brings its data may be loaded here (the gates refused it). */
    public const string TYPE_UNAVAILABLE = 'unavailable';

    /** No skill or tool teaches the widget yet — nothing to load, nothing refused. */
    public const string TYPE_WITHOUT_A_TEACHING_SKILL = 'withoutATeachingSkill';

    #[Choice(choices: [self::TYPE_WRITE_DIRECTLY, self::TYPE_THROUGH_META_TOOL, self::TYPE_SKILL_ALREADY_LOADED, self::TYPE_SKILL_LOADED_NOW, self::TYPE_SKILL_TO_LOAD, self::TYPE_UNAVAILABLE, self::TYPE_WITHOUT_A_TEACHING_SKILL])]
    public string $type = self::TYPE_UNAVAILABLE;

    /** The widget this reach is about. */
    public string $widgetType = '';

    /** @var string[] The skill names to load, root before sub-skill (TYPE_SKILL_TO_LOAD), or the loaded / refused ones. */
    public array $skillNames = [];

    /** The meta tool that writes the widget (TYPE_THROUGH_META_TOOL). */
    public ?string $metaToolName = null;

    /** Why the widget is unavailable — the gates' sentences. */
    public string $unavailableReason = '';

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    public static function writeDirectly(string $widgetType): self
    {
        return self::ofType(self::TYPE_WRITE_DIRECTLY, $widgetType);
    }

    public static function throughMetaTool(string $widgetType, string $metaToolName): self
    {
        $foundWidgetReach = self::ofType(self::TYPE_THROUGH_META_TOOL, $widgetType);
        $foundWidgetReach->metaToolName = $metaToolName;
        return $foundWidgetReach;
    }

    /** @param string[] $skillNames */
    public static function throughSkills(string $type, string $widgetType, array $skillNames): self
    {
        $foundWidgetReach = self::ofType($type, $widgetType);
        $foundWidgetReach->skillNames = array_values($skillNames);
        return $foundWidgetReach;
    }

    public static function withoutATeachingSkill(string $widgetType): self
    {
        return self::ofType(self::TYPE_WITHOUT_A_TEACHING_SKILL, $widgetType);
    }

    /** @param string[] $skillNames the providing skills the gates refused */
    public static function unavailable(string $widgetType, array $skillNames, string $unavailableReason): self
    {
        $foundWidgetReach = self::throughSkills(self::TYPE_UNAVAILABLE, $widgetType, $skillNames);
        $foundWidgetReach->unavailableReason = trim($unavailableReason);
        return $foundWidgetReach;
    }

    protected static function ofType(string $type, string $widgetType): static
    {
        $foundWidgetReach = new static();
        $foundWidgetReach->type = $type;
        $foundWidgetReach->widgetType = $widgetType;
        return $foundWidgetReach;
    }

    /**
     * The names a load of `$providingSkill` needs, root before sub-skill: the skill alone for a root; its root (unless
     * loaded) and then the sub-skill for a sub-skill — a sub-skill is visible only under its loaded root. Null for a
     * sub-skill no skill of `$skillCatalog` lists.
     *
     * @param string[] $loadedSkillNames
     * @return string[]|null
     */
    public static function buildSkillNamesToLoad(AISkill $providingSkill, AISkills $skillCatalog, array $loadedSkillNames): ?array
    {
        if (!$providingSkill->isSubSkill) {
            return [(string)$providingSkill->name];
        }
        $rootSkill = $skillCatalog->findRootSkillListingSubSkill((string)$providingSkill->name);
        if ($rootSkill === null) {
            return null;
        }
        return in_array($rootSkill->name, $loadedSkillNames, true)
            ? [(string)$providingSkill->name]
            : [(string)$rootSkill->name, (string)$providingSkill->name];
    }

    /** The widget can be written in the next step without another load. */
    public function isUsableNow(): bool
    {
        return in_array($this->type, [self::TYPE_WRITE_DIRECTLY, self::TYPE_THROUGH_META_TOOL, self::TYPE_SKILL_ALREADY_LOADED, self::TYPE_SKILL_LOADED_NOW], true);
    }

    /** The one line saying how the model reaches the widget. */
    public function toLlmMarkdownLine(): string
    {
        $skillNamesText = implode(' + ', $this->skillNames);
        return match ($this->type) {
            self::TYPE_WRITE_DIRECTLY => 'usable directly — no skill needed.',
            self::TYPE_THROUGH_META_TOOL => 'written by your tool `' . $this->metaToolName . '`.',
            self::TYPE_SKILL_ALREADY_LOADED => 'its skill ' . $skillNamesText . ' is loaded — usable now.',
            self::TYPE_SKILL_LOADED_NOW => 'loaded its skill ' . $skillNamesText . ' now — its rules and tools are yours from the next step.',
            self::TYPE_SKILL_TO_LOAD => 'brought by skill ' . $skillNamesText . ' (not loaded; `load_skills` with ' . implode(', ', $this->skillNames) . ' loads it).',
            self::TYPE_WITHOUT_A_TEACHING_SKILL => 'no skill teaches this widget yet — show the shape as a ComposedView or answer in prose.',
            default => 'NOT available here' . ($this->unavailableReason !== '' ? ': ' . $this->unavailableReason : '.'),
        };
    }
}
