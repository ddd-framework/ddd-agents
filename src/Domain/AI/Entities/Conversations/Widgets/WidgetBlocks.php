<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBareSyntaxParser;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockSerializer;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockSourceText;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockSyntaxParser;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetContainerSyntaxParser;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetFenceSyntaxParser;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetGluedFenceOpenerSyntaxParser;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetLeafSyntaxParser;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetOneLineSyntaxParser;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindingResolution;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewShownNumbers;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewBudgets;
use DDD\Domain\Base\Entities\ObjectSet;
use JsonException;
use stdClass;

/**
 * THE widget blocks of a message text: every block in message order, each a parsed-block
 * envelope ({@see WidgetBlock} — span, syntax, closure, outcome, ordinals, and the typed widget through
 * {@see WidgetBlock::buildWidget()}). The text is read by ONE PARSER PER SYNTAX ({@see WidgetBlockSyntaxParser},
 * asked in the order of {@see getSyntaxParsers()} at every line, the first that reads the line wins): the ```/~~~
 * fence, the one-line ```` ```widget {…}``` ````, the bare `widget {…}` paragraph, the unsupported `:::` container,
 * the stored `::Name{payload=…}` leaf and the fence opener glued to prose. Ordinary
 * code fences are text. The rules
 * follow CommonMark and the frontend's two repairs (repairMalformedWidgetFences); the frontend twins are
 * `WIDGET_FENCE_LANG` (widget-fence-syntax.ts), the fence plugin (remarkWidgetFencePlugin.ts) and the shared parity
 * fixture (widget-block-parity.json). The one writer is {@see WidgetBlockSerializer}. Reads nothing.
 * A new syntax = one parser class + its place in {@see getSyntaxParsers()}; its markers extend the database
 * preselection ({@see getMessageTextMarkers()}) by themselves.
 *
 * @property WidgetBlock[] $elements
 * @method WidgetBlock[] getElements()
 * @method WidgetBlock first()
 * @phpstan-consistent-constructor
 */
class WidgetBlocks extends ObjectSet
{
    /** @var string The text the blocks were read from — their spans index into it. */
    protected string $sourceMarkdown = '';

    /** Texts whose content-dependent owner-awaiting answer is kept per process at most. */
    public const int MAX_MEMOIZED_CONTENT_ANSWERS = 256;

    /** The most trailing `}` / `]` a block read drops to decode a block ({@see buildWidgetJsonWithoutSurplusClosers()}). */
    public const int MAX_SURPLUS_CLOSERS = 3;

    /** @var array<string, bool> {@see hasClosedBlockWhoseContentAwaitsOwnerInput()} by md5 of the text */
    protected static array $contentAwaitsOwnerInputByTextHash = [];

    /** Every block of `$markdown`, message order, each with its byte span and outcome. */
    public static function fromMarkdown(string $markdown): static
    {
        $widgetBlocks = new static();
        $widgetBlocks->sourceMarkdown = $markdown;
        $widgetBlockSourceText = WidgetBlockSourceText::fromMarkdown($markdown);
        $syntaxParsers = static::getSyntaxParsers();
        for ($lineIndex = 0, $lineCount = $widgetBlockSourceText->getLineCount(); $lineIndex < $lineCount; $lineIndex++) {
            foreach ($syntaxParsers as $syntaxParser) {
                $widgetBlockSyntaxMatch = $syntaxParser->readBlockAtLine($widgetBlockSourceText, $lineIndex);
                if ($widgetBlockSyntaxMatch === null) {
                    continue;
                }
                if ($widgetBlockSyntaxMatch->carriesBlock) {
                    $widgetBlocks->appendBlock($widgetBlockSyntaxMatch->start, $widgetBlockSyntaxMatch->length, $widgetBlockSyntaxMatch->syntax,
                        $widgetBlockSyntaxMatch->widgetJson, $widgetBlockSyntaxMatch->syntaxOutcome, $widgetBlockSyntaxMatch->isClosed);
                }
                $lineIndex = $widgetBlockSyntaxMatch->lastLineIndex;
                break;
            }
        }
        return $widgetBlocks;
    }

