<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * The condition on the partition column `agentSpaceId` for a statement of the package, with its bound value.
 *
 * The column is empty for rows without a space. What an empty column MEANS differs by entity, so the condition has
 * named modes and no statement writes it by hand:
 *
 * | Mode | Used for | A space is given | No space is given |
 * |---|---|---|---|
 * | {@see self::forAgentSpace()} — the rows OF this space | conversation, schedule | `col = :id` | `col IS NULL`: the account-level rows — of EVERY account, so the statement carries its own condition on the account |
 * | {@see self::forAgentSpaceOrAccountWide()} — the rows that APPLY to this space | mandate | `(col = :id OR col IS NULL)`: bound to the space, or account-wide | `col IS NULL`: the account-wide rows only (the statement carries the account, as above) |
 * | {@see self::forAccountWithoutAgentSpace()} — the rows ONE account holds without a space | schedule | — | `(col IS NULL AND accountId = :accountId)` |
 *
 * In no mode does "no space" select the rows of all spaces of an account: a read across spaces carries no space
 * condition and says so in its name, so a caller that failed to load a space lands on the narrow side.
 *
 * A space that is NOT STORED (an object without an id — an application gives one to a conversation that is never
 * stored, to read what a conversation of that space would see) has no rows: the condition compares the column with
 * {@see self::AGENT_SPACE_ID_THAT_MATCHES_NO_ROW}. It selects nothing bound to a space, and in the mode that
 * includes the rows without a space, those only. It is never read as "no space". A WRITE never takes its id from
 * here: {@see AgentSpace::getIdOfStoredAgentSpace()} refuses a space that is not stored.
 *
 * PHP decides between the forms: a comparison with a bound NULL matches nothing on either database, and a parameter
 * tested with `IS NULL` cannot be typed on PostgreSQL.
 *
 * @phpstan-consistent-constructor
 */
class AgentSpaceSqlCondition extends ValueObject
{
    /** @var string The name of the partition column on conversation, schedule and mandate */
    public const string COLUMN_NAME = 'agentSpaceId';

    /** @var string The name of the account column on conversation, schedule and mandate */
    public const string ACCOUNT_COLUMN_NAME = 'accountId';

    /** @var int The id a condition for a space that is not stored compares with: no space has it, so no row matches */
    public const int AGENT_SPACE_ID_THAT_MATCHES_NO_ROW = 0;

    /** @var string The default name of the bound parameter of {@see self::forAccountWithoutAgentSpace()} */
    public const string ACCOUNT_ID_PARAMETER_NAME = 'accountIdOfRowsWithoutAgentSpace';

    /** @var int|null The id of the space; null = no space */
    public ?int $agentSpaceId = null;

    /** @var bool true = rows without a space match next to the rows of the space (the mode of {@see self::forAgentSpaceOrAccountWide()}) */
    public bool $includesRowsWithoutSpace = false;

    /** @var string The name of the bound parameter; a statement that carries two space conditions gives each its own */
    public string $parameterName = self::COLUMN_NAME;

    /** @var int|null The id of the account whose rows without a space match (the mode of {@see self::forAccountWithoutAgentSpace()}); null in the other modes */
    public ?int $accountIdOfRowsWithoutAgentSpace = null;

    /** @var string The name of the bound parameter of {@see self::$accountIdOfRowsWithoutAgentSpace} */
    public string $accountIdParameterName = self::ACCOUNT_ID_PARAMETER_NAME;

    /**
     * The rows OF `$agentSpace`; without a space the account-level rows.
     *
     * @param AgentSpace|null $agentSpace
     * @param string $parameterName
     * @return static
     */
    public static function forAgentSpace(?AgentSpace $agentSpace, string $parameterName = self::COLUMN_NAME): static
    {
        return static::forAgentSpaceId(static::idOf($agentSpace), $parameterName);
    }

    /**
     * The rows of the space with id `$agentSpaceId`; without an id the account-level rows.
     *
     * @param int|null $agentSpaceId
     * @param string $parameterName
     * @return static
     */
    public static function forAgentSpaceId(?int $agentSpaceId, string $parameterName = self::COLUMN_NAME): static
    {
        $agentSpaceSqlCondition = new static();
        $agentSpaceSqlCondition->agentSpaceId = $agentSpaceId;
        $agentSpaceSqlCondition->parameterName = $parameterName;
        return $agentSpaceSqlCondition;
    }

    /**
     * The rows that APPLY to `$agentSpace`: bound to it, or account-wide. Without a space the account-wide rows only —
     * never a row bound to a space.
     *
     * @param AgentSpace|null $agentSpace
     * @param string $parameterName
     * @return static
     */
    public static function forAgentSpaceOrAccountWide(?AgentSpace $agentSpace, string $parameterName = self::COLUMN_NAME): static
    {
        return static::forAgentSpaceIdOrAccountWide(static::idOf($agentSpace), $parameterName);
    }

