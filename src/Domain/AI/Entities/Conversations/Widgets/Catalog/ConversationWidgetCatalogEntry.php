<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One widget the model can write, as the widget catalog holds it: its wire type, its one-line summary and
 * its "when to use" line (the class's {@see \DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget::SUMMARY} /
 * `WHEN_TO_USE`), the skills that bring its data, the meta tools that write it, and whether the model writes it without
 * any skill. The search words are computed once when the catalog is built.
 *
 * @phpstan-consistent-constructor
 */
class ConversationWidgetCatalogEntry extends ValueObject
{
    /** Points for a query word found in the widget's type name. */
    public const int SCORE_FOR_A_WORD_IN_THE_TYPE_NAME = 5;

    /** Points for a query word found in the summary. */
    public const int SCORE_FOR_A_WORD_IN_THE_SUMMARY = 3;

    /** Points for a query word found in the "when to use" line. */
    public const int SCORE_FOR_A_WORD_IN_WHEN_TO_USE = 1;

    /** Points for a type name all of whose words the query names (a share of it for a part): the narrower widget wins a tie. */
    public const int SCORE_FOR_THE_WHOLE_TYPE_NAME_COVERED = 4;

    /** Points for a query that names the widget type exactly. */
    public const int SCORE_FOR_THE_EXACT_TYPE_NAME = 100;

    /** The wire type (`ReviewsList`). */
    public string $widgetType = '';

    /** What the widget shows, one line. */
    public string $summary = '';

    /** When the model writes it, one line. */
    public string $whenToUse = '';

    /** @var string[] The skills that bring its data — `providesWidgetTypes`, or a package of the skill whose tool writes or dictates it — sorted. */
    public array $providingSkillNames = [];

    /** @var string[] The loop's meta tools that write it (`askOwner` → `QuickReplies`), sorted. */
    public array $writingMetaToolNames = [];

    /** The model writes it from what it already has: no skill and no tool is needed. */
    public bool $isWritableWithoutASkill = false;

    /**
     * The size class the widget embeds at inside a `ComposedView` (`compact`, `regular`, `full` — its class's
     * `EMBEDDED_SIZE_CLASS`), '' when it does not embed ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget::EMBEDDABLE}).
     */
    public string $embeddedSizeClass = '';

    /** @var string[] The search words of the type name (`ReviewsList` → `review`, `list`). */
    public array $typeNameSearchWords = [];

    /** @var string[] The search words of the summary. */
    public array $summarySearchWords = [];

    /** @var string[] The search words of the "when to use" line. */
    public array $whenToUseSearchWords = [];

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    /**
     * The entry of one widget class's catalog text; the search words are computed here.
     *
     * @param string[] $providingSkillNames
     * @param string[] $writingMetaToolNames
     */
    public static function forWidget(string $widgetType, string $summary, string $whenToUse, array $providingSkillNames, array $writingMetaToolNames, bool $isWritableWithoutASkill, string $embeddedSizeClass = ''): static
    {
        $conversationWidgetCatalogEntry = new static();
        $conversationWidgetCatalogEntry->widgetType = $widgetType;
        $conversationWidgetCatalogEntry->summary = $summary;
        $conversationWidgetCatalogEntry->whenToUse = $whenToUse;
        $providingSkillNames = array_values(array_unique($providingSkillNames));
        sort($providingSkillNames);
        $conversationWidgetCatalogEntry->providingSkillNames = $providingSkillNames;
        $writingMetaToolNames = array_values(array_unique($writingMetaToolNames));
        sort($writingMetaToolNames);
        $conversationWidgetCatalogEntry->writingMetaToolNames = $writingMetaToolNames;
        $conversationWidgetCatalogEntry->isWritableWithoutASkill = $isWritableWithoutASkill;
        $conversationWidgetCatalogEntry->embeddedSizeClass = $embeddedSizeClass;
        $conversationWidgetCatalogEntry->typeNameSearchWords = ConversationWidgetCatalog::splitIntoSearchWords($widgetType);
        $conversationWidgetCatalogEntry->summarySearchWords = ConversationWidgetCatalog::splitIntoSearchWords($summary);
        $conversationWidgetCatalogEntry->whenToUseSearchWords = ConversationWidgetCatalog::splitIntoSearchWords($whenToUse);
        return $conversationWidgetCatalogEntry;
    }

