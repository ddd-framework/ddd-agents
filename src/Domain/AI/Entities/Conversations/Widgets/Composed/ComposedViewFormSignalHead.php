<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The first line of ONE form signal of a `ComposedView` tree (`ComposedView form "<name>" submitted:` or
 * `… declined.`): the form it names and its verb. Built by
 * {@see ComposedViewConversationWidget::readFormSignalHead()} only for a form the tree carries.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewFormSignalHead extends ValueObject
{
    public const string VERB_SUBMITTED = 'submitted:';

    public const string VERB_DECLINED = 'declined.';

    /** @var string The `name` of the tree's `Form` node the signal names */
    public string $formName = '';

    /** @var string The verb of the signal as written: {@see VERB_SUBMITTED} or {@see VERB_DECLINED} */
    public string $verb = '';

    /**
     * @param string $formName
     * @param string $verb
     * @return static
     */
    public static function forFormNameAndVerb(string $formName, string $verb): static
    {
        $composedViewFormSignalHead = new static();
        $composedViewFormSignalHead->formName = $formName;
        $composedViewFormSignalHead->verb = $verb;
        return $composedViewFormSignalHead;
    }

    /** @return bool The signal submits the form's fields */
    public function isSubmitted(): bool
    {
        return $this->verb === self::VERB_SUBMITTED;
    }

    /** @return bool The owner declined the form */
    public function isDeclined(): bool
    {
        return $this->verb === self::VERB_DECLINED;
    }
}
