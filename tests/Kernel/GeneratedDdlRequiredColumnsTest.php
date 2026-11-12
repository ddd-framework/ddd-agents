<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfig;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The generated DDL of the three tables that hang on a conversation: their required columns are NOT NULL, the
 * foreign key to the conversation deletes the row with its conversation, and no foreign key sets a NOT NULL column to
 * NULL. Generated in memory under the package kernel, nothing is written and no database is asked.
 */
class GeneratedDdlRequiredColumnsTest extends UnitTestCase
{
    /** @var string[][] The required columns per entity class */
    protected const array REQUIRED_COLUMNS = [
        AIConversationMessage::class => ['aiConversationId', 'indexInConversation', 'message'],
        AIConversationTaskPushNotificationConfig::class => ['aiConversationId', 'taskUserMessageId', 'url'],
        AIConversationDelegationBatch::class => ['parentConversationId', 'expectedDelegationCount'],
    ];

    /** @var string[] The column that anchors the row to its conversation, per entity class */
    protected const array CONVERSATION_ANCHOR_COLUMNS = [
        AIConversationMessage::class => 'aiConversationId',
        AIConversationTaskPushNotificationConfig::class => 'aiConversationId',
        AIConversationDelegationBatch::class => 'parentConversationId',
    ];

    public function testTheRequiredColumnsAreNotNull(): void
    {
        foreach (self::REQUIRED_COLUMNS as $entityClass => $requiredColumns) {
            $createTableSql = $this->createTableSql($entityClass);
            foreach ($requiredColumns as $requiredColumn) {
                self::assertMatchesRegularExpression(
                    '/^\s*`' . $requiredColumn . '` [^\n]* NOT NULL,?$/m',
                    $createTableSql,
                    $entityClass . '::$' . $requiredColumn
                );
            }
        }
    }

    public function testTheForeignKeyToTheConversationDeletesTheRowWithItsConversation(): void
    {
        foreach (self::CONVERSATION_ANCHOR_COLUMNS as $entityClass => $anchorColumn) {
            $foreignKeyLines = $this->foreignKeyLinesByColumn($entityClass);
            self::assertArrayHasKey($anchorColumn, $foreignKeyLines, $entityClass);
            self::assertStringEndsWith('ON UPDATE CASCADE ON DELETE CASCADE;', $foreignKeyLines[$anchorColumn], $entityClass);
        }
    }

    public function testNoForeignKeySetsARequiredColumnToNull(): void
    {
        foreach (self::REQUIRED_COLUMNS as $entityClass => $requiredColumns) {
            $createTableSql = $this->createTableSql($entityClass);
            foreach ($this->foreignKeyLinesByColumn($entityClass) as $column => $foreignKeyLine) {
                if (!str_contains($foreignKeyLine, 'ON DELETE SET NULL')) {
                    continue;
                }
                self::assertNotContains($column, $requiredColumns, $entityClass . '::$' . $column);
                self::assertMatchesRegularExpression(
                    '/^\s*`' . $column . '` [^\n]* DEFAULT NULL,?$/m',
                    $createTableSql,
                    $entityClass . '::$' . $column . ' is set to NULL by its foreign key, so it must allow NULL'
                );
            }
        }
    }

    protected function generatedSql(string $entityClass): string
    {
        return EntityModelGeneratorService::getDatabaseModels([$entityClass])->first()->getSql();
    }

    /** The CREATE TABLE statement of the entity's table (the column definitions). */
    protected function createTableSql(string $entityClass): string
    {
        $generatedSql = $this->generatedSql($entityClass);
        self::assertStringContainsString('CREATE TABLE', $generatedSql, $entityClass);
        $firstAlterTablePosition = strpos($generatedSql, 'ALTER TABLE');
        return $firstAlterTablePosition === false ? $generatedSql : substr($generatedSql, 0, $firstAlterTablePosition);
    }

    /** @return string[] The ADD CONSTRAINT … FOREIGN KEY lines of the entity's table, by their column */
    protected function foreignKeyLinesByColumn(string $entityClass): array
    {
        $foreignKeyLinesByColumn = [];
        foreach (explode("\n", $this->generatedSql($entityClass)) as $line) {
            if (preg_match('/ADD CONSTRAINT .* FOREIGN KEY[^(]*\(`(\w+)`\)/', $line, $matches) === 1) {
                $foreignKeyLinesByColumn[$matches[1]] = trim($line);
            }
        }
        return $foreignKeyLinesByColumn;
    }
}
