<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettingsOverride;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The compaction override parses the raw option values (blank = not passed), refuses a value that is no whole number
 * or below its minimum, and writes only the passed knobs onto a conversation's settings.
 */
class CompactionSettingsOverrideTest extends UnitTestCase
{
    public function testNothingPassedGivesNoOverride(): void
    {
        self::assertNull(CompactionSettingsOverride::fromRawValues());
        self::assertNull(CompactionSettingsOverride::fromRawValues(' ', '', null, null, ''));
    }

    public function testEveryKnobIsParsed(): void
    {
        $compactionSettingsOverride = CompactionSettingsOverride::fromRawValues('200', '0', ' 3 ', '2', '1');

        self::assertSame(200, $compactionSettingsOverride?->triggerTokens);
        self::assertSame(0, $compactionSettingsOverride?->keepRecentMessages);
        self::assertSame(3, $compactionSettingsOverride?->segmentMessages);
        self::assertSame(2, $compactionSettingsOverride?->tierFanIn);
        self::assertSame(1, $compactionSettingsOverride?->levelRetention);
    }

    public function testApplyToMergesIntoACopyAndKeepsTheFieldsThatWereNotPassed(): void
    {
        $storedSettings = CompactionSettings::newInstance();
        $storedSettings->triggerTokens = 5000;
        $storedSettings->aiModelName = 'SOME.MODEL';
        $storedSettings->tierFanIn = 4;

        $appliedSettings = CompactionSettingsOverride::fromRawValues(null, '2')?->applyTo($storedSettings);

        self::assertNotNull($appliedSettings);
        self::assertNotSame($storedSettings, $appliedSettings, 'the result is a copy');
        self::assertSame(5000, $appliedSettings->triggerTokens);
        self::assertSame('SOME.MODEL', $appliedSettings->aiModelName);
        self::assertSame(4, $appliedSettings->tierFanIn);
        self::assertSame(2, $appliedSettings->keepRecentMessages);
    }

    public function testApplyToNeverChangesTheSettingsPassedIn(): void
    {
        $storedSettings = CompactionSettings::newInstance();
        $storedSettings->triggerTokens = 5000;

        CompactionSettingsOverride::fromRawValues('200', '2', '3', '2', '1')?->applyTo($storedSettings);

        self::assertSame(5000, $storedSettings->triggerTokens);
        self::assertNull($storedSettings->keepRecentMessages);
        self::assertNull($storedSettings->segmentMessages);
        self::assertNull($storedSettings->tierFanIn);
        self::assertNull($storedSettings->levelRetention);
    }

    public function testApplyToWithoutSettingsBuildsSettingsWithThePassedKnobsOnly(): void
    {
        $appliedSettings = CompactionSettingsOverride::fromRawValues('200')?->applyTo(null);

        self::assertInstanceOf(CompactionSettings::class, $appliedSettings);
        self::assertSame(200, $appliedSettings->triggerTokens);
        self::assertNull($appliedSettings->keepRecentMessages);
        self::assertTrue($appliedSettings->isEnabled());
    }

    public function testAThresholdThatIsNoWholeNumberIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("triggerTokens must be a whole number of at least 1, got '2k'");
        CompactionSettingsOverride::fromRawValues('2k');
    }

    public function testAThresholdTooLargeForAnIntegerIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("triggerTokens is too large, got '99999999999999999999999'");
        CompactionSettingsOverride::fromRawValues('99999999999999999999999');
    }

    public function testAZeroThresholdIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("triggerTokens must be a whole number of at least 1, got '0'");
        CompactionSettingsOverride::fromRawValues('0');
    }

    public function testAFanInOfOneIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("tierFanIn must be a whole number of at least 2, got '1'");
        CompactionSettingsOverride::fromRawValues(null, null, null, '1');
    }
}
