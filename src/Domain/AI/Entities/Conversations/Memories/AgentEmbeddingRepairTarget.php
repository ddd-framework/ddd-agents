<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One table whose zero vectors the embedding repair re-embeds: the generated model of the table, the column holding
 * the text and the vector column. Contributed by a component that owns such a table (the memory component). The
 * repair reads and writes the table through its model, so its statements run on every database engine the framework
 * supports.
 *
 * @phpstan-consistent-constructor
 */
class AgentEmbeddingRepairTarget extends ValueObject
{
    /** @var string The class name of the table's generated model (`DB…Model`). */
    public string $modelClassName;

    /** @var string The column holding the text the vector is built from. */
    public string $textColumnName;

    /** @var string The vector column. */
    public string $vectorColumnName;

    /**
     * A target over the table of the generated model `$modelClassName`.
     *
     * @param string $modelClassName
     * @param string $textColumnName
     * @param string $vectorColumnName
     * @return self
     */
    public static function forModel(string $modelClassName, string $textColumnName, string $vectorColumnName): self
    {
        $agentEmbeddingRepairTarget = new static();
        $agentEmbeddingRepairTarget->modelClassName = $modelClassName;
        $agentEmbeddingRepairTarget->textColumnName = $textColumnName;
        $agentEmbeddingRepairTarget->vectorColumnName = $vectorColumnName;
        return $agentEmbeddingRepairTarget;
    }

    /** The name of the table, as its model names it. */
    public function getTableName(): string
    {
        return $this->modelClassName::getTableName();
    }
}
