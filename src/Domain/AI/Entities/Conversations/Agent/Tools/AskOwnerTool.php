<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetDelivery;
use DDD\Domain\AI\Entities\Conversations\Widgets\MultiChoiceConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\OwnerQuestionConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\OwnerQuestionPick;
use DDD\Domain\AI\Entities\Conversations\Widgets\QuickRepliesConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ShownConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ShownConversationWidgets;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;
use stdClass;

/**
 * The `askOwner` meta-tool: a CLOSED question to the owner with the answer
 * variants the MODEL formulates — the same shape as an assistant's own question tool. The model calls it with its
 * question and 2–4 short options (label + optional description), single or multiple choice, then ends its turn on
 * that question in its own words; the PLATFORM renders the options under the final text as a tool widget (a
 * `QuickReplies` widget for a single choice, a `MultiChoice` widget for several), the owner picks or types, and the
 * pick arrives as an owner text row equal to the picked label(s), read by the widget itself
 * ({@see findNewestShownOwnerQuestionWidget()}, {@see readOwnerPickOnShownWidget()}).
 *
 * Nice to have, never enforced: no gate refuses a prose question; the tool replaces the seeded
 * pills the platform used to attach from the plan items (one list per item, the wrong pills under the wrong
 * question). Universal: offered in every purpose next to `load_skills`.
 *
 * The CATALOGUE ({@see AskOwnerQuestionCatalogue}): questions the platform understands. With a `questionKey` the model
 * passes `optionMeanings` — the catalogue meaning of each option in the same order — and the platform records the
 * pick itself (a purpose's draft gate reads a `draftApproval` widget as the presented draft; a tool of the application
 * applies the pick on a question the application added). Count and meanings are validated, the wording never.
 *
 * @phpstan-consistent-constructor
 */
class AskOwnerTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'askOwner';

    /** Neither a read nor a write for the stored-call readers. */
    public const ?string DECLARED_EFFECT = null;

    public const int MIN_OPTIONS = 2;
    public const int MAX_OPTIONS = 4;
    public const int MAX_LABEL_CHARS = 80;
    public const int MAX_DESCRIPTION_CHARS = 160;

    /**
     * The question is ONE question: a whole draft inside it put a second draft on the widget next to the one in
     * the text (the owner approved the widget's draft, the text's was saved). A schema limit like the label's.
     */
    public const int MAX_QUESTION_CHARS = 300;

    /**
     * The fence field that carries the catalogue key on the rendered widget (read back by the platform, ignored by the
     * widget).
     */
    public const string FENCE_FIELD_QUESTION_KEY = 'questionKey';
    /** The fence field that carries the catalogue meanings, one per option in order. */
    public const string FENCE_FIELD_OPTION_MEANINGS = 'optionMeanings';
    /** The fence field that carries the model's question text (the text above repeats it in the model's words). */
    public const string FENCE_FIELD_QUESTION = 'question';
    /** The separator the MultiChoice widget joins the picked labels with (`confirmAction` `{values}`). */
    public const string MULTI_PICK_SEPARATOR = ', ';

    public const string RESULT_RECORDED = 'Recorded — the platform renders your question WITH its options as a widget under your final '
        . 'text. Your final text carries what is new (results, the draft, and the block a read of this turn dictates — the question '
        . 'widget shows under it); do not repeat the question in it. END YOUR TURN; the owner picks an option or types. An option you '
        . 'offer you FOLLOW THROUGH when it is picked — never offer what you would refuse.';

    /** {@see RESULT_RECORDED} when the turn's read dictates a block: `%1$s` is its widget type, `%2$s` the tool's name. */
    public const string RESULT_RECORDED_WITH_DICTATED_BLOCK = 'Recorded — the platform renders your question WITH its options as a widget '
        . 'under your final text. Your final text carries what is new (results, the draft, and the `%1$s` block the `%2$s` result '
        . 'dictates — the question widget shows under it); do not repeat the question in it. END YOUR TURN; the owner picks an option '
        . 'or types. An option you offer you FOLLOW THROUGH when it is picked — never offer what you would refuse.';

    /** The phone line shows no widget — the question lives in the spoken text, the spoken answer is the pick. */
    public const string RESULT_RECORDED_ON_THE_PHONE_LINE = 'Recorded. The owner is on the phone line, where no widget is shown: ask'
        . ' the question in your own final text, the options in one sentence, and end it with a question mark. END YOUR'
        . ' TURN; the owner answers aloud. An option you offer you FOLLOW THROUGH when it is chosen.';

    /** The question in words, for a delivery on the phone line (the dictation note's spoken arm). */
    public const string QUESTION_SPOKEN_INSTRUCTION = 'Ask the question in your own final text, the options in one sentence, and end '
        . 'it with a question mark; the owner answers aloud.';

    /** The description's phone-line sentence. */
    public const string DESCRIPTION_ON_THE_PHONE_LINE = ' ON THE PHONE LINE (now): no widget is shown — the question and its'
        . ' options go into your own text, ending with a question mark.';

    /**
     * The turn-start signal naming the option the owner picked on the model's widget — with the option's description,
     * so the pick reads as the owner's answer to the model's offer, never as a typed wish. Pure.
     *
     * @param string[] $labels @param string[] $descriptions
     */
    public static function buildPickSignalText(array $labels, array $descriptions): string
    {
        $picked = [];
        foreach ($labels as $position => $label) {
            $description = trim((string)($descriptions[$position] ?? ''));
            $picked[] = '"' . $label . '"' . ($description !== '' ? ' (' . $description . ')' : '');
        }
        return 'The owner PICKED ' . implode(' and ', $picked) . ' on your widget — an option you offered. That is their answer: '
            . 'follow it through in this reply (do what the option says, or the next step towards it); never refuse what you offered, '
            . 'never re-ask it.';
    }

    /** {@see buildPickSignalText()} for the pick the widget read ({@see OwnerQuestionConversationWidget::readPickFromFirstOwnerText()}). Pure. */
    public static function buildPickSignalTextForPick(OwnerQuestionPick $ownerQuestionPick): string
    {
        return self::buildPickSignalText($ownerQuestionPick->labels, $ownerQuestionPick->descriptions);
    }

    /**
     * The newest owner-question widget shown (`QuickReplies` / `MultiChoice`) whose block carries an
     * option list: one asking `$questionKey`, else one carrying any catalogue key — with `$acceptKeylessWidgets` (and no
     * `$questionKey`) also one without a key; with `$beforeMessageIndex` only on a host row below it. The newest is the
     * one on the host row with the highest index, the last in that row; a host row without an index is skipped. Null
     * without one. Pure.
     */
    public static function findNewestShownOwnerQuestionWidget(
        ShownConversationWidgets $shownConversationWidgets,
        ?string $questionKey = null,
        ?int $beforeMessageIndex = null,
        bool $acceptKeylessWidgets = false
    ): ?ShownConversationWidget {
        $newestShownOwnerQuestionWidget = null;
        foreach ($shownConversationWidgets->getElements() as $shownConversationWidget) {
            $hostIndexInConversation = $shownConversationWidget->hostIndexInConversation;
            if ($hostIndexInConversation === null || ($beforeMessageIndex !== null && $hostIndexInConversation >= $beforeMessageIndex)
                || !$shownConversationWidget->isOfType(QuickRepliesConversationWidget::WIDGET_TYPE, MultiChoiceConversationWidget::WIDGET_TYPE)
                || !self::blockCarriesOptionList($shownConversationWidget)
            ) {
                continue;
            }
            $ownerQuestionWidget = $shownConversationWidget->buildWidget();
            if (!$ownerQuestionWidget instanceof OwnerQuestionConversationWidget) {
                continue;
            }
            $widgetQuestionKey = $ownerQuestionWidget->getQuestionKey();
            if (($widgetQuestionKey === null && ($questionKey !== null || !$acceptKeylessWidgets))
                || ($questionKey !== null && $widgetQuestionKey !== $questionKey)
            ) {
                continue;
            }
            $newestShownOwnerQuestionWidget = $shownConversationWidget;
        }
        return $newestShownOwnerQuestionWidget;
    }

    /**
     * The owner's pick on a shown owner-question widget: the first owner text after it when that text picks options
     * ({@see OwnerQuestionConversationWidget::readPickFromFirstOwnerText()}); null when the owner typed, has not answered,
     * or the widget is no owner question. Pure.
     */
    public static function readOwnerPickOnShownWidget(ShownConversationWidget $shownOwnerQuestionWidget): ?OwnerQuestionPick
    {
        $ownerQuestionWidget = $shownOwnerQuestionWidget->buildWidget();
        if (!$ownerQuestionWidget instanceof OwnerQuestionConversationWidget) {
            return null;
        }
        return $ownerQuestionWidget->readPickFromFirstOwnerText(...$shownOwnerQuestionWidget->getReactions());
    }

    /**
     * Whether the newest keyed owner question shown asks `$questionKey` and still awaits the owner: no owner text and no
     * click on it since it was shown. A form submit on another widget (a list the owner edits) answers that widget, not
     * this question — its pills stay clickable. False without such a widget or when a newer keyed question superseded
     * it. Pure.
     */
    public static function isNewestKeyedQuestionAwaitingOwner(ShownConversationWidgets $shownConversationWidgets, string $questionKey): bool
    {
        $newestKeyedQuestionWidget = self::findNewestShownOwnerQuestionWidget($shownConversationWidgets);
        if ($newestKeyedQuestionWidget === null) {
            return false;
        }
        $ownerQuestionWidget = $newestKeyedQuestionWidget->buildWidget();
        if (!$ownerQuestionWidget instanceof OwnerQuestionConversationWidget || $ownerQuestionWidget->getQuestionKey() !== $questionKey) {
            return false;
        }
        foreach ($newestKeyedQuestionWidget->getReactions() as $conversationWidgetReaction) {
            if ($ownerQuestionWidget->isReactionToThisWidget($conversationWidgetReaction)
                && !($conversationWidgetReaction->isOwnerText() && trim($conversationWidgetReaction->text) === '')
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * The owner's pick on the newest owner-question widget that carries a catalogue key — `$questionKey`, or any key
     * when null ({@see findNewestShownOwnerQuestionWidget()}, {@see readOwnerPickOnShownWidget()}): null without such a
     * widget or when the owner typed; never an older widget's pick (the newer question superseded it). Pure.
     */
    public static function readOwnerPickOnNewestKeyedQuestion(ShownConversationWidgets $shownConversationWidgets, ?string $questionKey = null): ?OwnerQuestionPick
    {
        $keyedQuestionWidget = self::findNewestShownOwnerQuestionWidget($shownConversationWidgets, $questionKey);
        return $keyedQuestionWidget !== null ? self::readOwnerPickOnShownWidget($keyedQuestionWidget) : null;
    }

    /**
     * Whether the owner's text at `$ownerMessageIndex` is the FIRST non-empty owner text after the widget — the row that
     * answers it (a pill click or a typed answer); a later owner text answers its own question, not the widget. False
     * for a widget on a host row without an index or not before the owner's text. Pure.
     *
     * An owner ACTION on another widget between the widget and the text (a form submit, a confirm, a connection — an
     * owner-authored signal that is no reaction to this widget) answered the question its own way: the owner moved on,
     * and a typed text after it answers what came after (a question answered by the submit of another widget, a text
     * typed two rows later read as the pick on that question, every write it gates refused). A text that picks one of
     * the widget's options still answers it — the pills stay clickable.
     */
    public static function isFirstOwnerTextAfterWidget(ShownConversationWidget $shownOwnerQuestionWidget, int $ownerMessageIndex): bool
    {
        if ($shownOwnerQuestionWidget->hostIndexInConversation === null || $shownOwnerQuestionWidget->hostIndexInConversation >= $ownerMessageIndex) {
            return false;
        }
        $ownerQuestionWidget = $shownOwnerQuestionWidget->buildWidget();
        $ownerActedOnAnotherWidget = false;
        foreach ($shownOwnerQuestionWidget->getReactions() as $conversationWidgetReaction) {
            if ($conversationWidgetReaction->isOwnerText() && trim($conversationWidgetReaction->text) !== '') {
                if ($conversationWidgetReaction->indexInConversation !== $ownerMessageIndex) {
                    return false;
                }
                return !$ownerActedOnAnotherWidget || self::isPickOnWidget($ownerQuestionWidget, $conversationWidgetReaction->text);
            }
            if (!$conversationWidgetReaction->isOwnerText()
                && !($ownerQuestionWidget instanceof OwnerQuestionConversationWidget && $ownerQuestionWidget->isReactionToThisWidget($conversationWidgetReaction))
            ) {
                $ownerActedOnAnotherWidget = true;
            }
        }
        return false;
    }

    /** Whether `$ownerText` picks options of the owner-question widget ({@see OwnerQuestionConversationWidget::buildPickFromOwnerText()}). Pure. */
    protected static function isPickOnWidget(?ConversationWidget $ownerQuestionWidget, string $ownerText): bool
    {
        return $ownerQuestionWidget instanceof OwnerQuestionConversationWidget
            && $ownerQuestionWidget->buildPickFromOwnerText($ownerText)?->isPick() === true;
    }

    /**
     * Whether the owner's text at `$ownerMessageIndex` answered the newest owner-question widget of `$questionKey` before
     * it ({@see findNewestShownOwnerQuestionWidget()}): the text is the first owner text after that widget
     * ({@see isFirstOwnerTextAfterWidget()}; a pill click arrives as its label text, a typed answer as prose — both
     * answer it). False without such a widget. Pure.
     */
    public static function isNewestKeyedQuestionAnsweredByOwnerText(ShownConversationWidgets $shownConversationWidgets, string $questionKey, int $ownerMessageIndex): bool
    {
        $keyedQuestionWidgetBefore = self::findNewestShownOwnerQuestionWidget($shownConversationWidgets, $questionKey, $ownerMessageIndex);
        return $keyedQuestionWidgetBefore !== null && self::isFirstOwnerTextAfterWidget($keyedQuestionWidgetBefore, $ownerMessageIndex);
    }

    /**
     * The keyed owner-question widget the owner's text at `$ownerMessageIndex` answered: the newest keyed widget before
     * the text, when the text is the first owner text after it; null when it answers none. Pure.
     */
    public static function findKeyedQuestionAnsweredByOwnerText(ShownConversationWidgets $shownConversationWidgets, int $ownerMessageIndex): ?ShownConversationWidget
    {
        $keyedQuestionWidgetBefore = self::findNewestShownOwnerQuestionWidget($shownConversationWidgets, null, $ownerMessageIndex);
        return $keyedQuestionWidgetBefore !== null && self::isFirstOwnerTextAfterWidget($keyedQuestionWidgetBefore, $ownerMessageIndex)
            ? $keyedQuestionWidgetBefore
            : null;
    }

    /** Whether the widget's block carries an option list (the old readers skipped a block without one). Pure. */
    public static function blockCarriesOptionList(ShownConversationWidget $shownConversationWidget): bool
    {
        $blockFields = $shownConversationWidget->widgetBlock?->getFields();
        $options = $blockFields !== null ? ($blockFields->options ?? null) : null;
        return is_array($options) || $options instanceof stdClass;
    }
    /**
     * The note under a recorded `draftApproval` question: the widget shows the question only — the draft is the text.
     */
    public const string RESULT_DRAFT_APPROVAL_NOTE = 'A draft approval stands under the FULL draft: every line of the draft is in THIS final text '
        . '(the widget carries only your question and the options). "The draft waits for your feedback" with no draft on the screen presents nothing; '
        . 'after a correction the revised draft is shown again in full.';
    /**
     * An unattended scheduled run asks nobody (2 of 58 lost reports in a measured period: the run waited for an answer
     * that never came). The schema stays on the wire — removing it per conversation would change the cached tool list.
     */
    public const string RESULT_NOBODY_PRESENT = 'Question NOT recorded: nobody is present — this is a scheduled task '
        . 'running on its own and no one will answer. Decide with the information you have, or name the open question '
        . 'in your report so the owner can settle it later.';

    public const string RESULT_ALREADY_ASKED_THIS_TURN = 'Already asked a question this turn — nothing changes. ONE askOwner per turn: '
        . 'end your turn on the question you asked.';

    public static function create(): static
    {
        return new static();
    }

    /**
     * the closed question is RECORDED on the call row (its arguments) — the widget itself is rendered at
     * turn end under the final text ({@see \DDD\Domain\AI\Services\Conversations\AIConversationTurnSignalsService::attachOwnerQuestionWidget()}),
     * never here: a mid-turn widget would stand above the text that asks the question. ONE per turn; a malformed call
     * is told why.
     *
     * @param array<string, mixed> $arguments
     */
    public static function answerCall(array $arguments, AIConversation $conversation): AgentMetaToolCallAnswer
    {
        $askOwnerArgumentsCheck = self::checkArguments($arguments);
        if ($askOwnerArgumentsCheck->feedback !== []) {
            return AgentMetaToolCallAnswer::failed('Question NOT recorded: ' . implode(' ', $askOwnerArgumentsCheck->feedback));
        }
        // the run's own unattended turn; a later turn answers an owner who wrote into the run and may ask
        if ($conversation->getOwnerPresence() === OwnerPresence::ABSENT_SCHEDULED_RUN
            && !$conversation->messages->currentTurnFollowsAnEarlierTurn()
        ) {
            return AgentMetaToolCallAnswer::failed(self::RESULT_NOBODY_PRESENT);
        }
        if ($conversation->messages->buildCurrentTurn()->getNewestCompletedToolCallNamedSinceOwnerInbound(self::NAME) !== null) {
            // a model that re-asks with new wording instead of writing its text (four calls, no text, the turn
            // died) is forced into the tool-less answer step: the question stands, the text is owed
            return AgentMetaToolCallAnswer::failed(self::RESULT_ALREADY_ASKED_THIS_TURN)->withToolLessAnswerStep();
        }
        $resultText = match (true) {
            // a delegation child writes no widget row: the block goes into the child's result
            $conversation->isDelegationChild() => self::buildRecordedResultInADelegationChild($askOwnerArgumentsCheck->question),
            $conversation->isOwnerOnThePhoneLine() => self::RESULT_RECORDED_ON_THE_PHONE_LINE,
            default => self::buildRecordedResult($conversation->messages->buildCurrentTurn()->findOnlyWorkToolCallDictatingAWidgetSinceOwnerInbound()),
        };
        // the child's result ends in the block: a note glued to its closing line would keep the fence open
        $noteSeparator = $conversation->isDelegationChild() ? "\n\n" : ' ';
        if ($askOwnerArgumentsCheck->question?->questionKey === AskOwnerQuestionCatalogue::QUESTION_KEY_DRAFT_APPROVAL) {
            $resultText .= $noteSeparator . self::RESULT_DRAFT_APPROVAL_NOTE;
        }
        if ($askOwnerArgumentsCheck->keyDropped !== null) {
            $resultText .= $noteSeparator . $askOwnerArgumentsCheck->keyDropped;
        }
        // the question is recorded — what the turn still owes is the final text under which the widget renders:
        // the next step runs tool-less (the same lever the repeat guard uses), never another call
        return AgentMetaToolCallAnswer::completed($resultText)->withToolLessAnswerStep();
    }

    /**
     * The recorded answer on a screen: it names the block the turn's read dictates ({@see ToolCall::$dictatedWidgetType}),
     * so the question does not replace it — the owner-question exemption keeps the DICTATED-BLOCK-MISSING stall silent
     * on this turn. Pure.
     */
    public static function buildRecordedResult(?ToolCall $dictatingToolCall): string
    {
        if ($dictatingToolCall === null || ($dictatingToolCall->dictatedWidgetType ?? null) === null) {
            return self::RESULT_RECORDED;
        }
        return sprintf(self::RESULT_RECORDED_WITH_DICTATED_BLOCK, $dictatingToolCall->dictatedWidgetType, (string)$dictatingToolCall->name);
    }

    /**
     * The recorded answer in a delegation child: the platform writes no widget row there, so the
     * question's widget block is dictated into the child's result through the one dictation note
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget::buildDictationNote()}) for the main
     * conversation to show. Pure.
     */
    public static function buildRecordedResultInADelegationChild(?AskOwnerQuestion $askOwnerQuestion): string
    {
        if ($askOwnerQuestion === null) {
            return self::RESULT_RECORDED;
        }
        $ownerQuestionWidget = OwnerQuestionConversationWidget::forAskOwnerQuestion($askOwnerQuestion);
        return 'Recorded. ' . $ownerQuestionWidget->buildDictationNote(ConversationWidgetDelivery::delegationChild(), self::QUESTION_SPOKEN_INSTRUCTION);
    }

    /** Nothing for the loop to do after this tool's call. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return null;
    }

    /** No work request: the tool answers its call itself. */
    public static function workRequest(ToolCall $toolCall): ?AgentToolCallWorkRequest
    {
        return null;
    }

    public function getToolName(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> the OpenAI function-tool wire entry */
    public function toOpenAiToolSchema(bool $ownerIsOnThePhoneLine = false): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Ask the owner a CLOSED question with answer variants: a choice, a yes/no, a confirmation, '
                    . 'an approval. Pass your question and 2–4 short options in the owner\'s language, each a complete answer '
                    . 'the owner could also type; the platform renders the question with its buttons as a widget under your final '
                    . 'text, typing stays possible. Then END YOUR TURN — your text carries what is new, never the question again. '
                    . 'Not for open questions (a name, a '
                    . 'number, an address, free wording) — ask those in prose. ONE call per turn. When the question is one '
                    . 'the platform knows, pass its questionKey and optionMeanings (one catalogue meaning per option, same '
                    . 'order) — the platform records the pick itself: ' . AskOwnerQuestionCatalogue::buildQuestionListOfTheToolDescription() . '.'
                    . ($ownerIsOnThePhoneLine ? self::DESCRIPTION_ON_THE_PHONE_LINE : ''),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string', 'maxLength' => self::MAX_QUESTION_CHARS, 'description' => 'The owner-facing question — ONE sentence, your own words, in the reply language (at most ' . self::MAX_QUESTION_CHARS . ' characters; a draft, results or explanations belong in your final text above the widget)'],
                        'options' => [
                            'type' => 'array',
                            'description' => '2–4 answer variants in the owner\'s language',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'label' => ['type' => 'string', 'description' => 'The button text — a complete short answer'],
                                    'description' => ['type' => 'string', 'description' => 'Optional one-line explanation of the option'],
                                ],
                                'required' => ['label'],
                            ],
                        ],
                        'multiSelect' => ['type' => 'boolean', 'description' => 'true when the owner may pick several options (default false)'],
                        'questionKey' => ['type' => 'string', 'enum' => AskOwnerQuestionCatalogue::getQuestionKeys(), 'description' => 'A catalogue key when the platform knows the question'],
                        'optionMeanings' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'With questionKey: the catalogue meaning of each option, same order as options'],
                    ],
                    'required' => ['question', 'options'],
                ],
            ],
        ];
    }

    /**
     * Strict + corrective validation of the call's arguments (pure). The normalized question is returned next to
     * the feedback; an unknown `questionKey` is dropped (a free question), a known key without matching meanings is
     * refused with the catalogue's meanings named.
     *
     * @param array<string, mixed> $arguments
     */
    public static function checkArguments(array $arguments): AskOwnerArgumentsCheck
    {
        $argumentsCheck = new AskOwnerArgumentsCheck();
        $feedback = [];
        $questionText = trim((string)($arguments['question'] ?? $arguments['text'] ?? ''));
        if ($questionText === '') {
            $feedback[] = "'question' is required — the owner-facing question in the reply language.";
        } elseif (mb_strlen($questionText) > self::MAX_QUESTION_CHARS) {
            $feedback[] = "'question' is ONE question (≤ " . self::MAX_QUESTION_CHARS . ' characters, got ' . mb_strlen($questionText)
                . ') — the draft, results and explanations belong in your final text above the widget.';
        }
        $rawOptions = $arguments['options'] ?? $arguments['choices'] ?? null;
        $options = [];
        if (!is_array($rawOptions)) {
            $feedback[] = "'options' is required — " . self::MIN_OPTIONS . '–' . self::MAX_OPTIONS . ' answer variants, each {label, description?}.';
        } else {
            $seenLabels = [];
            foreach (array_values($rawOptions) as $rawOption) {
                $label = is_array($rawOption) ? trim((string)($rawOption['label'] ?? $rawOption['text'] ?? '')) : trim((string)$rawOption);
                $description = is_array($rawOption) ? trim((string)($rawOption['description'] ?? $rawOption['sublabel'] ?? '')) : '';
                if ($label === '') {
                    $feedback[] = 'Every option needs a non-empty label.';
                    continue;
                }
                if (mb_strlen($label) > self::MAX_LABEL_CHARS) {
                    $feedback[] = 'Option "' . mb_substr($label, 0, 30) . '…" is longer than ' . self::MAX_LABEL_CHARS . ' characters — a label is a short complete answer.';
                    continue;
                }
                $labelKey = mb_strtolower($label);
                if (isset($seenLabels[$labelKey])) {
                    $feedback[] = 'Option "' . $label . '" is listed twice.';
                    continue;
                }
                $seenLabels[$labelKey] = true;
                $options[] = AskOwnerQuestionOption::forLabelAndDescription(
                    $label,
                    $description !== '' ? mb_substr($description, 0, self::MAX_DESCRIPTION_CHARS) : null
                );
            }
            if (count($options) < self::MIN_OPTIONS || count($options) > self::MAX_OPTIONS) {
                $feedback[] = 'Pass ' . self::MIN_OPTIONS . '–' . self::MAX_OPTIONS . ' options (got ' . count($options) . ').';
            }
        }
        $multiSelect = filter_var($arguments['multiSelect'] ?? $arguments['multi_select'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($multiSelect) {
            foreach ($options as $option) {
                if (str_contains($option->label, self::MULTI_PICK_SEPARATOR)) {
                    $feedback[] = 'With multiSelect a label must not contain "' . self::MULTI_PICK_SEPARATOR . '" (the picked labels are joined with it): "' . $option->label . '".';
                }
            }
        }
        $questionKey = trim((string)($arguments['questionKey'] ?? $arguments['question_key'] ?? ''));
        $optionMeanings = null;
        $catalogueMeanings = $questionKey !== '' ? AskOwnerQuestionCatalogue::getMeaningsOfQuestion($questionKey) : null;
        if ($catalogueMeanings === null) {
            $questionKey = ''; // a free question — the platform does not know it
        }
        // A catalogue key whose meanings do not fit the options is NO catalogue question — the question is recorded as a
        // FREE one (the key dropped, the reason in `keyDropped`), never refused: a refusal sent the model into a loop of
        // re-asks with other keys until one shape passed, and that shape then recorded an approval from a click on a
        // name (ten askOwner calls in one turn). A free question records nothing from the click; the
        // owner's answer arrives as their words.
        $keyDropped = null;
        if ($catalogueMeanings !== null) {
            $rawMeanings = $arguments['optionMeanings'] ?? $arguments['option_meanings'] ?? null;
            $optionMeanings = is_array($rawMeanings) ? array_values(array_map(static fn ($meaning): string => trim((string)$meaning), $rawMeanings)) : [];
            $unknownMeanings = array_values(array_diff($optionMeanings, $catalogueMeanings));
            if ($options !== [] && count($optionMeanings) !== count($options)) {
                $keyDropped = "'optionMeanings' must carry one meaning per option, in the same order (" . count($options) . ' options, ' . count($optionMeanings) . ' meanings); the meanings of ' . $questionKey . ': ' . implode(', ', $catalogueMeanings) . '.';
            } elseif ($unknownMeanings !== []) {
                $keyDropped = 'Unknown meaning(s) for ' . $questionKey . ': ' . implode(', ', $unknownMeanings) . ' — the meanings are ' . implode(', ', $catalogueMeanings) . '.';
            } elseif ($multiSelect) {
                $keyDropped = 'A catalogue question (' . $questionKey . ') is a single choice, this one is multiSelect.';
            } elseif (count($options) > 1 && count(array_unique($optionMeanings)) < 2) {
                // every option carries the same catalogue meaning: whatever the owner picks would record that ONE
                // meaning — a choice of names sent under a catalogue key, the same meaning on every option
                $keyDropped = 'Every option of this ' . $questionKey . ' question means "' . ($optionMeanings[0] ?? '') . '" — a catalogue question offers at least two different meanings of ' . $questionKey . ' (' . implode(', ', $catalogueMeanings) . ').';
            }
            if ($keyDropped !== null) {
                $keyDropped = 'Recorded as a FREE question — the catalogue key ' . $questionKey . ' was DROPPED: ' . $keyDropped
                    . ' A choice the platform does not know (names, variants, wording) is a free question: omit questionKey and optionMeanings next time; nothing is recorded from the click, the owner\'s answer arrives as their words.';
                $questionKey = '';
                $optionMeanings = null;
            }
        }
        if ($feedback !== []) {
            $argumentsCheck->feedback = $feedback;
            return $argumentsCheck;
        }
        $askOwnerQuestion = new AskOwnerQuestion();
        $askOwnerQuestion->question = $questionText;
        $askOwnerQuestion->options = $options;
        $askOwnerQuestion->multiSelect = $multiSelect;
        $askOwnerQuestion->questionKey = $questionKey !== '' ? $questionKey : null;
        $askOwnerQuestion->optionMeanings = $questionKey !== '' ? $optionMeanings : null;
        $argumentsCheck->keyDropped = $keyDropped;
        $argumentsCheck->question = $askOwnerQuestion;
        return $argumentsCheck;
    }

    /**
     * The widget's markdown (pure): an optional bold notice (platform text, e.g. a notice of the application under
     * the draft) above the widget fence — `QuickReplies` for a single choice (the click posts the label), `MultiChoice`
     * for several (the confirm posts the labels joined by {@see MULTI_PICK_SEPARATOR}). The fence carries the
     * question text, the catalogue key and the meanings as extra flat fields: the widgets ignore them, the platform's
     * read-back needs them.
     *
     * The REFERENCE rendering of the owner question: the platform writes the widget through
     * {@see \DDD\Domain\AI\Entities\Conversations\Widgets\OwnerQuestionConversationWidget::forAskOwnerQuestion()} and
     * the one writer, whose row is these bytes plus `emittedBy` as the block's last field
     * (`OwnerQuestionWidgetBytesTest` pins it). Still the builder of model-written and fixture rows.
     */
    public static function buildWidgetMarkdown(AskOwnerQuestion $question, ?string $notice = null): string
    {
        $extraFields = [self::FENCE_FIELD_QUESTION => $question->question];
        $questionLine = trim($question->question);
        if ($question->multiSelect) {
            $extraFields['options'] = array_map(static function (AskOwnerQuestionOption $option): array {
                $row = ['label' => $option->label, 'value' => $option->label];
                if ($option->description !== null) {
                    $row['sublabel'] = $option->description;
                }
                return $row;
            }, $question->options);
            $extraFields['confirmAction'] = '{values}';
            $fence = AIConversationMessage::buildWidgetFenceBlock(MultiChoiceConversationWidget::WIDGET_TYPE, null, $extraFields);
        } else {
            $extraFields['options'] = $question->getOptionLabels();
            $descriptions = $question->getOptionDescriptions();
            if (array_filter($descriptions) !== []) {
                $extraFields['descriptions'] = $descriptions;
            }
            if ($question->questionKey !== null) {
                $extraFields[self::FENCE_FIELD_QUESTION_KEY] = $question->questionKey;
                $extraFields[self::FENCE_FIELD_OPTION_MEANINGS] = $question->optionMeanings;
            }
            $fence = AIConversationMessage::buildWidgetFenceBlock(QuickRepliesConversationWidget::WIDGET_TYPE, null, $extraFields);
        }
        $notice = trim((string)($notice ?? ''));
        // the question text stands right above its options (the widgets render the options only), the notice above both
        $widgetMarkdown = ($questionLine !== '' ? $questionLine . "\n\n" : '') . $fence;
        return $notice !== '' ? '**' . $notice . "**\n\n" . $widgetMarkdown : $widgetMarkdown;
    }
}
