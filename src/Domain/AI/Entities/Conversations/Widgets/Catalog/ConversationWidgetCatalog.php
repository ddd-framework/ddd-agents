<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Catalog;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\FindWidgetsTool;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Infrastructure\Services\DDDService;
use ReflectionClass;
use Throwable;

/**
 * The complete catalog of the widgets the model can write, searchable (`findWidgets`). Every widget class
 * of {@see ConversationWidgetClasses} with a {@see ConversationWidget::SUMMARY} is an entry (the classes without one are
 * written by tools, the platform or the frontend only); each entry knows the skills that bring its data — the skill
 * frontmatter `providesWidgetTypes`, and every skill one of whose MCP packages names the widget in its code (a tool that
 * writes or dictates it; the rule the application's widget contract test reads tools by) — the loop's meta tools that
 * write it, and whether the model writes it without a skill ({@see ConversationWidget::IS_WRITABLE_WITHOUT_A_SKILL}).
 *
 * Because the catalog holds every widget the model can write, a query that matches nothing means that no such widget
 * exists. Built once per process ({@see getBuiltCatalog()}); the build reads the widget classes, the skill catalog and
 * the source files of the skills' packages and of the meta tools once.
 *
 * @phpstan-consistent-constructor
 */
class ConversationWidgetCatalog
{
    /** The fewest points an entry needs to count as a match: one word in the summary or in the type name. */
    public const int MIN_MATCH_SCORE = 3;

    /**
     * The fewest points a match needs for `findWidgets` to load the skill that brings its data in the same call — twice
     * a bare match: a single word in a summary is listed, but it does not load a peripheral skill
     * ({@see ConversationWidgetCatalogEntry::isMatchedStronglyEnoughToLoadItsSkill()}).
     */
    public const int MIN_SCORE_TO_LOAD_A_SKILL = 2 * self::MIN_MATCH_SCORE;

    /**
     * Search words that name a widget's FORM, not its data (`PostsList`, `ReviewsSnapshot`, `SingleReview`,
     * `BusinessProfileStatus`, `PostPreview`): they rank a match but never count toward loading its skill
     * ({@see ConversationWidgetCatalogEntry::isMatchedStronglyEnoughToLoadItsSkill()}). `card` and `widget` are ignored
     * search words altogether.
     */
    public const array GENERIC_TYPE_NAME_WORDS = ['list', 'snapshot', 'status', 'single', 'preview', 'card', 'widget'];

    /** The shortest search word; shorter words carry no meaning for the search. */
    public const int MIN_SEARCH_WORD_LENGTH = 3;

