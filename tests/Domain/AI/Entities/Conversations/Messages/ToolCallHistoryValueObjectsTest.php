<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\PlatformVerifiedSocialProfileUrls;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsSwitchRow;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallFailedReadStreak;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The value objects the tool-call history and the run settings return: the failure streak of a read, the verified
 * social profile URLs per network, and one parsed switch row.
 */
class ToolCallHistoryValueObjectsTest extends UnitTestCase
{
    public function testTheFailedReadStreakCarriesItsCountAndLastFailure(): void
    {
        $toolCallFailedReadStreak = ToolCallFailedReadStreak::forFailedCountAndLastFailureResult(2, 'The page could not be read.');

        self::assertSame(2, $toolCallFailedReadStreak->failedCount);
        self::assertSame('The page could not be read.', $toolCallFailedReadStreak->lastFailureResult);
    }

    public function testTheVerifiedUrlsAreReadPerNetwork(): void
    {
        $platformVerifiedSocialProfileUrls = PlatformVerifiedSocialProfileUrls::forUrlsByNetworkAndMessageIndex(
            ['instagram' => ['https://instagram.com/example']],
            7
        );

        self::assertSame(['https://instagram.com/example'], $platformVerifiedSocialProfileUrls->getUrlsOfNetwork('instagram'));
        self::assertSame([], $platformVerifiedSocialProfileUrls->getUrlsOfNetwork('facebook'));
        self::assertSame(7, $platformVerifiedSocialProfileUrls->messageIndex);
    }

    public function testASwitchRowIsReadIntoValidityAndStoredValue(): void
    {
        $expectedByText = [
            'inherit' => [true, null],
            ' Yes ' => [true, true],
            'on' => [true, true],
            '0' => [true, false],
            'off' => [true, false],
            'maybe' => [false, null],
        ];
        foreach ($expectedByText as $switchText => [$expectedValidity, $expectedStoredValue]) {
            $runSettingsSwitchRow = RunSettingsOverride::parseSwitchRow((string)$switchText);
            self::assertInstanceOf(RunSettingsSwitchRow::class, $runSettingsSwitchRow);
            self::assertSame($expectedValidity, $runSettingsSwitchRow->isValid, (string)$switchText);
            self::assertSame($expectedStoredValue, $runSettingsSwitchRow->storedValue, (string)$switchText);
        }
    }
}