    /**
     * The rows that apply to the space with id `$agentSpaceId`: bound to it, or account-wide. Without an id the
     * account-wide rows only.
     *
     * @param int|null $agentSpaceId
     * @param string $parameterName
     * @return static
     */
    public static function forAgentSpaceIdOrAccountWide(?int $agentSpaceId, string $parameterName = self::COLUMN_NAME): static
    {
        $agentSpaceSqlCondition = static::forAgentSpaceId($agentSpaceId, $parameterName);
        $agentSpaceSqlCondition->includesRowsWithoutSpace = true;
        return $agentSpaceSqlCondition;
    }

    /**
     * The rows `$account` holds WITHOUT a space — its own account-level rows, never those of another account. The
     * counterpart of {@see self::forAgentSpace()} for a read that names no space: the condition carries the account
     * itself, so no statement can forget it.
     *
     * @param Account $account
     * @param string $accountIdParameterName
     * @return static
     * @throws InternalErrorException when the account is not stored
     */
    public static function forAccountWithoutAgentSpace(Account $account, string $accountIdParameterName = self::ACCOUNT_ID_PARAMETER_NAME): static
    {
        if (!isset($account->id)) {
            throw new InternalErrorException('An account without an id cannot be used in a condition.');
        }
        $agentSpaceSqlCondition = new static();
        $agentSpaceSqlCondition->accountIdOfRowsWithoutAgentSpace = (int)$account->id;
        $agentSpaceSqlCondition->accountIdParameterName = $accountIdParameterName;
        return $agentSpaceSqlCondition;
    }

    /**
     * The condition for a raw statement, without a leading connective. Identifiers are written in backticks; the
     * framework rewrites them for the database in use.
     *
     * @param string $tableAlias the alias of the table that carries the column in the statement; '' = no qualifier
     * @return string
     */
    public function getConditionSql(string $tableAlias = ''): string
    {
        $columnQualifier = $tableAlias === '' ? '' : '`' . $tableAlias . '`.';
        return $this->renderCondition($columnQualifier . '`' . static::COLUMN_NAME . '`', $columnQualifier . '`' . static::ACCOUNT_COLUMN_NAME . '`');
    }

    /**
     * The condition for a QueryBuilder (`andWhere()`), without a leading connective.
     *
     * @param string $modelAlias the alias of the model that carries the property in the query
     * @return string
     */
    public function getConditionDql(string $modelAlias): string
    {
        return $this->renderCondition($modelAlias . '.' . static::COLUMN_NAME, $modelAlias . '.' . static::ACCOUNT_COLUMN_NAME);
    }

    /**
     * The values of the named parameters the condition uses, by parameter name: the id of the space when one is
     * given, the id of the account in the mode of {@see self::forAccountWithoutAgentSpace()}, else none.
     *
     * @return int[]
     */
    public function getParameters(): array
    {
        if ($this->agentSpaceId !== null) {
            return [$this->parameterName => $this->agentSpaceId];
        }
        if ($this->accountIdOfRowsWithoutAgentSpace !== null) {
            return [$this->accountIdParameterName => $this->accountIdOfRowsWithoutAgentSpace];
        }
        return [];
    }

    /**
     * @param string $columnReference the partition column as the statement's language writes it
     * @param string $accountColumnReference the account column, written the same way
     * @return string
     */
    protected function renderCondition(string $columnReference, string $accountColumnReference): string
    {
        if ($this->agentSpaceId === null) {
            if ($this->accountIdOfRowsWithoutAgentSpace !== null) {
                return '(' . $columnReference . ' IS NULL AND ' . $accountColumnReference . ' = :' . $this->accountIdParameterName . ')';
            }
            return $columnReference . ' IS NULL';
        }
        $equalsSpace = $columnReference . ' = :' . $this->parameterName;
        if (!$this->includesRowsWithoutSpace) {
            return $equalsSpace;
        }
        return '(' . $equalsSpace . ' OR ' . $columnReference . ' IS NULL)';
    }

    /**
     * The id the condition compares with. A space that is not stored has no rows: its condition compares with
     * {@see self::AGENT_SPACE_ID_THAT_MATCHES_NO_ROW} — reading it as "no space" would select the rows of another
     * partition.
     *
     * @param AgentSpace|null $agentSpace
     * @return int|null the id of the space; the id that matches no row for a space that is not stored; null without a space
     */
    protected static function idOf(?AgentSpace $agentSpace): ?int
    {
        if ($agentSpace === null) {
            return null;
        }
        return isset($agentSpace->id) ? (int)$agentSpace->id : static::AGENT_SPACE_ID_THAT_MATCHES_NO_ROW;
    }
}
