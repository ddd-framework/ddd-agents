<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Infrastructure\Services\DDDService;

/**
 * The questions the platform understands when the model asks them through `askOwner`: a question key and the meanings
 * its options can carry. With a key the model passes one meaning per option, and the platform records the owner's pick
 * itself instead of waiting for a write of the model.
 *
 * The module knows ONE question: the approval of a draft the final text presents (a purpose's draft gate reads it). An
 * application adds the questions its own tools read back in a class at the same path (it extends this one and
 * redeclares {@see QUESTIONS}); the tool's schema, its argument check, the plan's closing rule and the supervisor's
 * briefing ask the getters, never the constants. Reads nothing.
 */
class AskOwnerQuestionCatalogue
{
    public const string QUESTION_KEY_DRAFT_APPROVAL = 'draftApproval';

    public const string MEANING_APPROVED = 'approved';
    public const string MEANING_CHANGE = 'change';

    /** @var array<string, string[]> question key => the meanings the platform reads for it, in the order the model is told them */
    public const array QUESTIONS = [
        self::QUESTION_KEY_DRAFT_APPROVAL => [self::MEANING_APPROVED, self::MEANING_CHANGE],
    ];

    /** @var string[] The keys the tool's description does not list for the model; the schema's enum still carries them. */
    public const array QUESTION_KEYS_NOT_LISTED_IN_THE_TOOL_DESCRIPTION = [];

    /**
     * The class that declares the questions: the application's class at the same path, else this one.
     *
     * @return class-string<AskOwnerQuestionCatalogue>
     */
    public static function getCatalogueClassOfTheApplication(): string
    {
        /** @var class-string<AskOwnerQuestionCatalogue> $catalogueClassOfTheApplication */
        $catalogueClassOfTheApplication = DDDService::instance()->getContainerServiceClassNameForClass(self::class) ?? self::class;
        return $catalogueClassOfTheApplication;
    }

    /** @return array<string, string[]> question key => its meanings, as the application declares them */
    public static function getQuestions(): array
    {
        return (self::getCatalogueClassOfTheApplication())::QUESTIONS;
    }

    /** @return string[] The question keys, in the catalogue's order */
    public static function getQuestionKeys(): array
    {
        return array_keys(self::getQuestions());
    }

    /**
     * The meanings of one question; null for a key the catalogue does not hold (a free question).
     *
     * @return string[]|null
     */
    public static function getMeaningsOfQuestion(string $questionKey): ?array
    {
        return self::getQuestions()[$questionKey] ?? null;
    }

    /**
     * The questions as the tool's description lists them for the model: `<key> [<meaning>, <meaning>]`, joined by a
     * comma, without the keys of {@see QUESTION_KEYS_NOT_LISTED_IN_THE_TOOL_DESCRIPTION}.
     */
    public static function buildQuestionListOfTheToolDescription(): string
    {
        $keysNotListed = (self::getCatalogueClassOfTheApplication())::QUESTION_KEYS_NOT_LISTED_IN_THE_TOOL_DESCRIPTION;
        $listedQuestions = [];
        foreach (self::getQuestions() as $questionKey => $meanings) {
            if (!in_array($questionKey, $keysNotListed, true)) {
                $listedQuestions[] = $questionKey . ' [' . implode(', ', $meanings) . ']';
            }
        }
        return implode(', ', $listedQuestions);
    }
}
