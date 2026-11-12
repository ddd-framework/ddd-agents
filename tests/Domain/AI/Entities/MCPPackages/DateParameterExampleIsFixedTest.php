<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\MCPPackages\MCPObjectProperty;
use DDD\Domain\AI\Entities\MCPPackages\MCPSchema;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes\ProbeRequestWithDateParameters;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The tool list is part of the prompt prefix the model provider caches. An example that shows the day of the call
 * changes every tool with a `Date` parameter at midnight and with it the cached prefix of every conversation, so the
 * example of a day and of a moment is a fixed value.
 */
class DateParameterExampleIsFixedTest extends UnitTestCase
{
    public function testADayParameterDocumentsAFixedExampleAndNotTheDayOfTheCall(): void
    {
        $firstDayProperty = $this->documentProperty('firstDay');

        self::assertSame('string', $firstDayProperty->type);
        self::assertSame(['2026-03-14'], $firstDayProperty->examples);
        self::assertStringContainsString('YYYY-MM-DD', $firstDayProperty->description);
        self::assertNotSame([date('Y-m-d')], $firstDayProperty->examples);
    }

    public function testAMomentParameterDocumentsAFixedExample(): void
    {
        $runAtProperty = $this->documentProperty('runAt');

        self::assertSame(['2026-03-14 09:30:00'], $runAtProperty->examples);
    }

    protected function documentProperty(string $propertyName): MCPObjectProperty
    {
        $reflectionClass = ReflectionClass::instance(ProbeRequestWithDateParameters::class);
        /** @var MCPSchema $mcpSchema */
        $mcpSchema = $this->instantiateWithoutConstructor(MCPSchema::class);
        $mcpObjectProperty = MCPObjectProperty::createFromReflectionProperty(
            $mcpSchema,
            $reflectionClass,
            $reflectionClass->getProperty($propertyName)
        );
        self::assertInstanceOf(MCPObjectProperty::class, $mcpObjectProperty);
        return $mcpObjectProperty;
    }
}
