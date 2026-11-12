<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\ConversationWidgetCatalogEntries;
use DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\FoundWidgetReach;
use DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\FoundWidgetReaches;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `findWidgets` meta tool: a search over the COMPLETE catalog of the widgets the model can
 * write ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\ConversationWidgetCatalog}). The answer names the
 * best matches with what each shows and when to use it, and how the model reaches each one here; the dispatch loads the
 * skill that brings the best reachable match's data in the same call, through the gates of `load_skills`, so the widget
 * is usable in the next step. A miss means no such widget exists. Offered on screen surfaces only — a root conversation
 * whose owner reads the chat or talks in the app ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildOfferedTools()}),
 * never on the phone line.
 *
 * @phpstan-consistent-constructor
 */
class FindWidgetsTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'findWidgets';

    /** A search: the effect the stored-call readers see ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog}). */
    public const ?string DECLARED_EFFECT = MCPToolEffect::READ;

    /** A catalog search changes no account data: the turn's earlier reads stay reusable after it ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog}). */
    public const bool KEEPS_EARLIER_READS_REUSABLE = true;

    /** The most matches one answer lists. */
    public const int MAX_LISTED_WIDGETS = 5;

    /** The longest query read; the rest is cut. */
    public const int MAX_QUERY_LENGTH = 200;

    /**
     * The line under an embeddable match: its size class (`%1$s`) and its node form (`%2$s` the
     * wire type) — the kit names only the marker word
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewKitSpec::EMBEDS_MARKER}).
     */
    public const string EMBEDS_LINE = 'embeds: %1$s — inside a ComposedView: {"type":"Widget","widget":"%2$s","fields":{…}}';

    /** The longest summary of a match listed in short form. */
    public const int MAX_SHORT_SUMMARY_LENGTH = 200;

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    public static function create(): static
    {
        return new static();
    }

    /**
     * The call's query, trimmed and cut to {@see MAX_QUERY_LENGTH}; '' when none was sent.
     *
     * @param array<string, mixed> $arguments
     */
    public static function readQueryFromArguments(array $arguments): string
    {
        $query = $arguments['query'] ?? null;
        return is_string($query) ? mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH) : '';
    }

    public static function answerWithoutQuery(): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::failed('Nothing searched: `query` was empty. Name what the widget should show, e.g. "reviews over time" or "competitors on a map".');
    }

    public static function answerOnThePhoneLine(): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::failed('Nothing searched: the owner is on the phone line, where no widget can be shown. Answer in spoken prose.');
    }

    public static function answerWithoutMatch(string $query, int $catalogWidgetCount): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::completed(sprintf(
            'No widget matches "%s". The catalog lists all %d widgets you can write, so no widget exists for this — answer in prose, or compose the data yourself as a ComposedView.',
            $query,
            $catalogWidgetCount
        ));
    }

    /**
     * The answer to a call that found widgets: one numbered entry per match in rank order. The best match and the match
     * whose skill the call loaded carry their full "when to use" line; the others are one line each. The header names the
     * `ComposedView` kit as always available besides the domain widgets, an overview across areas as its KpiTile Grid —
     * or, asked for widgets, a Grid of the embeddable matches; an embeddable match carries {@see EMBEDS_LINE}.
     */
    public static function answerCall(string $query, int $catalogWidgetCount, ConversationWidgetCatalogEntries $matchingEntries, FoundWidgetReaches $foundWidgetReaches): AgentMetaToolCallAnswer
    {
        $matchingEntryList = $matchingEntries->getElements();
        $lines = [sprintf(
            'Widgets for "%s" — %d of the %d you can write (the catalog of domain widgets is complete; the kit — a `ComposedView` tree of any of its nodes (KpiTiles, Chart, Table, Map, Surface, Carousel, Form, Math, Code …) — is always yours besides them; an overview across areas is its KpiTile Grid, or — when the owner asks for widgets — a Grid of the matches marked `embeds`).',
            $query,
            count($matchingEntryList),
            $catalogWidgetCount
        )];
        foreach ($matchingEntryList as $position => $matchingEntry) {
            $foundWidgetReach = $foundWidgetReaches->findByWidgetType($matchingEntry->widgetType) ?? FoundWidgetReach::unavailable($matchingEntry->widgetType, [], '');
            $isListedInFull = $position === 0 || $foundWidgetReach->type === FoundWidgetReach::TYPE_SKILL_LOADED_NOW;
            $summary = $isListedInFull ? $matchingEntry->summary : self::cutText($matchingEntry->summary, self::MAX_SHORT_SUMMARY_LENGTH);
            $lines[] = ($position + 1) . '. ' . $matchingEntry->widgetType . ' — ' . $summary;
            if ($isListedInFull && $matchingEntry->whenToUse !== '') {
                $lines[] = '   When to use: ' . $matchingEntry->whenToUse;
            }
            $lines[] = '   Reach: ' . $foundWidgetReach->toLlmMarkdownLine();
            if ($matchingEntry->embeddedSizeClass !== '') {
                $lines[] = '   ' . sprintf(self::EMBEDS_LINE, $matchingEntry->embeddedSizeClass, $matchingEntry->widgetType);
            }
        }
        return AgentMetaToolCallAnswer::completed(implode("\n", $lines));
    }

    /** `$text` cut at a word to `$maxLength` characters, with an ellipsis when it was cut. */
    public static function cutText(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        $cutText = mb_substr($text, 0, $maxLength);
        $lastSpacePosition = mb_strrpos($cutText, ' ');
        return rtrim($lastSpacePosition !== false ? mb_substr($cutText, 0, $lastSpacePosition) : $cutText, ' ,.;—') . '…';
    }

    /** Nothing for the loop to do after this tool's call. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return null;
    }

    /** No work request: the dispatch searches, loads through the gates and lets the tool answer. */
    public static function workRequest(ToolCall $toolCall): ?AgentToolCallWorkRequest
    {
        return null;
    }

    public function getToolName(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> the OpenAI function-tool wire entry */
    public function toOpenAiToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Search the complete catalog of chat widgets by what your answer should show; returns the '
                    . 'matches with when to use each and loads the skill that brings the best match\'s data.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['query'],
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'What the widget should show, in English keywords (e.g. "reviews over time", "competitors on a map").',
                        ],
                    ],
                ],
            ],
        ];
    }
}
