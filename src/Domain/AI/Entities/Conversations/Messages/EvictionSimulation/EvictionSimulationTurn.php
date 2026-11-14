<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the eviction simulator observed in one turn: what loaded, what was used, what the eviction pass removed (with
 * the reason shown in the printout) and the load carried after the pass. Identified by its turn number
 * ({@see EvictionSimulationTurns}).
 *
 * @phpstan-consistent-constructor
 */
class EvictionSimulationTurn extends ValueObject
{
    /** @var string Reason label of a skill body removed because none of its tools is loaded any more */
    public const string REASON_CASCADE = 'casc';

    /** @var string Prefix of the reason label of a tool removed by the hard ceiling (followed by its score) */
    public const string REASON_PREFIX_HARD_CEILING = 'cap:';

    /** @var int The turn number */
    public int $turnIndex = 0;

    /** @var int The context size in tokens at this turn */
    public int $contextInputTokens = 0;

    /** @var string[] The capabilities loaded in this turn */
    public array $loadedCapabilityNames = [];

    /** @var string[] The capabilities used in this turn (the tool and its skill) */
    public array $usedCapabilityNames = [];

    /** @var string[] The reason label of every capability the pass removed, by name: its score, `casc` or `cap:<score>` */
    public array $evictionReasonsByName = [];

    /** @var string[] The capabilities still loaded after the pass */
    public array $residentCapabilityNames = [];

    /** @var int Tokens of the skill bodies loaded after the pass */
    public int $skillTokens = 0;

    /** @var int Tokens of the tool schemas loaded after the pass */
    public int $toolTokens = 0;

    /** @var int The whole load carried before the pass */
    public int $carriedTokensBeforeEviction = 0;

    /** @var EvictionSimulationScoreRows The figures of every capability loaded before the pass, highest eviction score first */
    public EvictionSimulationScoreRows $scoreRowsBeforeEviction;

    public function __construct()
    {
        parent::__construct();
        $this->scoreRowsBeforeEviction = new EvictionSimulationScoreRows();
    }

    /** @return int the whole load carried after the pass */
    public function getCarriedTokens(): int
    {
        return $this->skillTokens + $this->toolTokens;
    }

    /** @return string[] the capabilities the pass removed */
    public function getEvictedCapabilityNames(): array
    {
        return array_keys($this->evictionReasonsByName);
    }

    /**
     * @param int $turnIndex
     * @return string the key of the turn of that number in an {@see EvictionSimulationTurns}
     */
    public static function uniqueKeyForTurnIndex(int $turnIndex): string
    {
        return self::class . '_' . $turnIndex;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForTurnIndex($this->turnIndex);
    }
}
