<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Briefing;

use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * How the agent sounds and which language it speaks: the field-wise merge of two layers, the configured default (a
 * configured language wins over the conversation's), the language sentences of the instructions and of the notes
 * sent while a call runs, the spelling-free provider name and the provider family.
 */
class AgentVoiceSettingsTest extends AgentVoiceUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([]));
    }

    protected static function buildSettings(?string $voiceId, ?string $languageCode, ?bool $mirrorCallerLanguage, ?string $speakingStyle): AgentVoiceSettings
    {
        $agentVoiceSettings = new AgentVoiceSettings();
        $agentVoiceSettings->voiceId = $voiceId;
        $agentVoiceSettings->languageCode = $languageCode;
        $agentVoiceSettings->mirrorCallerLanguage = $mirrorCallerLanguage;
        $agentVoiceSettings->speakingStyle = $speakingStyle;

        return $agentVoiceSettings;
    }

    /** @return array{?string, ?string, ?bool, ?string} */
    protected static function readFields(AgentVoiceSettings $agentVoiceSettings): array
    {
        return [$agentVoiceSettings->voiceId, $agentVoiceSettings->languageCode, $agentVoiceSettings->mirrorCallerLanguage, $agentVoiceSettings->speakingStyle];
    }

    public function testAnOverrideWinsOnlyWithTheFieldsItSets(): void
    {
        $lowerLayer = static::buildSettings('marin', 'de', true, 'calm');
        $overridingLayer = static::buildSettings('nuri', null, false, null);

        $mergedVoiceSettings = $lowerLayer->buildWithOverrideApplied($overridingLayer);

        static::assertSame(['nuri', 'de', false, 'calm'], static::readFields($mergedVoiceSettings));
        static::assertNotSame($lowerLayer, $mergedVoiceSettings);
        static::assertSame(['marin', 'de', true, 'calm'], static::readFields($lowerLayer), 'the lower layer is not changed');
    }

    public function testAnOverrideOfTheOtherFieldsKeepsTheVoice(): void
    {
        $mergedVoiceSettings = static::buildSettings('marin', null, null, null)
            ->buildWithOverrideApplied(static::buildSettings(null, 'fr', true, 'warm'));

        static::assertSame(['marin', 'fr', true, 'warm'], static::readFields($mergedVoiceSettings));
    }

    public function testNoOverrideGivesACopy(): void
    {
        $lowerLayer = static::buildSettings('marin', 'de', false, 'calm');

        $mergedVoiceSettings = $lowerLayer->buildWithOverrideApplied(null);

        static::assertSame(['marin', 'de', false, 'calm'], static::readFields($mergedVoiceSettings));
        static::assertNotSame($lowerLayer, $mergedVoiceSettings);
    }

    public function testAConfiguredLanguageWinsOverTheLanguageOfTheConversation(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['language' => ' fr ']));

        $configuredDefault = AgentVoiceSettings::buildConfiguredDefault('de');

        static::assertSame([null, 'fr', true, null], static::readFields($configuredDefault));
    }

    public function testWithoutAConfiguredLanguageTheConversationsLanguageAndThenThePlatformsAnswer(): void
    {
        static::assertSame([null, 'de', true, null], static::readFields(AgentVoiceSettings::buildConfiguredDefault('de')));
        static::assertSame(Translatable::getDefaultLanguageCode(), AgentVoiceSettings::buildConfiguredDefault()->languageCode);
        static::assertSame(Translatable::getDefaultLanguageCode(), AgentVoiceSettings::getPlatformDefaultLanguageCode());
    }

    public function testTheLanguageOrTheDefault(): void
    {
        static::assertSame('it', static::buildSettings(null, 'it', null, null)->getLanguageCodeOrDefault());
        static::assertSame(Translatable::getDefaultLanguageCode(), (new AgentVoiceSettings())->getLanguageCodeOrDefault());
    }

    /** @return array<string, array{?bool, string, string}> */
    public static function provideLanguageInstructions(): array
    {
        return [
            'following the caller' => [true, 'de', 'Open the call in German. From then on answer in the language the caller speaks; while you cannot tell, stay in German.'],
            'not set: following the caller' => [null, 'fr', 'Open the call in French. From then on answer in the language the caller speaks; while you cannot tell, stay in French.'],
            'held in one language' => [false, 'de', 'Speak German for the whole call, regardless of which language the caller uses. Only words in German are addressed to you: speech in another language is the caller talking to other people in the room — do not answer it, do not translate or summarise it, do not hand it on as a request; stay silent until you are addressed in German again.'],
        ];
    }

    #[DataProvider('provideLanguageInstructions')]
    public function testTheLanguageRuleOfTheInstructionsNamesTheLanguage(?bool $mirrorCallerLanguage, string $languageCode, string $expectedLanguageInstruction): void
    {
        static::assertSame($expectedLanguageInstruction, static::buildSettings(null, $languageCode, $mirrorCallerLanguage, null)->buildLanguageInstruction());
    }

    public function testTheClauseOfANoteSentWhileTheCallRunsNamesTheLanguage(): void
    {
        static::assertSame(
            ' Say it in German, the language of this call — only if the caller has clearly switched to another language since, use theirs. This note is English for the system only; never switch to English because of it.',
            AgentVoiceSettings::buildMidCallLanguageClause('de')
        );
        static::assertSame(
            ' Say it in English, the language of this call — only if the caller has clearly switched to another language since, use theirs.',
            AgentVoiceSettings::buildMidCallLanguageClause('EN')
        );
    }

    public function testALanguageNameForACode(): void
    {
        static::assertSame('German', AgentVoiceSettings::getLanguageNameForCode('de'));
        static::assertSame('English', AgentVoiceSettings::getLanguageNameForCode('en'));
    }

    /** @return array<string, array{string, string}> */
    public static function provideLanguageCodes(): array
    {
        return [
            'upper case' => ['DE', 'de'],
            'with a region' => ['de-DE', 'de'],
            'with an underscore' => ['de_AT', 'de'],
            'with spaces' => [' en ', 'en'],
        ];
    }

    #[DataProvider('provideLanguageCodes')]
    public function testALanguageCodeIsBroughtToItsStoredForm(string $languageCode, string $expectedLanguageCode): void
    {
        static::assertSame($expectedLanguageCode, AgentVoiceSettings::normalizeLanguageCode($languageCode));
    }

    public function testAProviderNameWithoutItsSpelling(): void
    {
        static::assertSame('openailive', AgentVoiceSettings::normalizeVoiceProviderName('OPENAI_LIVE'));
        static::assertSame('openailive', AgentVoiceSettings::normalizeVoiceProviderName('openAiLive'));
        static::assertSame('live2', AgentVoiceSettings::normalizeVoiceProviderName('Live-2'));
    }

    /** @return array<string, array{?string, ?string}> */
    public static function provideProviderNames(): array
    {
        return [
            'the stored spelling' => ['OPENAI_LIVE', 'LIVE'],
            'the registered spelling' => ['openAiLive', 'LIVE'],
            'no name: the configured default' => [null, 'LIVE'],
            'a name of no family the package knows' => ['otherVoice', null],
        ];
    }

    #[DataProvider('provideProviderNames')]
    public function testTheFamilyOfAProviderName(?string $voiceProviderName, ?string $expectedFamily): void
    {
        static::assertSame($expectedFamily, AgentVoiceSettings::getVoiceProviderFamilyForName($voiceProviderName));
        static::assertSame($expectedFamily === 'LIVE', AgentVoiceSettings::isLiveVoiceProviderName($voiceProviderName));
    }

    public function testADefaultOfNoKnownFamilyGivesNoFamily(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['provider' => ['default' => 'otherVoice']]));

        static::assertNull(AgentVoiceSettings::getVoiceProviderFamilyForName(null));
    }

    public function testTheCatalogOfLiveVoicesHoldsMarinOnlyAsTheDefault(): void
    {
        static::assertCount(21, AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS);
        static::assertNotContains(AgentVoiceSettings::VOICE_MARIN, AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS);
        static::assertSame(AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS, array_values(array_unique(AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS)));
    }
}