    /**
     * The syntax parsers in the order they are asked at a line: the fence first (it consumes ordinary code
     * fences too, so nothing inside one is read as a widget), the one-line code span before the bare paragraph, the
     * container before the leaf, the glued fence opener last.
     *
     * @return WidgetBlockSyntaxParser[]
     */
    public static function getSyntaxParsers(): array
    {
        return [
            new WidgetFenceSyntaxParser(),
            new WidgetOneLineSyntaxParser(),
            new WidgetBareSyntaxParser(),
            new WidgetContainerSyntaxParser(),
            new WidgetLeafSyntaxParser(),
            new WidgetGluedFenceOpenerSyntaxParser(),
        ];
    }

    /**
     * Substrings every block of every syntax contains, each once (`widget`, `::`) — the database preselection of the
     * widget view loads an assistant row when its text carries one of them; the parsers narrow the superset.
     *
     * @return string[]
     */
    public static function getMessageTextMarkers(): array
    {
        $messageTextMarkers = [];
        foreach (static::getSyntaxParsers() as $syntaxParser) {
            foreach ($syntaxParser->getMessageTextMarkers() as $messageTextMarker) {
                $messageTextMarkers[$messageTextMarker] = $messageTextMarker;
            }
        }
        return array_values($messageTextMarkers);
    }

