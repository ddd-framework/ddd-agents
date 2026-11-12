<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionCatalogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `askOwner`: the closed question with the model's own answer variants — argument validation
 * (count, labels, the catalogue meanings), the widget markdown the platform renders at turn end. Pure static logic.
 * The catalogue is the one of an application with its own questions; one test reads the module's own.
 */
class AskOwnerToolTest extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[AskOwnerQuestionCatalogue::class] = AskOwnerQuestionCatalogueOfAnApplicationWithItsOwnQuestions::class;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    public function testWithoutAnApplicationTheCatalogueHoldsTheDraftApprovalOnly(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AskOwnerQuestionCatalogue::class] = AskOwnerQuestionCatalogue::class;

        $schema = AskOwnerTool::create()->toOpenAiToolSchema();
        self::assertSame(['draftApproval'], $schema['function']['parameters']['properties']['questionKey']['enum']);
        self::assertStringEndsWith('the platform records the pick itself: draftApproval [approved, change].', $schema['function']['description']);

        $questionOfAnApplication = AskOwnerTool::checkArguments([
            'question' => 'Q?', 'options' => ['A', 'B'], 'questionKey' => 'serveModel', 'optionMeanings' => ['walkIn', 'mobile'],
        ]);
        self::assertSame([], $questionOfAnApplication->feedback);
        self::assertNull($questionOfAnApplication->question->questionKey, 'a key the catalogue does not hold is a free question');
        self::assertNull($questionOfAnApplication->keyDropped);

        $draftApproval = AskOwnerTool::checkArguments([
            'question' => 'Q?', 'options' => ['A', 'B'], 'questionKey' => 'draftApproval', 'optionMeanings' => ['approved', 'change'],
        ]);
        self::assertSame('draftApproval', $draftApproval->question->questionKey);
    }

    public function testAFreeQuestionNormalizes(): void
    {
        $check = AskOwnerTool::checkArguments([
            'question' => ' Soll ich die Öffnungszeiten so übernehmen? ',
            'options' => [['label' => 'Ja, passt'], ['label' => ' Nein, ändern ', 'description' => 'Ich sage dir, was anders ist']],
        ]);
        self::assertSame([], $check->feedback);
        self::assertSame('Soll ich die Öffnungszeiten so übernehmen?', $check->question->question);
        self::assertSame(['Ja, passt', 'Nein, ändern'], $check->question->getOptionLabels());
        self::assertSame([null, 'Ich sage dir, was anders ist'], $check->question->getOptionDescriptions());
        self::assertFalse($check->question->multiSelect);
        self::assertNull($check->question->questionKey);
    }

    /** A whole profile draft inside the question put a second draft on the widget — the question is ONE question. */
    public function testAQuestionLongerThanOneQuestionIsRefusedAndTheLimitIsOnTheSchema(): void
    {
        $draftAsQuestion = str_repeat('Hier ist dein Profil-Entwurf: Name, Beschreibung, Kategorie, Öffnungszeiten rund um die Uhr. ', 4) . 'Passt das so?';
        $check = AskOwnerTool::checkArguments(['question' => $draftAsQuestion, 'options' => ['Ja', 'Nein']]);
        self::assertNull($check->question);
        self::assertStringContainsString('ONE question (≤ 300 characters', implode(' ', $check->feedback));
        self::assertNotNull(AskOwnerTool::checkArguments(['question' => str_repeat('a', 300), 'options' => ['Ja', 'Nein']])->question, 'the boundary is accepted');
        self::assertNull(AskOwnerTool::checkArguments(['question' => str_repeat('a', 301), 'options' => ['Ja', 'Nein']])->question);
        self::assertSame(300, (new AskOwnerTool())->toOpenAiToolSchema()['function']['parameters']['properties']['question']['maxLength']);
    }

    public function testPlainStringOptionsAreAccepted(): void
    {
        $check = AskOwnerTool::checkArguments(['question' => 'Which one?', 'options' => ['A', 'B', 'C']]);
        self::assertSame([], $check->feedback);
        self::assertSame(['A', 'B', 'C'], $check->question->getOptionLabels());
    }

    public function testTooFewOrTooManyOptionsAreRefused(): void
    {
        self::assertStringContainsString('2–4 options (got 1)', AskOwnerTool::checkArguments(['question' => 'Q?', 'options' => ['only']])->feedback[0]);
        self::assertStringContainsString('got 5', AskOwnerTool::checkArguments(['question' => 'Q?', 'options' => ['1', '2', '3', '4', '5']])->feedback[0]);
    }

    public function testDuplicateLabelsAndMissingQuestionAreRefused(): void
    {
        $check = AskOwnerTool::checkArguments(['options' => ['Ja', 'ja', 'Nein']]);
        self::assertStringContainsString("'question' is required", $check->feedback[0]);
        self::assertStringContainsString('listed twice', $check->feedback[1]);
        self::assertNull($check->question);
    }

    public function testACatalogueKeyNeedsOneMeaningPerOption(): void
    {
        $check = AskOwnerTool::checkArguments([
            'question' => 'Wie erreichen dich deine Kunden?',
            'options' => ['Kunden kommen zu uns', 'Wir fahren zu den Kunden', 'Beides'],
            'questionKey' => 'serveModel',
            'optionMeanings' => ['walkIn', 'mobile'],
        ]);
        self::assertSame([], $check->feedback, 'a mismatched key is dropped, never refused (the refusal looped)');
        self::assertNull($check->question->questionKey);
        self::assertStringContainsString('one meaning per option', $check->keyDropped);
        self::assertStringContainsString('walkIn, mobile, hybrid, online', $check->keyDropped);

        $check = AskOwnerTool::checkArguments([
            'question' => 'Wie erreichen dich deine Kunden?',
            'options' => ['Kunden kommen zu uns', 'Wir fahren zu den Kunden', 'Beides'],
            'questionKey' => 'serveModel',
            'optionMeanings' => ['walkIn', 'mobile', 'hybrid'],
        ]);
        self::assertSame([], $check->feedback);
        self::assertSame('serveModel', $check->question->questionKey);
        self::assertSame(['walkIn', 'mobile', 'hybrid'], $check->question->optionMeanings);
    }

    public function testAnUnknownMeaningOrKeyIsHandled(): void
    {
        $unknownMeaning = AskOwnerTool::checkArguments([
            'question' => 'Q?', 'options' => ['A', 'B'], 'questionKey' => 'addressVisibility', 'optionMeanings' => ['shown', 'private'],
        ]);
        self::assertSame([], $unknownMeaning->feedback);
        self::assertNull($unknownMeaning->question->questionKey, 'an unknown meaning drops the key — a free question');
        self::assertStringContainsString('Unknown meaning(s) for addressVisibility: private', $unknownMeaning->keyDropped);

        $unknownKey = AskOwnerTool::checkArguments(['question' => 'Q?', 'options' => ['A', 'B'], 'questionKey' => 'somethingElse']);
        self::assertSame([], $unknownKey->feedback, 'an unknown key is a free question');
        self::assertNull($unknownKey->question->questionKey);
    }

    public function testSeveralOptionsMayShareAMeaning(): void
    {
        $check = AskOwnerTool::checkArguments([
            'question' => 'Passt das so?', 'options' => ['Passt so', 'Suchbegriffe ändern', 'Wettbewerber ändern'],
            'questionKey' => 'setsApproval', 'optionMeanings' => ['approved', 'change', 'change'],
        ]);
        self::assertSame([], $check->feedback, 'three change variants next to one approval are a legitimate shape');
    }

    public function testACatalogueQuestionIsNeverMultiSelect(): void
    {
        $check = AskOwnerTool::checkArguments([
            'question' => 'Q?', 'options' => ['A', 'B'], 'questionKey' => 'draftApproval', 'optionMeanings' => ['approved', 'change'], 'multiSelect' => true,
        ]);
        self::assertSame([], $check->feedback);
        self::assertNull($check->question->questionKey, 'a multiSelect catalogue question is a free question');
        self::assertStringContainsString('single choice', $check->keyDropped);
    }

    public function testTheSingleChoiceWidgetIsAQuickRepliesFenceCarryingTheKeyAndMeanings(): void
    {
        $question = AskOwnerTool::checkArguments([
            'question' => 'Wie erreichen dich deine Kunden?',
            'options' => [['label' => 'Kunden kommen zu uns'], ['label' => 'Wir fahren raus', 'description' => 'kein Ladengeschäft']],
            'questionKey' => 'serveModel',
            'optionMeanings' => ['walkIn', 'mobile'],
        ])->question;
        $markdown = AskOwnerTool::buildWidgetMarkdown($question, 'Deine Adresse wird angezeigt.');
        self::assertStringStartsWith("**Deine Adresse wird angezeigt.**\n\nWie erreichen dich deine Kunden?\n\n```widget\n", $markdown, 'the notice, then the question text, then the options');
        $payload = json_decode(WidgetBlocks::fromMarkdown($markdown)->first()->widgetJson, true);
        self::assertSame('QuickReplies', $payload['type']);
        self::assertSame(['Kunden kommen zu uns', 'Wir fahren raus'], $payload['options']);
        self::assertSame([null, 'kein Ladengeschäft'], $payload['descriptions']);
        self::assertSame('serveModel', $payload['questionKey']);
        self::assertSame(['walkIn', 'mobile'], $payload['optionMeanings']);
        self::assertSame('Wie erreichen dich deine Kunden?', $payload['question']);
    }

    public function testTheMultiChoiceWidgetPostsTheLabelsJoined(): void
    {
        $question = AskOwnerTool::checkArguments([
            'question' => 'Welche Kanäle nutzt du?', 'options' => ['Instagram', 'Facebook', 'TikTok'], 'multiSelect' => true,
        ])->question;
        $markdown = AskOwnerTool::buildWidgetMarkdown($question);
        $payload = json_decode(WidgetBlocks::fromMarkdown($markdown)->first()->widgetJson, true);
        self::assertSame('MultiChoice', $payload['type']);
        self::assertSame('{values}', $payload['confirmAction']);
        self::assertSame([['label' => 'Instagram', 'value' => 'Instagram'], ['label' => 'Facebook', 'value' => 'Facebook'], ['label' => 'TikTok', 'value' => 'TikTok']], $payload['options']);
        self::assertArrayNotHasKey('questionKey', $payload);
    }

    public function testTheSchemaNamesTheCatalogueKeys(): void
    {
        $schema = AskOwnerTool::create()->toOpenAiToolSchema();
        self::assertSame('askOwner', $schema['function']['name']);
        self::assertSame(['question', 'options'], $schema['function']['parameters']['required']);
        self::assertSame(['serveModel', 'addressVisibility', 'draftApproval', 'setsApproval', 'connectOffer'], $schema['function']['parameters']['properties']['questionKey']['enum']);
        self::assertStringEndsWith(
            'the platform records the pick itself: serveModel [walkIn, mobile, hybrid, online], addressVisibility [shown, hidden], draftApproval [approved, change], connectOffer [connect, later].',
            $schema['function']['description'],
            'a key the application keeps out of the description is in the enum only'
        );
    }
    /** A name choice sent as serveModel with "mobile, mobile, mobile" would have recorded the serve model from a click on a name. */
    public function testACatalogueQuestionWhoseOptionsAllMeanTheSameBecomesAFreeQuestion(): void
    {
        $check = AskOwnerTool::checkArguments([
            'question' => 'Welchen Namen findest du am besten?',
            'options' => ['Kassel Beauty Mobil', 'Schönzeit Zuhause', 'Nägel & Glow mobil'],
            'questionKey' => 'serveModel',
            'optionMeanings' => ['mobile', 'mobile', 'mobile'],
        ]);
        self::assertSame([], $check->feedback);
        self::assertNull($check->question->questionKey, 'every option meaning the same: the key is dropped, the question is free');
        self::assertNull($check->question->optionMeanings);
        self::assertStringContainsString('at least two different meanings', $check->keyDropped);
        self::assertStringContainsString('FREE question', $check->keyDropped);
    }
}
