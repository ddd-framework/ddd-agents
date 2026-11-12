<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentBackgroundOperationOutcome;
use DDD\Domain\AI\Entities\Conversations\Purposes\BackgroundMechanicalOperationPurpose;
use DDD\Domain\AI\Services\Conversations\Agent\AgentBackgroundOperationsService;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The module's own mechanical operation is the run of one tool. Every other operation is an application's: its
 * background operations service names it, labels its seed message and carries it out.
 */
class BackgroundMechanicalOperationsOfTheApplicationTest extends UnitTestCase
{
    public function testTheModuleNamesTheToolRunAndNoOperationOfOneApplication(): void
    {
        $operationConstantNames = [];
        foreach (array_keys((new ReflectionClass(BackgroundMechanicalOperationPurpose::class))->getConstants()) as $constantName) {
            if (str_starts_with($constantName, 'OPERATION_')) {
                $operationConstantNames[] = $constantName;
            }
        }

        self::assertSame(['OPERATION_EXECUTE_TOOL'], $operationConstantNames);
    }

    public function testTheSeedOfAToolRunIsLabelledWithItsTool(): void
    {
        self::assertSame(
            "Background job: run tool readStock\n" . '{"operation":"executeTool","toolName":"readStock","toolArguments":{"shelf":"A4"}}',
            BackgroundMechanicalOperationPurpose::buildOperationSeedText(BackgroundMechanicalOperationPurpose::OPERATION_EXECUTE_TOOL, 'readStock', ['shelf' => 'A4'])
        );
    }

    public function testAnOperationWithoutALabelOfTheApplicationIsLabelledWithItsName(): void
    {
        $seedText = BackgroundMechanicalOperationPurpose::buildOperationSeedText('checkStockOfTheShop');

        self::assertSame("Background job: checkStockOfTheShop\n" . '{"operation":"checkStockOfTheShop"}', $seedText);
        self::assertSame('checkStockOfTheShop', BackgroundMechanicalOperationPurpose::parseOperationSeedText($seedText)->operation);
    }

    public function testAnApplicationNamesLabelsAndCarriesOutItsOperation(): void
    {
        $operationsServiceOfTheApplication = new AgentBackgroundOperationsServiceOfAnApplicationWithAStockCheck();
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();

        self::assertSame(['checkStockOfTheShop'], $operationsServiceOfTheApplication->getSupportedOperationNames());
        self::assertSame('Background job: check the stock of the shop', $operationsServiceOfTheApplication->getOperationLabel('checkStockOfTheShop'));
        self::assertNull($operationsServiceOfTheApplication->getOperationLabel('executeTool'));
        self::assertFalse($operationsServiceOfTheApplication->runMechanicalOperation($conversation, 'checkStockOfTheShop')->operationFailed);
        self::assertTrue($operationsServiceOfTheApplication->runMechanicalOperation($conversation, 'somethingElse')->operationFailed);
    }
}

/** An application whose tools ask for a stock check of the shop in the background. */
class AgentBackgroundOperationsServiceOfAnApplicationWithAStockCheck extends AgentBackgroundOperationsService
{
    public const string OPERATION_CHECK_STOCK_OF_THE_SHOP = 'checkStockOfTheShop';

    public function getSupportedOperationNames(): array
    {
        return [self::OPERATION_CHECK_STOCK_OF_THE_SHOP];
    }

    public function getOperationLabel(string $operation): ?string
    {
        return $operation === self::OPERATION_CHECK_STOCK_OF_THE_SHOP ? 'Background job: check the stock of the shop' : null;
    }

    public function runMechanicalOperation(AIConversation $conversation, string $operation): AgentBackgroundOperationOutcome
    {
        if ($operation !== self::OPERATION_CHECK_STOCK_OF_THE_SHOP) {
            return parent::runMechanicalOperation($conversation, $operation);
        }
        return AgentBackgroundOperationOutcome::completed('The stock of the shop is checked.');
    }
}
