<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use LogicException;

/**
 * The 26 SEEDED DECLARATIONS of a {@see TaskPlanItem} — the fields a purpose seed declares and a full-replace resend
 * must never lose (gates, criterion, markers, the stepper key, the skills) — listed ONCE with their value kind (the
 * properties stay flat on the item, this class is the one owner of
 * the module's field list; an application's item class adds its own, {@see TaskPlanItem::getSeededDeclarationKinds()}). The seed reader and the repository reader read the list; the repository WRITE
 * keeps its ordered key list on {@see TaskPlanItem::toRepositoryFields()}, because the persisted key order interleaves
 * declarations and platform records (the plan column's bytes).
 *
 * The readers differ where the code always differed, by name: the repository reads `doneCriterion` untrimmed and
 * `skillNames` without trimming; the seed inherits the parent's stepper key.
 */
class TaskPlanItemDeclaration
{
    /** Trimmed string; empty = null. */
    public const string KIND_TEXT = 'text';

    /** A non-empty array (an argument filter, a field skeleton); anything else = null. */
    public const string KIND_NON_EMPTY_ARRAY = 'nonEmptyArray';

    /** A non-empty list of names, each cast to string; anything else = null. */
    public const string KIND_NAME_LIST = 'nameList';

    public const string KIND_BOOL = 'bool';

    /** A name of the item class's {@see TaskPlanItem::CONNECTED_CHANNEL_LABELS}, lower-cased; an undeclared name = null. */
    public const string KIND_CONNECTED_CHANNEL = 'connectedChannel';

    /** Language code → 2–5 option strings ({@see normalizeClosedQuestionOptions()}). */
    public const string KIND_CLOSED_QUESTION_OPTIONS = 'closedQuestionOptions';

    /** `low` / `medium` / `high`, lower-cased; anything else = null. */
    public const string KIND_REASONING_EFFORT = 'reasoningEffort';

    /** The skills list: never null, empty names dropped. */
    public const string KIND_SKILL_NAMES = 'skillNames';

    /** @var array<string, string> declaration property → kind, in the order the seed parser always read them */
    public const array FIELD_KINDS = [
        'ownerFacingTitle' => self::KIND_TEXT,
        'doneCriterion' => self::KIND_TEXT,
        'doneRequiresToolCall' => self::KIND_TEXT,
        'doneRequiresToolCallArgumentsAbsent' => self::KIND_NON_EMPTY_ARRAY,
        'doneRequiresToolCallArgumentsPresent' => self::KIND_NON_EMPTY_ARRAY,
        'notApplicableRequiresToolCall' => self::KIND_TEXT,
        'notApplicableRequiresToolCallArgumentsAbsent' => self::KIND_NON_EMPTY_ARRAY,
        'notApplicableRequiresToolCallArgumentsPresent' => self::KIND_NON_EMPTY_ARRAY,
        'notApplicableForbiddenAfterToolCall' => self::KIND_TEXT,
        'doneRequiresWidget' => self::KIND_TEXT,
        'doneRequiresConnectedChannel' => self::KIND_CONNECTED_CHANNEL,
        'closedQuestionOptions' => self::KIND_CLOSED_QUESTION_OPTIONS,
        // the purpose's minimum effort for the call that works on this item
        'minReasoningEffort' => self::KIND_REASONING_EFFORT,
        'doneRequiresWidgetFilledFields' => self::KIND_NON_EMPTY_ARRAY,
        'doneRequiresWidgetAnswered' => self::KIND_BOOL,
        'doneRequiresOwnerVisibleAnswerInSameTurn' => self::KIND_BOOL,
        'doneRequiresOwnerQuestionKey' => self::KIND_TEXT,
        'doneAlsoOnOwnerAnswerOfWidget' => self::KIND_TEXT,
        'doneOwnerQuestionMayFollowToolCall' => self::KIND_TEXT,
        'previousTurnDeliveryEvidenceToolNames' => self::KIND_NAME_LIST,
        'blockedRequiresWidget' => self::KIND_TEXT,
        'blockedRequiresToolCall' => self::KIND_TEXT,
        'blockedRequiresToolCallArgumentsPresent' => self::KIND_NON_EMPTY_ARRAY,
        'triggersSupervisorMilestoneCheckpoint' => self::KIND_BOOL,
        // the skills this item needs when it is the plan's next
        'skillNames' => self::KIND_SKILL_NAMES,
        // Stepper membership: a seeded subtask without its own key INHERITS the parent's ({@see readSeedDefinition()})
        'customerProgressStepKey' => self::KIND_TEXT,
    ];

    /**
     * Read every declaration of one seed item definition onto $taskPlanItem. A seeded subtask without its own stepper
     * key takes $inheritedCustomerProgressStepKey (materialized here so the runtime aggregation stays a flat key
     * match per item); the seed trims the skill names.
     *
     * @param array<string, mixed> $seedItemDefinition
     */
    public static function readSeedDefinition(array $seedItemDefinition, TaskPlanItem $taskPlanItem, ?string $inheritedCustomerProgressStepKey): void
    {
        foreach ($taskPlanItem->getSeededDeclarationKinds() as $fieldName => $kind) {
            $rawValue = $seedItemDefinition[$fieldName] ?? null;
            $taskPlanItem->{$fieldName} = $kind === self::KIND_SKILL_NAMES
                ? self::readNameListTrimmed($rawValue)
                : self::readValue($kind, $rawValue, $taskPlanItem);
        }
        $taskPlanItem->customerProgressStepKey ??= $inheritedCustomerProgressStepKey;
    }