    /**
     * How well the query matches this widget: the exact type name wins outright; otherwise every query word scores in the
     * type name, the summary and the "when to use" line (a word matches an indexed word it equals or starts), and the
     * share of the type name's words the query names adds up to {@see SCORE_FOR_THE_WHOLE_TYPE_NAME_COVERED}.
     *
     * @param string[] $querySearchWords
     */
    public function scoreQuery(string $query, array $querySearchWords): int
    {
        $score = $this->isNamedExactlyBy($query) ? self::SCORE_FOR_THE_EXACT_TYPE_NAME : 0;
        $coveredTypeNameWordCount = 0;
        foreach ($this->typeNameSearchWords as $typeNameSearchWord) {
            foreach ($querySearchWords as $querySearchWord) {
                if (self::containsMatchingWord([$typeNameSearchWord], $querySearchWord)) {
                    $coveredTypeNameWordCount++;
                    break;
                }
            }
        }
        if ($coveredTypeNameWordCount > 0) {
            $score += intdiv(self::SCORE_FOR_THE_WHOLE_TYPE_NAME_COVERED * $coveredTypeNameWordCount, count($this->typeNameSearchWords));
        }
        foreach ($querySearchWords as $querySearchWord) {
            if (self::containsMatchingWord($this->typeNameSearchWords, $querySearchWord)) {
                $score += self::SCORE_FOR_A_WORD_IN_THE_TYPE_NAME;
            }
            if (self::containsMatchingWord($this->summarySearchWords, $querySearchWord)) {
                $score += self::SCORE_FOR_A_WORD_IN_THE_SUMMARY;
            }
            if (self::containsMatchingWord($this->whenToUseSearchWords, $querySearchWord)) {
                $score += self::SCORE_FOR_A_WORD_IN_WHEN_TO_USE;
            }
        }
        return $score;
    }

    /**
     * Whether the query matches this widget strongly enough for `findWidgets` to load the skill that brings its data:
     * at least {@see ConversationWidgetCatalog::MIN_SCORE_TO_LOAD_A_SKILL} points (the exact type name always is),
     * counted WITHOUT the generic type-name words ({@see ConversationWidgetCatalog::GENERIC_TYPE_NAME_WORDS}) — else a
     * query for a list of places would score every `…List` widget on its "list" alone and load an unrelated skill. The
     * ranking of {@see ConversationWidgetCatalog::findEntriesMatchingQuery()} still counts them.
     */
    public function isMatchedStronglyEnoughToLoadItsSkill(string $query): bool
    {
        $querySearchWordsWithoutGenericTypeNameWords = array_values(array_diff(
            ConversationWidgetCatalog::splitIntoSearchWords($query),
            ConversationWidgetCatalog::GENERIC_TYPE_NAME_WORDS
        ));
        return $this->scoreQuery($query, $querySearchWordsWithoutGenericTypeNameWords) >= ConversationWidgetCatalog::MIN_SCORE_TO_LOAD_A_SKILL;
    }

    /** Whether one word of the query is this widget's wire type (case-insensitive) — the query asks for exactly this widget. */
    public function isNamedExactlyBy(string $query): bool
    {
        foreach (preg_split('/[^A-Za-z0-9]+/', $query) ?: [] as $queryToken) {
            if ($queryToken !== '' && strcasecmp($queryToken, $this->widgetType) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether `$indexedSearchWords` holds `$querySearchWord`, or a word it starts (4 letters at least: `book` →
     * `booking`).
     *
     * @param string[] $indexedSearchWords
     */
    public static function containsMatchingWord(array $indexedSearchWords, string $querySearchWord): bool
    {
        foreach ($indexedSearchWords as $indexedSearchWord) {
            if ($indexedSearchWord === $querySearchWord
                || (mb_strlen($querySearchWord) >= 4 && str_starts_with($indexedSearchWord, $querySearchWord))
            ) {
                return true;
            }
        }
        return false;
    }
}
