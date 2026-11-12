<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The generated DDL of the space and of the three tables that reference one: the reference is optional (an empty one
 * is the account level, on a mandate account-wide), and deleting a space deletes its rows — it never empties the
 * reference, which would move a conversation to the account level and widen a mandate to every space. Generated in
 * memory under the package kernel, nothing is written and no database is asked.
 */
class GeneratedDdlAgentSpaceTest extends UnitTestCase
{
    /** @var string[] The entity classes whose rows reference a space */
    protected const array ENTITY_CLASSES_REFERENCING_A_SPACE = [AIConversation::class, AIConversationSchedule::class, AgentMandate::class];

    public function testTheReferenceToASpaceIsOptional(): void
    {
        foreach (self::ENTITY_CLASSES_REFERENCING_A_SPACE as $entityClass) {
            self::assertMatchesRegularExpression(
                '/^\s*`agentSpaceId` [^\n]* DEFAULT NULL,?$/m',
                $this->createTableSql($entityClass),
                $entityClass
            );
        }
    }

    public function testDeletingASpaceDeletesItsRows(): void
    {
        foreach (self::ENTITY_CLASSES_REFERENCING_A_SPACE as $entityClass) {
            $foreignKeyLines = $this->foreignKeyLinesByColumn($entityClass);
            self::assertArrayHasKey('agentSpaceId', $foreignKeyLines, $entityClass);
            self::assertStringContainsString('REFERENCES `AgentSpaces`', $foreignKeyLines['agentSpaceId'], $entityClass);
            self::assertStringEndsWith('ON DELETE CASCADE;', $foreignKeyLines['agentSpaceId'], $entityClass);
        }
    }

    public function testTheStartOrReopenLookupBySpaceHasItsIndex(): void
    {
        self::assertMatchesRegularExpression(
            '/\(`agentSpaceId`,\s*`accountId`,\s*`entryPointKey`\)/',
            $this->generatedSql(AIConversation::class)
        );
    }

    public function testTheAccountOfASpaceIsOptionalAndDeletesItsSpaces(): void
    {
        self::assertMatchesRegularExpression('/^\s*`accountId` [^\n]* DEFAULT NULL,?$/m', $this->createTableSql(AgentSpace::class));
        $foreignKeyLines = $this->foreignKeyLinesByColumn(AgentSpace::class);
        self::assertArrayHasKey('accountId', $foreignKeyLines);
        self::assertStringEndsWith('ON DELETE CASCADE;', $foreignKeyLines['accountId']);
    }

    public function testTheTimezoneOfASpaceIsAShortTextWithoutAnIndex(): void
    {
        self::assertMatchesRegularExpression('/^\s*`timezone` VARCHAR\(64\) [^\n]*DEFAULT NULL,?$/mi', $this->createTableSql(AgentSpace::class));
        self::assertDoesNotMatchRegularExpression('/(KEY|INDEX)[^\n]*\(`timezone`\)/i', $this->generatedSql(AgentSpace::class));
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
