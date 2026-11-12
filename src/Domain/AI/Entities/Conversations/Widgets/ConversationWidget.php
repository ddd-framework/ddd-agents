<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockSerializer;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;
use JsonException;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * A widget the chat renders: its wire type, the key of the agent space it is scoped to and its typed fields. Every
 * concrete widget is `<WireName>ConversationWidget` in the domain of the data it shows
 * (`<Domain>/Entities/AI/Conversations/Widgets/`), named like its frontend component. Every frontend widget type has
 * exactly one class, whether the backend emits it or not. Reads nothing: the emitter
 * resolves translations and facts and passes them in. The AI core never imports a domain widget.
 *
 * @phpstan-consistent-constructor
 */
abstract class ConversationWidget extends ValueObject
{
    /** @var string The wire type — the frontend component's registry name. */
    public const string WIDGET_TYPE = '';

    /** @var bool The widget waits for the owner: a form, a picker, a confirm, pills, a connect button. */
    public const bool AWAITS_OWNER_INPUT = false;

    /**
     * @var bool Whether ONE widget of the type awaits the owner by its content, not by its type (a
     * `ComposedView` awaits the owner when its tree holds a `Form`) — then {@see AWAITS_OWNER_INPUT} is the type's
     * default and {@see awaitsOwnerInput()} the instance's answer; the readers of a text ask the instance only for such
     * types ({@see WidgetBlocks::hasClosedBlockWhoseContentAwaitsOwnerInput()}).
     */
    public const bool AWAITS_OWNER_INPUT_DEPENDS_ON_CONTENT = false;

    /**
     * @var string What the owner can do on the widget, in the words of the judge's widget inventory ("form submit",
     * "answer pills", "none (read-only list)"); empty = not described — the inventory prints "unknown".
     */
    public const string OWNER_CONTROL = '';

    /**
     * @var string One line: what the widget shows — the widget doc's frontmatter `summary`, readable by the backend
     * (the widget catalog `findWidgets` searches). Held equal to the doc for every `emitter: agent` widget
     * by a test of the application; empty for a widget the model never writes.
     */
    public const string SUMMARY = '';

    /**
     * @var string When the model writes it — the doc's `## When to use` section on one line (bullets joined). Held equal
     * to the doc like {@see SUMMARY}.
     */
    public const string WHEN_TO_USE = '';

    /**
     * @var bool The model writes the widget from what it already has — no skill has to bring its data first (a clock
     * from a zone id, a self-hydrating weather widget, a drafted text, the composable kit). `findWidgets` then answers
     * "usable directly" and loads nothing. Such a widget needs {@see SUMMARY} and {@see WHEN_TO_USE} too.
     */
    public const bool IS_WRITABLE_WITHOUT_A_SKILL = false;

    /**
     * @var string The line a model-facing example answer starts with (English, `<…>` where the model fills in), for a
     * widget {@see IS_WRITABLE_WITHOUT_A_SKILL}: the kit shows it with the block of {@see EXAMPLE_FIELDS}
     * ({@see buildExampleAnswer()}); empty = no example.
     */
    public const string EXAMPLE_INTRO_LINE = '';

    /** @var array<string, mixed> The block fields of that example besides `type`, each a public property of the class. */
    public const array EXAMPLE_FIELDS = [];

    /**
     * @var string The situation, in one line, in which the turn-start decision names this widget as the turn's DIRECT
     * widget — the block the answer is written as, without a skill or a tool first: the choice
     * criterion of the `direct_widget` question ({@see \DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision}).
     * Empty = the widget is never decided directly; the answer set is every class with a line
     * ({@see ConversationWidgetClasses::getDirectWidgetWhenLinesByWidgetType()}).
     */
    public const string DIRECT_WIDGET_WHEN = '';

    /**
     * @var string The `[SIGNAL:widget]` sentence of a turn that decided this widget directly; empty = the generic sentence
     * that names the widget ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecision::toSignalText()}).
     */
    public const string DIRECT_WIDGET_SIGNAL = '';

    /**
     * @var bool The widget reads the block's self-hydration key ({@see $widgetSelfHydrationKey}: it reads its content
     * by it, or computes against the space's time zone) — the model-facing field line names it as a field; default off.
     */
    public const bool READS_WIDGET_SELF_HYDRATION_KEY = false;

