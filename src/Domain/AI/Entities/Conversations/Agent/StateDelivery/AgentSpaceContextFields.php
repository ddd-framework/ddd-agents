<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery;

use DDD\Infrastructure\Services\DDDService;

/**
 * The fields of the context text an agent space contributes to the base prompt (the `{%AGENT_SPACE_CONTEXT%}`
 * placeholder), read back from the rendered text so that a change is named field by field
 * ({@see describeChangesSince()}) and the text stays the one source.
 *
 * The module knows no field: who writes the text knows its labels. An application declares them in its class at the
 * same path — {@see FIELDS_IN_ORDER} and {@see readFieldsOfText()} — and {@see parse()} builds that class. Without
 * one no field is read, no change line is written, and the state note carries the whole new text
 * ({@see AgentStateDeltaTexts::buildAgentSpaceContextText()}). Pure.
 *
 * @phpstan-consistent-constructor
 */
class AgentSpaceContextFields
{
    /** @var string[] Every field in the order the text carries them; an application declares its own. */
    protected const array FIELDS_IN_ORDER = [];

    /** @var array<string, string> field => value, in the order the text carries them */
    public array $valuesByField = [];

    /**
     * The class that reads the fields: the application's class at the same path, this one without it.
     *
     * @return class-string<AgentSpaceContextFields>
     */
    public static function getFieldsClassOfTheApplication(): string
    {
        /** @var class-string<AgentSpaceContextFields> $fieldsClassOfTheApplication */
        $fieldsClassOfTheApplication = DDDService::instance()->getContainerServiceClassNameForClass(self::class) ?? self::class;
        return $fieldsClassOfTheApplication;
    }

    /**
     * The fields of `$agentSpaceContextText`, read by the application's class (called on this class) or by the class
     * it is called on.
     *
     * @param string|null $agentSpaceContextText
     * @return static
     */
    public static function parse(?string $agentSpaceContextText): static
    {
        /** @var class-string<static> $fieldsClass */
        $fieldsClass = static::class === self::class ? self::getFieldsClassOfTheApplication() : static::class;
        $agentSpaceContextFields = new $fieldsClass();
        $agentSpaceContextFields->readFieldsOfText((string)$agentSpaceContextText);
        return $agentSpaceContextFields;
    }

    /**
     * Sets {@see $valuesByField} from the text. The module reads nothing; an application reads the labels its builder
     * writes.
     *
     * @param string $agentSpaceContextText
     * @return void
     */
    protected function readFieldsOfText(string $agentSpaceContextText): void
    {
    }

    /** @return string[] Every field in the order the text carries them. */
    public function getFieldsInOrder(): array
    {
        return static::FIELDS_IN_ORDER;
    }

    /**
     * The changes from `$before` to these fields, one line per field, in the order of the text: `Name: "A" → "B"`,
     * `Address: added "…"`, `Description: removed (was "…")`. [] when nothing changed or no field is known.
     *
     * @param AgentSpaceContextFields $before
     * @return string[]
     */
    public function describeChangesSince(self $before): array
    {
        $changeLines = [];
        foreach ($this->getFieldsInOrder() as $field) {
            $valueBefore = $before->valuesByField[$field] ?? null;
            $valueAfter = $this->valuesByField[$field] ?? null;
            if ($valueBefore === $valueAfter) {
                continue;
            }
            if ($valueBefore === null) {
                $changeLines[] = $field . ': added "' . $valueAfter . '"';
            } elseif ($valueAfter === null) {
                $changeLines[] = $field . ': removed (was "' . $valueBefore . '")';
            } else {
                $changeLines[] = $field . ': "' . $valueBefore . '" → "' . $valueAfter . '"';
            }
        }
        return $changeLines;
    }
}
