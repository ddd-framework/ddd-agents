<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The answer pills of a seeded closed question are declared on
 * the plan item and attached by the platform — the seed round trip and the "ends on a question" trigger.
 */
class TaskPlanClosedQuestionPillsTest extends UnitTestCase
{
    public function testSeedRoundTripNormalizationAndResendCarry(): void
    {
        $items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Reach', 'closedQuestionOptions' => ['de' => ['Kunden kommen zu uns', 'Wir fahren zu den Kunden', 'Beides'], 'EN' => ['Customers come to us', 'We go out', 'Both']]],
            ['text' => 'Broken', 'closedQuestionOptions' => ['de' => ['only one'], 'xx1' => ['a', 'b']]],
        ]);
        $reach = $items->getElements()[0];
        self::assertSame(['de', 'en'], array_keys($reach->closedQuestionOptions));
        self::assertNull($items->getElements()[1]->closedQuestionOptions, 'one option or a bad language code is no closed question');
        $roundTrip = TaskPlanItem::fromRepositoryFields($reach->toRepositoryFields());
        self::assertSame($reach->closedQuestionOptions, $roundTrip->closedQuestionOptions);
    }

    public function testTheTriggerIsAFinalTextEndingOnAQuestion(): void
    {
        self::assertTrue(AIConversationMessages::textEndsWithOwnerQuestion("Hier ist dein Entwurf.\n\nSoll ich ihn so übernehmen — oder möchtest du etwas ändern?"));
        self::assertTrue(AIConversationMessages::textEndsWithOwnerQuestion("Frage?\n```widget\n{\"type\": \"DirectoriesSnapshot\"}\n```"), 'a trailing widget does not hide the question');
        self::assertTrue(AIConversationMessages::textEndsWithOwnerQuestion('**Passt das so?**'));
        self::assertTrue(AIConversationMessages::textEndsWithOwnerQuestion("Soll ich das speichern? Ich warte auf deine Antwort."), 'a closing remark after the question keeps the question');
        self::assertTrue(AIConversationMessages::textEndsWithOwnerQuestion("Was ist dein Ziel für die nächsten Monate?\n\nBerichte gehen an inhaber@example.de; sag Bescheid, wenn eine andere Adresse gewünscht ist."), 'the e-mail sub-clause under the last owner question is no announcement');
        self::assertFalse(AIConversationMessages::textEndsWithOwnerQuestion("Passt das?\nZeile 1.\nZeile 2.\nZeile 3.\nAls Nächstes speichere ich alles."), 'a question buried under a paragraph of announcements does not count');
        self::assertFalse(AIConversationMessages::textEndsWithOwnerQuestion("Mehr dazu unter https://example.de/?utm=setup und ich mache weiter."), 'a question mark inside a URL is no sentence end');
        self::assertFalse(AIConversationMessages::textEndsWithOwnerQuestion(''));
    }
}