    /**
     * @var bool The widget proposes an identity or a class to the owner (which record is theirs, which class they
     * fall into) — the widget's counterpart of {@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolProposesIdentityOrClass}:
     * the owner's next words are then read as a possible rejection of the proposal when the effort of the turn is
     * decided. Default off.
     */
    public const bool PROPOSES_IDENTITY_OR_CLASS = false;

    /**
     * @var bool The widget may sit in a slot of a `ComposedView` tree as a `Widget` node; default off.
     * An embeddable widget also declares {@see EMBEDDED_SIZE_CLASS}.
     */
    public const bool EMBEDDABLE = false;

    /**
     * @var string The size class the widget needs when embedded (`compact | regular | full`,
     * {@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot::SIZE_CLASSES}); read only when {@see EMBEDDABLE}.
     */
    public const string EMBEDDED_SIZE_CLASS = 'regular';

    /**
     * @var bool The widget renders as an image in a scheduled report mail — its frontend email
     * branch exists and the mail job captures it. The one source of the mail's allowlist
     * ({@see ConversationWidgetClasses::getEmailRenderableWidgetTypes()}) and of what an e-mail run is taught; a widget
     * without it falls back to a text line in the mail. Default off.
     */
    public const bool EMAIL_RENDERABLE = false;

    /**
     * @var int|null The key of the agent space the widget is scoped to
     * ({@see \DDD\Domain\AI\Entities\AgentSpaces\AgentSpace::getWidgetSelfHydrationKey()}); null for a widget that
     * resolves the client's active space. On the block it is the field the application names
     * ({@see WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName()}).
     */
    public ?int $widgetSelfHydrationKey = null;

    /**
     * The widget restored from a stored block of any origin: every declared public property whose name
     * the block carries is set from it; a value of the wrong type is skipped, never thrown, and so is a property typed
     * with a class (the blocks carry JSON scalars and lists only). An integer written as a numeric string (the stored
     * leaf form's key attribute) fills an `int` property; {@see $widgetSelfHydrationKey} is read from the block field
     * the application names. A block that is not VALID gives an empty widget.
     * Reads nothing; a class overrides it where its fields need more than this.
     */
    /** Whether THIS widget waits for the owner — the type's {@see AWAITS_OWNER_INPUT} unless its content decides. */
    public function awaitsOwnerInput(): bool
    {
        return static::AWAITS_OWNER_INPUT;
    }

    public static function fromWidgetBlock(WidgetBlock $widgetBlock): static
    {
        $conversationWidget = new static();
        $widgetFields = $widgetBlock->outcome === WidgetBlock::OUTCOME_VALID ? static::decodeWidgetFields($widgetBlock->widgetJson) : [];
        $widgetSelfHydrationKeyFieldName = WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName();
        foreach ((new ReflectionObject($conversationWidget))->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            $propertyName = $reflectionProperty->getName();
            $widgetFieldName = $propertyName === 'widgetSelfHydrationKey' ? $widgetSelfHydrationKeyFieldName : $propertyName;
            if ($reflectionProperty->isStatic() || !array_key_exists($widgetFieldName, $widgetFields)) {
                continue;
            }
            $propertyValue = static::convertNumericStringForIntProperty($reflectionProperty, $widgetFields[$widgetFieldName]);
            if (static::doesValueFitPropertyType($reflectionProperty, $propertyValue)) {
                $conversationWidget->{$propertyName} = $propertyValue;
            }
        }
        return $conversationWidget;
    }

    /**
     * The owner's answer on the NEWEST widget of this type the rows show, as the plan gate reads it where the view's
     * reader of one widget cannot answer (`TaskPlanItemPlatformCloser::resolveOwnerAnswerOnNewestWidgetOfType()`): true =
     * answered, false = declined, null = no answer yet or no widget. The generic rules by default
     * ({@see ShownConversationWidgets::readOwnerAnswerOnNewestOfTypeByGenericRule()}); a class whose answer reads more
     * than the reactions after its newest widget (a tool call that asks the same question) overrides it.
     */
    public static function readOwnerAnswerOnTheNewestWidgetOfThisType(AIConversationMessages $messages, ShownConversationWidgets $shownConversationWidgets): ?bool
    {
        return $shownConversationWidgets->readOwnerAnswerOnNewestOfTypeByGenericRule(static::WIDGET_TYPE);
    }

