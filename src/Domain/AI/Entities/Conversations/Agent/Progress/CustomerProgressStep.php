<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Progress;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * ONE customer-facing progress step of a purposed conversation (a guided run's stepper):
 * the CUSTOMER's view of one phase of the conversation's seeded task plan — key + translated label + an
 * aggregate status — derived from the plan items that declare membership via
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::$customerProgressStepKey}. Deliberately
 * carries NONE of the plan's internals (item texts are English agent instructions and never reach the
 * customer wire); the raw plan stays admin-only.
 */
class CustomerProgressStep extends ValueObject
{
    /** @var string Every member plan item reached a terminal status — the step renders as completed. */
    public const string STATUS_DONE = 'done';

    /** @var string The FIRST step (in declared order) that still has open member items — the one being worked. */
    public const string STATUS_ACTIVE = 'active';

    /** @var string Not yet worked — later in the declared order than the active step. */
    public const string STATUS_UPCOMING = 'upcoming';

    /** @var string The step's stable identifier from the purpose declaration (e.g. 'identify'). */
    public string $key = '';

    /**
     * @var string The customer-facing label — the ENGLISH SOURCE STRING of a JS-scope translation key
     * (declared in the purpose frontmatter); the frontend renders it through its translation layer.
     */
    public string $customerLabel = '';

    /** @var string The aggregate status over the step's member plan items. */
    #[Choice(choices: [self::STATUS_DONE, self::STATUS_ACTIVE, self::STATUS_UPCOMING])]
    public string $status = self::STATUS_UPCOMING;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->key);
    }
}
