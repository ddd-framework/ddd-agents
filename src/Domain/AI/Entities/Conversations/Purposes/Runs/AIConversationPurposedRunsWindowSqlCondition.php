<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The WHERE clause that selects the purposed runs of one window ({@see AIConversationPurposedRunsFilter}) from the
 * conversation table aliased `c`, with the values and the value types its placeholders are bound with. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposedRunsReadService}; the count, the page
 * and the criteria review of one read all run with the same condition.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationPurposedRunsWindowSqlCondition extends ValueObject
{
    /** @var string The conditions joined by AND, without the keyword WHERE */
    public string $conditionSql = '';

    /** @var array<string, mixed> Placeholder name ⇒ the value bound to it */
    public array $parameters = [];

    /** @var array<string, mixed> Placeholder name ⇒ its DBAL type; only the placeholders that need one */
    public array $parameterTypes = [];

    /**
     * @param string[] $conditions The single conditions
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $parameterTypes
     * @return static
     */
    public static function forConditionsAndParameters(array $conditions, array $parameters, array $parameterTypes): static
    {
        $windowSqlCondition = new static();
        $windowSqlCondition->conditionSql = implode(' AND ', $conditions);
        $windowSqlCondition->parameters = $parameters;
        $windowSqlCondition->parameterTypes = $parameterTypes;
        return $windowSqlCondition;
    }
}