    /**
     * Applies the owner's edit of a widget of THIS type that the turn opens on — the owner's submit of the widget is
     * their decision on the record, written by the platform before the first model call — and returns the SIGNAL text
     * that tells the model what was applied; null = nothing to apply (the default). Asked once per turn start of every
     * registered widget class by the turn-signal service, which appends the signal; the class finds its own unapplied
     * submit in `$conversation`'s messages (the widget it was submitted on is not necessarily the newest shown one).
     * Idempotent per turn. A lost run claim is rethrown; any other failure is the caller's to swallow.
     */
    public static function applyOwnerEditAtTurnOpen(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * The block's JSON object as an associative array (nested objects as arrays); empty when it does not decode.
     *
     * @return array<string, mixed>
     */
    protected static function decodeWidgetFields(string $widgetJson): array
    {
        try {
            $widgetFields = json_decode($widgetJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($widgetFields) ? $widgetFields : [];
    }

    /** An integer written as a numeric string becomes the integer when the property takes an `int`; any other value stays. */
    protected static function convertNumericStringForIntProperty(ReflectionProperty $reflectionProperty, mixed $propertyValue): mixed
    {
        if (!is_string($propertyValue) || preg_match('/^-?\d{1,18}$/', $propertyValue) !== 1) {
            return $propertyValue;
        }
        foreach (static::getBuiltinTypeNamesOfProperty($reflectionProperty) as $builtinTypeName) {
            if ($builtinTypeName === 'int') {
                return (int)$propertyValue;
            }
        }
        return $propertyValue;
    }

    /** Whether `$propertyValue` may be assigned to the property: one of its builtin types takes it (an `int` widens to `float`). */
    protected static function doesValueFitPropertyType(ReflectionProperty $reflectionProperty, mixed $propertyValue): bool
    {
        if (!$reflectionProperty->hasType()) {
            return true;
        }
        if ($propertyValue === null) {
            return $reflectionProperty->getType()->allowsNull();
        }
        foreach (static::getBuiltinTypeNamesOfProperty($reflectionProperty) as $builtinTypeName) {
            $fits = match ($builtinTypeName) {
                'mixed' => true,
                'string' => is_string($propertyValue),
                'int' => is_int($propertyValue),
                'float' => is_float($propertyValue) || is_int($propertyValue),
                'bool' => is_bool($propertyValue),
                'array' => is_array($propertyValue),
                default => false,
            };
            if ($fits) {
                return true;
            }
        }
        return false;
    }

    /**
     * The builtin type names of the property's declared type (`int`, `string`, …; a class type is left out).
     *
     * @return string[]
     */
    protected static function getBuiltinTypeNamesOfProperty(ReflectionProperty $reflectionProperty): array
    {
        $propertyType = $reflectionProperty->getType();
        $namedTypes = $propertyType instanceof ReflectionUnionType ? $propertyType->getTypes() : [$propertyType];
        $builtinTypeNames = [];
        foreach ($namedTypes as $namedType) {
            if ($namedType instanceof ReflectionNamedType && $namedType->isBuiltin()) {
                $builtinTypeNames[] = $namedType->getName();
            }
        }
        return $builtinTypeNames;
    }

    /**
     * The widget block the chat renders — today's ```widget fence with the flat JSON fields next to `type`, and NO
     * `emittedBy`: the form of every dictation, model-facing example and equivalence check (a block without the field
     * reads as written by the model).
     */
    public function buildWidgetBlock(): string
    {
        return WidgetBlockSerializer::buildFenceBlock(static::WIDGET_TYPE, $this->widgetSelfHydrationKey, $this->getWidgetFields());
    }

    /**
     * The model-facing example answer: {@see EXAMPLE_INTRO_LINE}, then the block {@see buildWidgetBlock()} renders from
     * {@see EXAMPLE_FIELDS} (a field the class does not declare is left out); '' without an intro line.
     */
    public static function buildExampleAnswer(): string
    {
        if (static::EXAMPLE_INTRO_LINE === '') {
            return '';
        }
        $exampleWidget = new static();
        foreach (static::EXAMPLE_FIELDS as $exampleFieldName => $exampleFieldValue) {
            if (property_exists($exampleWidget, $exampleFieldName)) {
                $exampleWidget->{$exampleFieldName} = $exampleFieldValue;
            }
        }
        return static::EXAMPLE_INTRO_LINE . "\n" . $exampleWidget->buildWidgetBlock();
    }

    /**
     * The block the one writer stores: the same fields as {@see buildWidgetBlock()}, plus the origin as
     * the LAST field (`"emittedBy":"tool:<toolName>"` / `"platform:<reason>"`) — the bytes before it are exactly
     * {@see buildWidgetBlock()}'s. A MODEL origin carries no field and renders {@see buildWidgetBlock()}.
     */
    public function buildWidgetBlockEmittedBy(ConversationWidgetOrigin $conversationWidgetOrigin): string
    {
        $emittedByValue = $conversationWidgetOrigin->toEmittedByValue();
        if ($emittedByValue === null) {
            return $this->buildWidgetBlock();
        }
        $widgetFields = $this->getWidgetFields();
        unset($widgetFields[ConversationWidgetOrigin::EMITTED_BY_FIELD]);
        $widgetFields[ConversationWidgetOrigin::EMITTED_BY_FIELD] = $emittedByValue;
        return WidgetBlockSerializer::buildFenceBlock(static::WIDGET_TYPE, $this->widgetSelfHydrationKey, $widgetFields);
    }

    /**
     * The prose line the written row carries right ABOVE the block — null for a block alone. The owner question
     * overrides it with its question text (the widgets render the options only).
     */
    public function getProseAboveTheBlock(): ?string
    {
        return null;
    }

    /**
     * The text of the row the one writer stores: the prose above the block (when the widget has one), a
     * blank line, then {@see buildWidgetBlockEmittedBy()}. A widget without prose stores the block alone.
     */
    public function buildWidgetRowEmittedBy(ConversationWidgetOrigin $conversationWidgetOrigin): string
    {
        $proseAboveTheBlock = trim((string)($this->getProseAboveTheBlock() ?? ''));
        return ($proseAboveTheBlock !== '' ? $proseAboveTheBlock . "\n\n" : '') . $this->buildWidgetBlockEmittedBy($conversationWidgetOrigin);
    }

    /**
     * THE note for this widget when the delivery wrote no row — the one dictation text every tool and meta-tool returns
     * to the model instead of a widget row (no caller writes its own): on the phone line
     * nothing can be shown or clicked, so the model says `$spokenInstruction` (the only per-caller part — what to say
     * depends on the widget); in a delegation child the block goes into the child's result for the main conversation
     * to show; without a chat the model renders the block itself. Null when the widget was written.
     */
    public function buildDictationNote(ConversationWidgetDelivery $conversationWidgetDelivery, string $spokenInstruction): ?string
    {
        return match ($conversationWidgetDelivery->type) {
            ConversationWidgetDelivery::TYPE_WRITTEN => null,
            ConversationWidgetDelivery::TYPE_PHONE_LINE => 'The owner is on a phone call — nothing was written into the chat and '
                . 'nothing can be clicked. ' . $spokenInstruction,
            ConversationWidgetDelivery::TYPE_DELEGATION_CHILD => 'This call runs in a delegated sub-task — nothing was written into '
                . 'the owner\'s chat. Put the block below into your result for the main conversation to show, after one short '
                . "prose line:\n" . $this->buildWidgetBlock(),
            default => "No chat to write into here — render it yourself: one short prose line, then on its own line:\n"
                . $this->buildWidgetBlock(),
        };
    }

    /**
     * The widget's own fields, flat next to `type` and the self-hydration key, in the order the block carries them.
     *
     * @return array<string, mixed>
     */
    abstract protected function getWidgetFields(): array;

    /**
     * The fields without the ones left null — an optional payload field the emitter did not set is not written.
     *
     * @param array<string, mixed> $widgetFields
     * @return array<string, mixed>
     */
    protected static function withoutNullWidgetFields(array $widgetFields): array
    {
        $writtenWidgetFields = [];
        foreach ($widgetFields as $widgetFieldName => $widgetFieldValue) {
            if ($widgetFieldValue !== null) {
                $writtenWidgetFields[$widgetFieldName] = $widgetFieldValue;
            }
        }
        return $writtenWidgetFields;
    }
}