    /** Words that occur in every widget text or in every query and decide nothing. */
    public const array IGNORED_SEARCH_WORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'into', 'onto', 'about', 'are', 'was', 'not', 'never',
        'one', 'its', 'their', 'they', 'when', 'what', 'which', 'only', 'also', 'any', 'all', 'each', 'own', 'how',
        'show', 'shows', 'display', 'widget', 'widgets', 'card', 'owner', 'business', 'agent', 'model', 'first', 'line',
        'short', 'prose', 'block', 'write', 'writes', 'answer', 'than', 'then', 'there', 'here', 'our', 'your', 'you',
    ];

    /** @var static|null The catalog of this process, built on first use. */
    protected static ?self $builtCatalog = null;

    /** The entries, sorted by wire type. */
    protected ?ConversationWidgetCatalogEntries $entries = null;

    /** The catalog of this process — built from the code on first use, never again. */
    public static function getBuiltCatalog(): static
    {
        return static::$builtCatalog ??= static::buildFromCode();
    }

    /** The catalog of the widget classes, the skill catalog and the package and meta-tool sources in the code. */
    public static function buildFromCode(): static
    {
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $skillCatalog = $aISkillsService->getCatalog();
        $packageSourceTextsBySkillName = [];
        foreach (AISkill::getPackageClassesBySkillName() as $skillName => $packageClassNames) {
            $packageSourceText = '';
            foreach ($packageClassNames as $packageClassName) {
                $packageSourceText .= static::readSourceTextOfClassAndItsTraits((string)$packageClassName);
            }
            $packageSourceTextsBySkillName[(string)$skillName] = $packageSourceText;
        }
        $metaToolSourceTextsByToolName = [];
        foreach (AgentMetaTools::TOOL_CLASSES as $metaToolClass) {
            if ($metaToolClass === FindWidgetsTool::class) {
                continue; // the search tool names no widget it writes
            }
            $metaToolSourceTextsByToolName[$metaToolClass::NAME] = static::readSourceTextOfClassAndItsTraits($metaToolClass);
        }
        return static::buildFrom(ConversationWidgetClasses::getWidgetClassesByWidgetType(), $skillCatalog, $packageSourceTextsBySkillName, $metaToolSourceTextsByToolName);
    }

    /**
     * The catalog of the given inputs (pure).
     *
     * @param array<string, class-string<ConversationWidget>> $widgetClassesByWidgetType
     * @param array<string, string> $packageSourceTextsBySkillName skill name → the source of its packages
     * @param array<string, string> $metaToolSourceTextsByToolName meta tool name → its source
     */
    public static function buildFrom(array $widgetClassesByWidgetType, AISkills $skillCatalog, array $packageSourceTextsBySkillName, array $metaToolSourceTextsByToolName): static
    {
        $skillNamesProvidingWidgetType = [];
        foreach ($skillCatalog->getElements() as $aiSkill) {
            foreach ($aiSkill->providesWidgetTypes as $providedWidgetType) {
                $skillNamesProvidingWidgetType[$providedWidgetType][] = (string)$aiSkill->name;
            }
        }
        ksort($widgetClassesByWidgetType);
        $conversationWidgetCatalog = new static();
        $conversationWidgetCatalog->entries = new ConversationWidgetCatalogEntries();
        foreach ($widgetClassesByWidgetType as $widgetType => $widgetClass) {
            if ($widgetClass::SUMMARY === '') {
                continue;
            }
            $providingSkillNames = $skillNamesProvidingWidgetType[$widgetType] ?? [];
            foreach ($packageSourceTextsBySkillName as $skillName => $packageSourceText) {
                if (static::sourceTextNamesWidgetType($packageSourceText, (string)$widgetType)) {
                    $providingSkillNames[] = (string)$skillName;
                }
            }
            $writingMetaToolNames = [];
            foreach ($metaToolSourceTextsByToolName as $metaToolName => $metaToolSourceText) {
                if (static::sourceTextNamesWidgetType($metaToolSourceText, (string)$widgetType)) {
                    $writingMetaToolNames[] = (string)$metaToolName;
                }
            }
            $conversationWidgetCatalogEntry = ConversationWidgetCatalogEntry::forWidget(
                (string)$widgetType,
                $widgetClass::SUMMARY,
                $widgetClass::WHEN_TO_USE,
                $providingSkillNames,
                $writingMetaToolNames,
                $widgetClass::IS_WRITABLE_WITHOUT_A_SKILL,
                $widgetClass::EMBEDDABLE ? $widgetClass::EMBEDDED_SIZE_CLASS : ''
            );
            $conversationWidgetCatalog->entries->add($conversationWidgetCatalogEntry);
        }
        return $conversationWidgetCatalog;
    }

    /** Whether the source names the widget as the contract test reads it: quoted (`'QuickReplies'`) or by its class. */
    public static function sourceTextNamesWidgetType(string $sourceText, string $widgetType): bool
    {
        $quotedWidgetType = preg_quote($widgetType, '/');
        return preg_match('/["\'`]' . $quotedWidgetType . '["\'`]|\b' . $quotedWidgetType . ConversationWidgetClasses::WIDGET_CLASS_SHORT_NAME_SUFFIX . '\b/', $sourceText) === 1;
    }

    /** The source text of the class's file and of every trait it uses (recursively); '' for a class that does not load. */
    public static function readSourceTextOfClassAndItsTraits(string $className): string
    {
        try {
            $reflectionClass = new ReflectionClass($className);
        } catch (Throwable) {
            return '';
        }
        $sourceText = '';
        $fileName = $reflectionClass->getFileName();
        if ($fileName !== false && is_readable($fileName)) {
            $sourceText .= (string)file_get_contents($fileName) . "\n";
        }
        foreach ($reflectionClass->getTraitNames() as $traitName) {
            $sourceText .= static::readSourceTextOfClassAndItsTraits($traitName);
        }
        return $sourceText;
    }

    /**
     * The search words of a text: camel case split (`WebSearchResults` → `Web Search Results`), lowercased, split at
     * every non-letter, the ignored and the too short words dropped, a plural `s` cut from a longer word (`results` →
     * `result`), each word once.
     *
     * @return string[]
     */
    public static function splitIntoSearchWords(string $text): array
    {
        $searchWords = [];
        $textWithCamelCaseSplit = (string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $text);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($textWithCamelCaseSplit)) ?: [] as $word) {
            if (mb_strlen($word) < self::MIN_SEARCH_WORD_LENGTH || in_array($word, self::IGNORED_SEARCH_WORDS, true)) {
                continue;
            }
            if (mb_strlen($word) > 4 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
                $word = mb_substr($word, 0, -1);
            }
            $searchWords[$word] = $word;
        }
        return array_values($searchWords);
    }

    /** Every entry, sorted by wire type. */
    public function getEntries(): ConversationWidgetCatalogEntries
    {
        return $this->entries ?? new ConversationWidgetCatalogEntries();
    }

    public function countEntries(): int
    {
        return count($this->getEntries()->getElements());
    }

    /**
     * The entries the query matches, best first (score descending, then wire type), at most `$maxEntries`; empty when the
     * query matches no widget — then none exists for it.
     */
    public function findEntriesMatchingQuery(string $query, int $maxEntries): ConversationWidgetCatalogEntries
    {
        $querySearchWords = static::splitIntoSearchWords($query);
        $scoredEntries = [];
        foreach ($this->getEntries()->getElements() as $conversationWidgetCatalogEntry) {
            $score = $conversationWidgetCatalogEntry->scoreQuery($query, $querySearchWords);
            if ($score >= self::MIN_MATCH_SCORE) {
                $scoredEntries[] = ['score' => $score, 'entry' => $conversationWidgetCatalogEntry];
            }
        }
        usort($scoredEntries, static fn (array $left, array $right): int => [$right['score'], $left['entry']->widgetType] <=> [$left['score'], $right['entry']->widgetType]);
        $matchingEntries = new ConversationWidgetCatalogEntries();
        foreach (array_slice($scoredEntries, 0, max(0, $maxEntries)) as $scoredEntry) {
            $matchingEntry = $scoredEntry['entry'];
            $matchingEntries->add($matchingEntry);
        }
        return $matchingEntries;
    }
}
