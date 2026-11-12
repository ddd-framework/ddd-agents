<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TurnEvents;

use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventSnapshot;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * ONE catalog snapshot a skill-preload decision was rendered with: the answer tokens → skill name and
 * tool names of the OFFERED and the LOADED skills, keyed by the hash of its content so one row serves every event
 * rendered from the same catalog (a 3–5 KB map would otherwise ride every decision row). Holds skill and tool names
 * only — no personal data, no account key — and is purged only when no event references its hash any more.
 *
 * @method static AIConversationTurnEventsService getService()
 * @method static DBAIConversationTurnEventSnapshot getRepoClassInstance(string $repoType = null)
 */
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['hash'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationTurnEventSnapshot::class)]
class AIConversationTurnEventSnapshot extends Entity
{
    use ChangeHistoryTrait;

    /** @var string|null sha1 of the canonical JSON of {@see $payload}. */
    #[NotNull]
    #[Length(max: 40)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)] // the UNIQUE index above is the only one needed
    public ?string $hash;

    /** @var string|null `{ "offered": {token: {name, toolNames}}, "loaded": {name: {toolNames}}, "criteriaVersion": … }`. */
    #[NotNull]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_JSON)]
    public ?string $payload;

    /** The canonical JSON + its hash of a snapshot map (sorted keys, so equal content hashes equal). */
    public static function canonicalize(array $snapshot): array
    {
        self::sortKeysRecursively($snapshot);
        $json = (string) json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [sha1($json), $json];
    }

    protected static function sortKeysRecursively(array &$value): void
    {
        ksort($value);
        foreach ($value as &$child) {
            if (is_array($child)) {
                self::sortKeysRecursively($child);
            }
        }
    }
}
