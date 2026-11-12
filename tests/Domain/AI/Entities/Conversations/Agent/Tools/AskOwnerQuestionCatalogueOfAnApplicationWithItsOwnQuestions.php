<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionCatalogue;

/**
 * Fixture: the catalogue of an application that adds four questions of its own around the module's draft approval and
 * keeps one of them out of the tool's description. The recorded gate tables were written by such an application.
 */
class AskOwnerQuestionCatalogueOfAnApplicationWithItsOwnQuestions extends AskOwnerQuestionCatalogue
{
    public const array QUESTIONS = [
        'serveModel' => ['walkIn', 'mobile', 'hybrid', 'online'],
        'addressVisibility' => ['shown', 'hidden'],
        self::QUESTION_KEY_DRAFT_APPROVAL => [self::MEANING_APPROVED, self::MEANING_CHANGE],
        'setsApproval' => [self::MEANING_APPROVED, self::MEANING_CHANGE],
        'connectOffer' => ['connect', 'later'],
    ];

    public const array QUESTION_KEYS_NOT_LISTED_IN_THE_TOOL_DESCRIPTION = ['setsApproval'];
}
