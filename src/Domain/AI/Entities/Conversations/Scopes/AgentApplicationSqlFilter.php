<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Scopes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A piece of SQL condition, with the join it needs, that the application adds to a read of the package — for example
 * to leave out test accounts. It names tables of the application and has nothing to do with an agent space. The
 * package knows no table of an application, so its own statements carry the empty filter ({@see self::none()}); an
 * application's same-path subclass of the repository or service that issues the statement returns its tables here.
 *
 * Both fragments are SQL text written by the application's code, never user input. Values go into
 * {@see self::$parameters} and are bound by the statement.
 *
 * @phpstan-consistent-constructor
 */
class AgentApplicationSqlFilter extends ValueObject
{
    /** @var string The join clause(s), complete with their keyword (`LEFT JOIN … ON …`); empty for none. */
    public string $joinSql = '';

    /** @var string The condition without a leading connective; the statement connects it. Empty for none. */
    public string $conditionSql = '';

    /** @var string[] The values of the named parameters the two fragments use, by parameter name. */
    public array $parameters = [];

    /**
     * The filter of an application without tables of its own: no join, no condition.
     *
     * @return static
     */
    public static function none(): static
    {
        return new static();
    }

    /**
     * @param string $joinSql The join clause(s), complete with their keyword
     * @param string $conditionSql The condition without a leading connective
     * @param string[] $parameters The values of the named parameters, by parameter name
     * @return static
     */
    public static function of(string $joinSql, string $conditionSql, array $parameters = []): static
    {
        $agentApplicationSqlFilter = new static();
        $agentApplicationSqlFilter->joinSql = $joinSql;
        $agentApplicationSqlFilter->conditionSql = $conditionSql;
        $agentApplicationSqlFilter->parameters = $parameters;
        return $agentApplicationSqlFilter;
    }

    /**
     * The condition connected with `AND`, or the empty string when there is none.
     *
     * @return string
     */
    public function getConditionSqlConnectedByAnd(): string
    {
        return $this->conditionSql === '' ? '' : 'AND ' . $this->conditionSql;
    }

    /**
     * The condition connected with `OR`, or the empty string when there is none.
     *
     * @return string
     */
    public function getConditionSqlConnectedByOr(): string
    {
        return $this->conditionSql === '' ? '' : 'OR ' . $this->conditionSql;
    }
}
