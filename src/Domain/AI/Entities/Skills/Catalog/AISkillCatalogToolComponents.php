<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Where one tool's wire-schema tokens live: the description, each input parameter, each shared `$def`, and the
 * structural envelope. The scalar fields are typed; `parameters` and `defs` stay plain dynamic maps (name => tokens)
 * because their keys are the tool's own parameter/definition names (open-ended), not a fixed schema.
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalogToolComponents extends ValueObject
{
    /** @var int Total wire-schema tokens of the tool. */
    public int $total = 0;

    /** @var int Tokens in the tool description. */
    public int $description = 0;

    /** @var array Per-parameter token cost (parameter name => tokens). */
    public array $parameters = [];

    /** @var array Per-`$def` token cost (definition name => tokens). */
    public array $defs = [];

    /** @var int Structural remainder (name, type, required, wrappers). */
    public int $envelope = 0;

    /**
     * @param array<string, int> $parameters
     * @param array<string, int> $defs
     */
    public static function make(int $total, int $description, array $parameters, array $defs, int $envelope): static
    {
        $components = new static();
        $components->total = $total;
        $components->description = $description;
        $components->parameters = $parameters;
        $components->defs = $defs;
        $components->envelope = $envelope;
        return $components;
    }
}
