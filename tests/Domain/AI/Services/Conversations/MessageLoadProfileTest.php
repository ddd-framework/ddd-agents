<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Tests\Agents\Support\UnitTestCase;
use Doctrine\ORM\Mapping\Column;
use ReflectionClass;
use ReflectionProperty;

/**
 * Guard: the message column-load profiles ({@see AIConversationMessage}::*_LOAD_COLUMNS) must stay correct as
 * the entity evolves. Two pure (no-DB) assertions:
 *  (a) CONTEXT_LOAD_COLUMNS === every persisted column — so adding a new column to the entity FAILS this test until it
 *      is consciously placed in (or excluded from) the profile (the allow-list safety net). The recall vector is NO
 *      LONGER a message column (it moved to its own {@see \DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbedding}
 *      row), so there is no longer a heavy column CONTEXT must drop — CONTEXT == the full persisted set.
 *  (b) every heavy column a profile DROPS is declared WITHOUT a default value — so a projected load leaves it an
 *      uninitialized typed property, which the DDD upsert SKIPS (no NULL-clobber on a later full update()).
 * (c) — the real update() round-trip — is an integration test (needs a persisted row); this unit test locks the two
 *      invariants the design rests on without a DB.
 */
class MessageLoadProfileTest extends UnitTestCase
{
    /** Heavy columns a profile may drop — each MUST stay default-less for clobber-safety. */
    protected const array DROPPABLE_HEAVY_COLUMNS = ['toolCall', 'loadedCapabilities', 'requestSnapshot', 'taskPlanSnapshot'];

    /**
     * Persisted columns DELIBERATELY excluded from CONTEXT: `taskPlanSnapshot` is the per-message plan-traceability
     * copy — read ONLY by the admin inspector projection, never by the turn/context build, and a
     * potentially large JSON per message. Clobber-safe because it is also in DROPPABLE_HEAVY_COLUMNS (no default).
     */
    protected const array CONTEXT_EXCLUDED_COLUMNS = ['taskPlanSnapshot'];

    /** (a) CONTEXT === every persisted column minus the deliberate exclusions above. */
    public function testContextProfileIsEveryPersistedColumn(): void
    {
        $expectedContext = array_values(array_diff($this->persistedColumnNames(), self::CONTEXT_EXCLUDED_COLUMNS));
        sort($expectedContext);
        $actualContext = AIConversationMessage::CONTEXT_LOAD_COLUMNS;
        sort($actualContext);

        self::assertSame(
            $expectedContext,
            $actualContext,
            'CONTEXT_LOAD_COLUMNS must equal every persisted column. '
            . 'A column was added/removed on AIConversationMessage — update CONTEXT_LOAD_COLUMNS (or exclude it deliberately).'
        );
    }

    /** The leaner profiles must be subsets of the persisted columns (no typo / stale field). */
    public function testSearchHitAndCurationProfilesAreSubsetsOfPersistedColumns(): void
    {
        $persistedColumns = $this->persistedColumnNames();
        foreach (AIConversationMessage::SEARCH_HIT_LOAD_COLUMNS as $column) {
            self::assertContains($column, $persistedColumns, "SEARCH_HIT column '{$column}' is not a persisted column");
        }
        foreach (AIConversationMessage::CURATION_LOAD_COLUMNS as $column) {
            self::assertContains($column, $persistedColumns, "CURATION column '{$column}' is not a persisted column");
        }
    }

    /** (b) clobber-safety: every droppable heavy column has NO default → uninitialized on a projected load → update() skips it. */
    public function testDroppableHeavyColumnsHaveNoDefaultValue(): void
    {
        $entityReflection = new ReflectionClass(AIConversationMessage::class);
        foreach (self::DROPPABLE_HEAVY_COLUMNS as $heavyColumn) {
            $property = $entityReflection->getProperty($heavyColumn);
            self::assertFalse(
                $property->hasDefaultValue(),
                "Heavy column '{$heavyColumn}' must NOT have a default value — a default makes a projected load write it back "
                . '(NULL-clobber) on a full update(). Keep it a bare `public ?Type $x;`.'
            );
        }
    }

    /**
     * The persisted column names = the generated ORM model's #[ORM\Column] properties, minus the generated `virtual*`
     * DDL columns. Reading them from the auto-generated model is the canonical source — it tracks the entity exactly.
     *
     * @return string[]
     */
    protected function persistedColumnNames(): array
    {
        $modelReflection = new ReflectionClass(DBAIConversationMessageModel::class);
        $columns = [];
        foreach ($modelReflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Column::class) === []) {
                continue; // not a mapped column
            }
            $name = $property->getName();
            if (str_starts_with($name, 'virtual')) {
                continue; // generated DDL-only column (toolName/createdDate/…) — never selected/hydrated
            }
            $columns[] = $name;
        }
        return $columns;
    }
}