    /**
     * Whether a VALID block of `$widgetType` ({@see WidgetBlock::isOfType()}) carries every field of
     * `$requiredFilledFields` filled ({@see WidgetBlock::carriesFilledFields()}) — the question the promise-aware
     * handover gate asks the text's blocks.
     *
     * @param array<string, mixed> $requiredFilledFields
     */
    public function hasBlockOfTypeWithFilledFields(string $widgetType, array $requiredFilledFields): bool
    {
        foreach ($this->getElements() as $widgetBlock) {
            if ($widgetBlock->isOfType($widgetType) && $widgetBlock->carriesFilledFields($requiredFilledFields)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The blocks' JSON as written without its trailing line ending, message order, the blocks without JSON (a container,
     * an unreadable leaf, an empty fence) left out.
     *
     * @return string[]
     */
    public function getWidgetJsons(): array
    {
        $widgetJsons = [];
        foreach ($this->getElements() as $widgetBlock) {
            if ($widgetBlock->widgetJson !== '') {
                $widgetJsons[] = rtrim($widgetBlock->widgetJson, "\r\n");
            }
        }
        return $widgetJsons;
    }

    /**
     * The `type` of every VALID block as written (a `…Directive` suffix kept), message order, repeats kept.
     *
     * @return string[]
     */
    public function getWrittenWidgetTypes(): array
    {
        $writtenWidgetTypes = [];
        foreach ($this->getElements() as $widgetBlock) {
            $writtenWidgetType = $widgetBlock->getWrittenWidgetType();
            if ($writtenWidgetType !== null) {
                $writtenWidgetTypes[] = $writtenWidgetType;
            }
        }
        return $writtenWidgetTypes;
    }

    /** The first VALID block of one of `$widgetTypes` ({@see WidgetBlock::isOfType()}), message order, or null. */
    public function findFirstBlockOfTypes(string ...$widgetTypes): ?WidgetBlock
    {
        foreach ($this->getElements() as $widgetBlock) {
            foreach ($widgetTypes as $widgetType) {
                if ($widgetBlock->isOfType($widgetType)) {
                    return $widgetBlock;
                }
            }
        }
        return null;
    }

    /**
     * The lines of the prose that lead into the blocks — when the blocks carry the whole content: at least one widget
     * block holds JSON and every non-empty prose line outside the blocks ends with a colon ("Here's the weather in
     * New Rochelle:", also as `**…:**` or a `#` heading), repeats left out; a line of fence backticks a block left
     * behind is no prose. Empty otherwise. A voice reader of such a text has only headings to say.
     *
     * @return string[]
     */
    public function getLeadInLinesWhenTheBlocksCarryTheContent(): array
    {
        if ($this->getWidgetJsons() === []) {
            return [];
        }
        $leadInLines = [];
        foreach (preg_split('/\R/u', $this->buildMarkdownWithoutBlocks()) ?: [] as $proseLine) {
            $proseLine = trim(trim(ltrim(trim($proseLine), '# ')), '*_ ');
            if ($proseLine === '' || trim($proseLine, '`') === '') {
                continue;
            }
            if (!str_ends_with($proseLine, ':')) {
                return [];
            }
            $leadInLines[$proseLine] = $proseLine;
        }
        return array_values($leadInLines);
    }

    /**
     * The text without its widget blocks — the prose a reader of the words sees: each block's lines become one line
     * break, so the paragraphs around a block stay apart.
     */
    public function buildMarkdownWithoutBlocks(): string
    {
        return $this->buildMarkdownWithBlocksReplaced(static fn (WidgetBlock $widgetBlock): string => "\n");
    }

    /**
     * {@see buildMarkdownWithoutBlocks()} for a reader of the text's Markdown structure: a ``` fence the frontend repairs
     * ends right after its JSON object, mid-line, and backticks glued onto that object (`…}```` followed by nothing but
     * whitespace on the line) become part of the closer the frontend splices in (`repairMalformedWidgetFences`: `}` +
     * a new line + ``` + the glued ``` is one closer line) — so they go with the block instead of opening a fence of
     * their own over the prose that follows.
     */
    public function buildMarkdownWithoutBlocksAndTheirGluedClosers(): string
    {
        $markdown = $this->sourceMarkdown;
        foreach (array_reverse($this->getElements()) as $widgetBlock) {
            $blockEnd = $widgetBlock->start + $widgetBlock->length;
            $endsMidLine = $blockEnd > 0 && $blockEnd < strlen($markdown) && !in_array($markdown[$blockEnd - 1], ["\n", "\r"], true);
            $gluedCloserLength = $endsMidLine && preg_match('/\G`{3,}[ \t]*(?:\r\n|\n|\r|$)/', $markdown, $gluedCloser, 0, $blockEnd) === 1
                ? strlen($gluedCloser[0])
                : 0;
            $markdown = substr_replace($markdown, "\n", $widgetBlock->start, $widgetBlock->length + $gluedCloserLength);
        }
        return $markdown;
    }

    /**
     * The source text with blocks replaced by span: `$replacementTextFor` receives each block and returns its
     * replacement, or null to keep the block. A span covers whole lines, line endings included, so `''` removes the
     * block's lines — except a fence the frontend repairs, which starts at a glued opener's backticks or ends right
     * after its JSON object, mid-line.
     *
     * @param callable(WidgetBlock): ?string $replacementTextFor
     */
    public function buildMarkdownWithBlocksReplaced(callable $replacementTextFor): string
    {
        $markdown = $this->sourceMarkdown;
        foreach (array_reverse($this->getElements()) as $widgetBlock) {
            $replacementText = $replacementTextFor($widgetBlock);
            if ($replacementText !== null) {
                $markdown = substr_replace($markdown, $replacementText, $widgetBlock->start, $widgetBlock->length);
            }
        }
        return $markdown;
    }

    /**
     * The source text with every block's `emittedBy` removed ({@see WidgetBlock::buildWidgetJsonWithoutEmittedBy()}),
     * everything else byte for byte — the text a model-facing reader of a stored row gets (the compaction
     * leaf input, the `loadMessageContext` anchor). A tool row the one writer stored reads exactly as before the field
     * existed; a text without the field comes back unchanged. A block whose JSON is not written out in the text (a
     * stored leaf, whose JSON is re-encoded) keeps its bytes — the writer writes fences only.
     */
    public function buildMarkdownWithoutEmittedBy(): string
    {
        $markdown = $this->sourceMarkdown;
        foreach (array_reverse($this->getElements()) as $widgetBlock) {
            $widgetJsonWithoutEmittedBy = $widgetBlock->buildWidgetJsonWithoutEmittedBy();
            if ($widgetJsonWithoutEmittedBy === null) {
                continue;
            }
            $writtenWidgetJson = rtrim($widgetBlock->widgetJson, "\r\n");
            $widgetJsonOffset = strpos(substr($markdown, $widgetBlock->start, $widgetBlock->length), $writtenWidgetJson);
            if ($widgetJsonOffset === false) {
                continue;
            }
            $markdown = substr_replace($markdown, $widgetJsonWithoutEmittedBy, $widgetBlock->start + $widgetJsonOffset, strlen($writtenWidgetJson));
        }
        return $markdown;
    }

    /**
     * Whether a CLOSED, VALID block names a widget the model writes without a skill
     * ({@see ConversationWidget::IS_WRITABLE_WITHOUT_A_SKILL}: `ComposedView`, `CustomVisualization`, `WeatherForecast`,
     * `Clock`, `WritingDraft`, …) — such a block is a delivered answer on its own. A
     * MALFORMED block, an unclosed one and a block of an unknown type answer false.
     */
    public function hasClosedBlockWritableWithoutASkill(): bool
    {
        foreach ($this->getElements() as $widgetBlock) {
            $widgetClass = $widgetBlock->isClosed ? $widgetBlock->getWidgetClass() : null;
            if ($widgetClass !== null && $widgetClass::IS_WRITABLE_WITHOUT_A_SKILL) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the text holds a block of `$widgetType` that is not closed, or a MALFORMED block (its type cannot be read)
     * — an attempt at the block that renders nothing, as opposed to no block at all.
     */
    public function hasUnclosedBlockOfTypeOrMalformedBlock(string $widgetType): bool
    {
        foreach ($this->getElements() as $widgetBlock) {
            if ($widgetBlock->outcome === WidgetBlock::OUTCOME_MALFORMED || (!$widgetBlock->isClosed && $widgetBlock->isOfType($widgetType))) {
                return true;
            }
        }
        return false;
    }

    /** Whether a CLOSED, VALID block of `$widgetType` (or its legacy `…Directive` form) is in the text. */
    public function hasClosedValidBlockOfType(string $widgetType): bool
    {
        foreach ($this->getElements() as $widgetBlock) {
            if ($widgetBlock->isClosed && $widgetBlock->outcome === WidgetBlock::OUTCOME_VALID && $widgetBlock->isOfType($widgetType)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The findings of every CLOSED, VALID `ComposedView` block that DROP a node or a bound value
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewValidationFindings::CODES_THAT_DROP_A_NODE_OR_A_BINDING}),
     * block order then reading order; a block of two or more prefixes its paths with `block <n>: ` (n from 1).
     *
     * @return string[] one line per finding, `<CODE> at <path>: <message>`
     */
    public function buildComposedViewFindingLinesThatDropANodeOrABinding(): array
    {
        $composedViewBlocks = array_values(array_filter(
            $this->getElements(),
            static fn (WidgetBlock $widgetBlock): bool => $widgetBlock->isClosed && $widgetBlock->outcome === WidgetBlock::OUTCOME_VALID
                && $widgetBlock->isOfType(ComposedViewConversationWidget::WIDGET_TYPE)
        ));
        $findingLines = [];
        foreach ($composedViewBlocks as $blockPosition => $composedViewBlock) {
            $composedViewConversationWidget = $composedViewBlock->buildWidget();
            if (!$composedViewConversationWidget instanceof ComposedViewConversationWidget) {
                continue;
            }
            $blockPrefix = count($composedViewBlocks) > 1 ? 'block ' . ($blockPosition + 1) . ': ' : '';
            foreach ($composedViewConversationWidget->getValidationFindings()->buildFindingsThatDropANodeOrABinding()->getElements() as $composedViewValidationFinding) {
                $findingLines[] = $blockPrefix . $composedViewValidationFinding->toLine();
            }
        }
        return $findingLines;
    }

    /**
     * The text with every closed, valid `ComposedView` block whose tree LIFTS widgets
     * ({@see ComposedViewTreeReading::$liftedWidgetNodes}) replaced by the block without those nodes — or nothing,
     * when the lift took the whole view — followed by one block per lifted widget, in tree order; everything else byte
     * for byte. A lifted widget is then a top-level shown widget like any other: it awaits the owner and is read
     * as its own block. A text without such a block comes back unchanged (one substring check first).
     */
    public function buildMarkdownWithLiftedWidgetBlocks(): string
    {
        $markdown = $this->sourceMarkdown;
        if (!str_contains($markdown, ComposedViewConversationWidget::WIDGET_TYPE)) {
            return $markdown;
        }
        foreach (array_reverse($this->getElements()) as $widgetBlock) {
            $replacementText = $this->buildBlockTextWithLiftedWidgetBlocks($widgetBlock);
            if ($replacementText !== null) {
                $markdown = substr_replace($markdown, $replacementText, $widgetBlock->start, $widgetBlock->length);
            }
        }
        return $markdown;
    }

    /**
     * One block's replacement under the lift: the kept `ComposedView` (when it keeps a view) and the lifted widgets' blocks,
     * one blank line apart, ending as the block's span ended; null when the block lifts nothing or is no closed, valid
     * `ComposedView` within the source budget.
     */
    protected function buildBlockTextWithLiftedWidgetBlocks(WidgetBlock $widgetBlock): ?string
    {
        if (!$widgetBlock->isClosed || $widgetBlock->outcome !== WidgetBlock::OUTCOME_VALID || !$widgetBlock->isOfType(ComposedViewConversationWidget::WIDGET_TYPE)) {
            return null;
        }
        $widgetFields = $widgetBlock->getFieldsAsArray() ?? [];
        if (ComposedViewTreeReading::measureSourceBytes($widgetFields) > ComposedViewBudgets::MAX_SOURCE_BYTES) {
            return null;
        }
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields($widgetFields);
        if ($composedViewTreeReading->liftedWidgetNodes->isEmpty()) {
            return null;
        }
        $keptWidgetFields = $composedViewTreeReading->buildWireFieldsWithoutLiftedNodes($widgetFields);
        unset($keptWidgetFields['type']);
        $replacementBlocks = array_key_exists('view', $keptWidgetFields)
            ? [WidgetBlockSerializer::buildFenceBlock(ComposedViewConversationWidget::WIDGET_TYPE, null, $keptWidgetFields)]
            : [];
        array_push($replacementBlocks, ...$composedViewTreeReading->buildLiftedWidgetBlocks());
        $blockText = substr($this->sourceMarkdown, $widgetBlock->start, $widgetBlock->length);
        $lineEnding = preg_match('/(\r\n|\n|\r)\z/', $blockText, $lineEndingMatch) === 1 ? $lineEndingMatch[1] : '';
        return implode("\n\n", $replacementBlocks) . $lineEnding;
    }

    /**
     * Whether a CLOSED, VALID block of a type whose owner-awaiting depends on its content
     * ({@see ConversationWidget::AWAITS_OWNER_INPUT_DEPENDS_ON_CONTENT}) awaits the owner by what it holds — a
     * `ComposedView` with a `Form`. Builds only such blocks' widgets; the type-level answer for
     * every other type stays with {@see ConversationWidget::AWAITS_OWNER_INPUT}.
     */
    public function hasClosedBlockWhoseContentAwaitsOwnerInput(): bool
    {
        $contentKey = md5($this->sourceMarkdown);
        if (array_key_exists($contentKey, self::$contentAwaitsOwnerInputByTextHash)) {
            return self::$contentAwaitsOwnerInputByTextHash[$contentKey];
        }
        $awaitsOwnerInput = false;
        foreach ($this->getElements() as $widgetBlock) {
            $widgetClass = $widgetBlock->isClosed ? $widgetBlock->getWidgetClass() : null;
            if ($widgetClass !== null && $widgetClass::AWAITS_OWNER_INPUT_DEPENDS_ON_CONTENT && ($widgetBlock->buildWidget()?->awaitsOwnerInput() ?? false)) {
                $awaitsOwnerInput = true;
                break;
            }
        }
        // the answer of a text never changes — the loop asks it several times per step, the tree is read once
        if (count(self::$contentAwaitsOwnerInputByTextHash) >= self::MAX_MEMOIZED_CONTENT_ANSWERS) {
            self::$contentAwaitsOwnerInputByTextHash = [];
        }
        return self::$contentAwaitsOwnerInputByTextHash[$contentKey] = $awaitsOwnerInput;
    }

    /**
     * `$text` as the model reads it again: the `$from` snapshots and resolved entities of its
     * `ComposedView` blocks folded back to what the model wrote
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindingResolution::foldSnapshotsForTheModel()}),
     * the {@see WidgetBlock::LEGACY_WRITTEN_SYNTAX_FIELD} of an old stored fence dropped. A text without a `ComposedView`
     * block is returned as it is (one substring check).
     */
    public static function buildModelFacingText(string $text): string
    {
        if (!str_contains($text, ComposedViewConversationWidget::WIDGET_TYPE)) {
            return $text;
        }
        $widgetBlocks = self::fromMarkdown($text);
        $foldedWidgetJsonsByOrdinal = [];
        foreach ($widgetBlocks->getElements() as $widgetBlock) {
            if ($widgetBlock->outcome !== WidgetBlock::OUTCOME_VALID || !$widgetBlock->isOfType(ComposedViewConversationWidget::WIDGET_TYPE)) {
                continue;
            }
            // an empty object the model wrote (`"fields":{}` of an embedded widget) stays `{}` — decoded to an array it was
            // re-encoded as `[]`, and the model read its own tree back changed (seen in a replayed run)
            $widgetFields = self::decodeJsonKeepingEmptyObjects($widgetBlock->widgetJson);
            if (!is_array($widgetFields)) {
                continue;
            }
            $foldedWidgetFields = ComposedViewBindingResolution::foldSnapshotsForTheModel($widgetFields);
            unset($foldedWidgetFields[WidgetBlock::LEGACY_WRITTEN_SYNTAX_FIELD]);
            $foldedWidgetJson = json_encode($foldedWidgetFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
            if (is_string($foldedWidgetJson)) {
                $foldedWidgetJsonsByOrdinal[$widgetBlock->ordinal] = $foldedWidgetJson;
            }
        }
        return $foldedWidgetJsonsByOrdinal === [] ? $text : $widgetBlocks->buildMarkdownWithWidgetJsonsReplaced($foldedWidgetJsonsByOrdinal);
    }

    /**
     * `$json` decoded to arrays (objects and lists alike) — except an EMPTY object, which stays an empty `\stdClass`, so
     * `json_encode()` writes it back as `{}`, never `[]`. Null for invalid JSON (depth 32).
     */
    public static function decodeJsonKeepingEmptyObjects(string $json): mixed
    {
        $decodedValue = json_decode($json, false, 32);
        if ($decodedValue === null && json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return self::convertDecodedObjectsToArraysExceptEmptyOnes($decodedValue);
    }

    /** One level of {@see decodeJsonKeepingEmptyObjects()}: a non-empty object becomes an array, recursively. */
    protected static function convertDecodedObjectsToArraysExceptEmptyOnes(mixed $decodedValue): mixed
    {
        if ($decodedValue instanceof \stdClass) {
            $objectProperties = get_object_vars($decodedValue);
            if ($objectProperties === []) {
                return $decodedValue;
            }
            $decodedValue = $objectProperties;
        }
        if (!is_array($decodedValue)) {
            return $decodedValue;
        }
        $convertedValue = [];
        foreach ($decodedValue as $key => $nestedValue) {
            $convertedValue[$key] = self::convertDecodedObjectsToArraysExceptEmptyOnes($nestedValue);
        }
        return $convertedValue;
    }

    /**
     * The source text with the written JSON of some blocks replaced (the persist-time `$from`
     * resolution writes its snapshots into the block), everything else byte for byte: `$replacementWidgetJsonsByOrdinal`
     * maps a block's {@see WidgetBlock::$ordinal} to its new JSON. A block whose JSON is not written out in the text (a
     * stored leaf, whose JSON is re-encoded) keeps its bytes, as {@see buildMarkdownWithoutEmittedBy()} does.
     *
     * @param string[] $replacementWidgetJsonsByOrdinal
     */
    public function buildMarkdownWithWidgetJsonsReplaced(array $replacementWidgetJsonsByOrdinal): string
    {
        $markdown = $this->sourceMarkdown;
        foreach (array_reverse($this->getElements()) as $widgetBlock) {
            $replacementWidgetJson = $replacementWidgetJsonsByOrdinal[$widgetBlock->ordinal] ?? null;
            if ($replacementWidgetJson === null) {
                continue;
            }
            $writtenWidgetJson = rtrim($widgetBlock->widgetJson, "\r\n");
            $widgetJsonOffset = $writtenWidgetJson === '' ? false : strpos(substr($markdown, $widgetBlock->start, $widgetBlock->length), $writtenWidgetJson);
            if ($widgetJsonOffset === false) {
                continue;
            }
            $markdown = substr_replace($markdown, $replacementWidgetJson, $widgetBlock->start + $widgetJsonOffset, strlen($writtenWidgetJson));
        }
        return $markdown;
    }

    /**
     * Per block, message order: its canonical type (null when it names none), the syntax it is written in, its outcome
     * and closure — what the eval run records per block of a case's final text (`widgetBlocks`). For a
     * VALID `ComposedView` block also the reader's finding codes and the canonical source bytes (the share of valid
     * trees and the size per tree, `scripts/evals/widgets-report.php`), the `$from` bindings whose snapshot is `failed`
     * (the view renders an empty dataset) and the numbers the tree shows that the prose outside every block repeats
     * ({@see ComposedViewShownNumbers}).
     *
     * @return array<int, array{type: ?string, syntax: string, outcome: string, isClosed: bool, findingCodes: ?string[], sourceBytes: ?int, failedBindingCount: ?int, numbersRepeatedInProse: ?string[]}>
     */
    public function buildBlockSyntaxRecords(): array
    {
        $blockSyntaxRecords = [];
        $proseOutsideBlocks = $this->buildMarkdownWithoutBlocks();
        foreach ($this->getElements() as $widgetBlock) {
            $composedViewFields = $widgetBlock->outcome === WidgetBlock::OUTCOME_VALID && $widgetBlock->isOfType(ComposedViewConversationWidget::WIDGET_TYPE) ? ($widgetBlock->getFieldsAsArray() ?? []) : null;
            $sourceBytes = $composedViewFields !== null ? ComposedViewTreeReading::measureSourceBytes($composedViewFields) : null;
            $blockSyntaxRecords[] = [
                'type' => $widgetBlock->getCanonicalWidgetType() ?? $widgetBlock->getWrittenWidgetType(),
                'syntax' => $widgetBlock->syntax,
                'outcome' => $widgetBlock->outcome,
                'isClosed' => $widgetBlock->isClosed,
                // a ComposedView block: what the tree reader drops or flags, and the canonical bytes the source budget counts
                'findingCodes' => $composedViewFields === null ? null : ($sourceBytes > ComposedViewBudgets::MAX_SOURCE_BYTES
                    ? ComposedViewTreeReading::forBlockOverTheSourceBudget($sourceBytes)
                    : ComposedViewTreeReading::fromWidgetFields($composedViewFields))->findings->getCodes(),
                'sourceBytes' => $sourceBytes,
                'failedBindingCount' => $composedViewFields === null ? null : ComposedViewBindingResolution::countFailedFromBindings($composedViewFields),
                'numbersRepeatedInProse' => $composedViewFields === null ? null : ComposedViewShownNumbers::fromWidgetFields($composedViewFields)->findNumbersRepeatedInProse($proseOutsideBlocks),
            ];
        }
        return $blockSyntaxRecords;
    }

    /**
     * Appends one block; without a syntax outcome the JSON decides — an object with a string `type` is VALID and its
     * fields are decoded here, once.
     */
    protected function appendBlock(int $start, int $length, string $syntax, string $widgetJson, ?string $syntaxOutcome = null, bool $isClosed = true): void
    {
        $widgetBlock = new WidgetBlock();
        $widgetBlock->ordinal = $this->count();
        $widgetBlock->start = $start;
        $widgetBlock->length = $length;
        $widgetBlock->syntax = $syntax;
        $widgetBlock->isClosed = $isClosed;
        $widgetBlock->widgetJson = $widgetJson;
        $widgetBlock->outcome = $syntaxOutcome ?? WidgetBlock::OUTCOME_MALFORMED;
        if ($syntaxOutcome === null) {
            $widgetJsonWithoutSurplusClosers = self::buildWidgetJsonWithoutSurplusClosers($widgetJson);
            if ($widgetJsonWithoutSurplusClosers !== null) {
                // the block is read as the JSON without the model's surplus `}` / `]`; its span stays the written text
                $widgetBlock->widgetJson = $widgetJsonWithoutSurplusClosers;
            }
            try {
                $fields = json_decode($widgetBlock->widgetJson, false, 32, JSON_THROW_ON_ERROR);
                if ($fields instanceof stdClass && is_string($fields->type ?? null)) {
                    $widgetBlock->outcome = WidgetBlock::OUTCOME_VALID;
                    $widgetBlock->setFields($fields);
                    $widgetBlock->ordinalAmongBlocksOfType = $this->countValidBlocksOfType((string)$widgetBlock->getWidgetTypeWithoutLegacySuffix());
                }
            } catch (JsonException) {
                // stays MALFORMED
            }
        }
        $this->add($widgetBlock);
    }

    /**
     * The block JSON without the surplus closing brackets a model wrote at its end (one `}` too many after a correct
     * tree — "Extra data: char 1109"): when `$widgetJson` does not decode, up to {@see MAX_SURPLUS_CLOSERS} trailing `}` /
     * `]` are dropped one by one, and the first text that decodes to a JSON object is the answer. Null when the JSON
     * decodes as written, or when no such text decodes. The frontend twin is `widget-fence-json.ts`
     * (`readWidgetJsonWithoutSurplusClosers`); the parity fixture `composed-view-parity.json` (`surplusCloserCases`)
     * holds both equal.
     */
    public static function buildWidgetJsonWithoutSurplusClosers(string $widgetJson): ?string
    {
        if (self::decodesToAJsonObject($widgetJson)) {
            return null;
        }
        $candidateJson = rtrim($widgetJson);
        for ($droppedCloserCount = 0; $droppedCloserCount < self::MAX_SURPLUS_CLOSERS; $droppedCloserCount++) {
            if ($candidateJson === '' || !in_array($candidateJson[strlen($candidateJson) - 1], ['}', ']'], true)) {
                return null;
            }
            $candidateJson = rtrim(substr($candidateJson, 0, -1));
            if (self::decodesToAJsonObject($candidateJson)) {
                return $candidateJson;
            }
        }
        return null;
    }

    /** Whether the text decodes (depth 32, like every block read) to a JSON object. */
    protected static function decodesToAJsonObject(string $jsonText): bool
    {
        try {
            return json_decode($jsonText, false, 32, JSON_THROW_ON_ERROR) instanceof stdClass;
        } catch (JsonException) {
            return false;
        }
    }

    /** How many VALID blocks of `$widgetTypeWithoutLegacySuffix` the set holds so far ({@see WidgetBlock::getWidgetTypeWithoutLegacySuffix()}). */
    protected function countValidBlocksOfType(string $widgetTypeWithoutLegacySuffix): int
    {
        $validBlockCount = 0;
        foreach ($this->getElements() as $widgetBlock) {
            if ($widgetBlock->getWidgetTypeWithoutLegacySuffix() === $widgetTypeWithoutLegacySuffix) {
                $validBlockCount++;
            }
        }
        return $validBlockCount;
    }
}
