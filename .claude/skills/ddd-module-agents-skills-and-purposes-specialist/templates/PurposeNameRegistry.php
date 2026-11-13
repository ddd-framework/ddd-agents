<?php
// placeholder {{PURPOSE_CONSTANT}} sample: DEMO_NOTES_REVIEW
// placeholder {{PURPOSE_NAME}} sample: Demo.NotesReview

declare(strict_types=1);

namespace App\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose as PackageAIConversationPurpose;

/**
 * The host's purpose registry, found by the framework's same-path lookup: under `App\` at the mirrored path with no
 * configuration, under another root namespace through a services.yaml definition whose id is the package class
 * (entry skill, override points). The names of the host's purposes are registered here, the package's own names
 * included. One file per host, not per purpose.
 */
class AIConversationPurpose extends PackageAIConversationPurpose
{
    public const string {{PURPOSE_CONSTANT}} = '{{PURPOSE_NAME}}';

    protected const array REGISTERED_PURPOSE_NAMES = [
        ...parent::REGISTERED_PURPOSE_NAMES,
        self::{{PURPOSE_CONSTANT}},
    ];

    /** Purposes whose conversations a customer never sees; add the host's own hidden purposes. */
    protected const array CUSTOMER_HIDDEN_PURPOSE_NAMES = [
        ...parent::CUSTOMER_HIDDEN_PURPOSE_NAMES,
    ];
}
