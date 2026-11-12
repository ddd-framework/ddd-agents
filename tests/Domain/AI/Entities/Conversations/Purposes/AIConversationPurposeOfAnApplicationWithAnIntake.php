<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/**
 * The purpose class of an application that takes a new customer in — by chat or by phone — and writes a nightly
 * digest: it declares the names of its purposes and redeclares the four lists of the module's purpose class.
 */
class AIConversationPurposeOfAnApplicationWithAnIntake extends AIConversationPurpose
{
    public const string INTAKE_CHAT = 'Intake.Chat';

    public const string INTAKE_CALL = 'Intake.Call';

    public const string NIGHTLY_DIGEST = 'Nightly.Digest';

    protected const array REGISTERED_PURPOSE_NAMES = [
        self::BACKGROUND_MECHANICAL_OPERATION,
        self::SUPERVISOR_CHECKPOINT,
        self::SCHEDULE_RUN,
        self::INTAKE_CHAT,
        self::INTAKE_CALL,
        self::NIGHTLY_DIGEST,
    ];

    protected const array CUSTOMER_HIDDEN_PURPOSE_NAMES = [
        self::SUPERVISOR_CHECKPOINT,
        self::BACKGROUND_MECHANICAL_OPERATION,
        self::NIGHTLY_DIGEST,
    ];

    protected const array PURPOSE_NAMES_CLOSED_WITHOUT_AN_OWNER_TURN = [self::INTAKE_CHAT];

    protected const array PURPOSE_NAMES_HELD_AS_A_PHONE_CALL = [self::INTAKE_CALL];
}