    /**
     * Read every declaration of one persisted item onto $taskPlanItem. Unlike the seed, the stored `doneCriterion` is
     * taken as stored (cast, not trimmed) and the stored skill names are not trimmed.
     *
     * @param array<string, mixed> $itemFields
     */
    public static function readRepositoryFields(array $itemFields, TaskPlanItem $taskPlanItem): void
    {
        foreach ($taskPlanItem->getSeededDeclarationKinds() as $fieldName => $kind) {
            $rawValue = $itemFields[$fieldName] ?? null;
            if ($fieldName === 'doneCriterion') {
                $taskPlanItem->doneCriterion = $rawValue !== null ? (string)$rawValue : null;
                continue;
            }
            $taskPlanItem->{$fieldName} = $kind === self::KIND_SKILL_NAMES
                ? array_values(array_filter(array_map('strval', is_array($rawValue) ? $rawValue : []), static fn (string $name): bool => $name !== ''))
                : self::readValue($kind, $rawValue, $taskPlanItem);
        }
    }

    /**
     * Whether $taskPlanItem carries any of the declarations: a text or list declaration set, a bool raised, a skill
     * named — the read's stand-in for `seededByPurpose` on an item stored before that field existed.
     */
    public static function hasAnyDeclaration(TaskPlanItem $taskPlanItem): bool
    {
        foreach ($taskPlanItem->getSeededDeclarationKinds() as $fieldName => $kind) {
            $value = $taskPlanItem->{$fieldName};
            if ($kind === self::KIND_BOOL ? $value === true : ($value !== null && $value !== [])) {
                return true;
            }
        }
        return false;
    }

    protected static function readValue(string $kind, mixed $rawValue, TaskPlanItem $taskPlanItem): mixed
    {
        return match ($kind) {
            self::KIND_TEXT => self::readText($rawValue),
            self::KIND_NON_EMPTY_ARRAY => is_array($rawValue) && $rawValue !== [] ? $rawValue : null,
            self::KIND_NAME_LIST => is_array($rawValue) && $rawValue !== [] ? array_values(array_map('strval', $rawValue)) : null,
            self::KIND_BOOL => (bool)($rawValue ?? false),
            self::KIND_CONNECTED_CHANNEL => self::normalizeConnectedChannel($rawValue, $taskPlanItem),
            self::KIND_CLOSED_QUESTION_OPTIONS => self::normalizeClosedQuestionOptions($rawValue),
            self::KIND_REASONING_EFFORT => self::readReasoningEffort($rawValue),
            default => throw new LogicException('Unknown task-plan declaration kind: ' . $kind),
        };
    }

    protected static function readText(mixed $rawValue): ?string
    {
        $text = trim((string)($rawValue ?? ''));
        return $text !== '' ? $text : null;
    }

    protected static function readReasoningEffort(mixed $rawValue): ?string
    {
        $minReasoningEffort = strtolower(trim((string)($rawValue ?? '')));
        return in_array($minReasoningEffort, ['low', 'medium', 'high'], true) ? $minReasoningEffort : null;
    }

    /** @return string[] */
    protected static function readNameListTrimmed(mixed $rawValue): array
    {
        return array_values(array_filter(
            array_map(static fn ($name): string => trim((string) $name), is_array($rawValue) ? $rawValue : []),
            static fn (string $name): bool => $name !== ''
        ));
    }

    /**
     * The seed / repository value of {@see TaskPlanItem::$closedQuestionOptions}: a map language code → list of 2–5
     * non-empty option strings; anything else is dropped (null). Language codes are lower-cased two-letter codes.
     *
     * @return array<string, string[]>|null
     */
    public static function normalizeClosedQuestionOptions(mixed $closedQuestionOptions): ?array
    {
        if (!is_array($closedQuestionOptions)) {
            return null;
        }
        $normalized = [];
        foreach ($closedQuestionOptions as $languageCode => $options) {
            $languageCode = strtolower(trim((string)$languageCode));
            if (!preg_match('/^[a-z]{2}$/', $languageCode) || !is_array($options)) {
                continue;
            }
            $optionTexts = array_values(array_unique(array_filter(array_map(static fn ($option): string => trim((string)$option), $options), static fn (string $option): bool => $option !== '')));
            if (count($optionTexts) >= 2 && count($optionTexts) <= 5) {
                $normalized[$languageCode] = $optionTexts;
            }
        }
        return $normalized !== [] ? $normalized : null;
    }

    /**
     * The seed / repository value of {@see TaskPlanItem::$doneRequiresConnectedChannel}, lower-cased; a name the class
     * of `$taskPlanItem` does not declare ({@see TaskPlanItem::CONNECTED_CHANNEL_LABELS}) is no gate.
     */
    public static function normalizeConnectedChannel(mixed $connectedChannel, TaskPlanItem $taskPlanItem): ?string
    {
        $connectedChannel = strtolower(trim((string)($connectedChannel ?? '')));
        return array_key_exists($connectedChannel, $taskPlanItem::CONNECTED_CHANNEL_LABELS) ? $connectedChannel : null;
    }
}
