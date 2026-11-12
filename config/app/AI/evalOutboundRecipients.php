<?php

declare(strict_types=1);

/**
 * The test sinks an eval run may send to — the package default is empty (no outbound recipient is allowed in eval
 * context). Read as `Config::get('AI.evalOutboundRecipients')` by
 * {@see \DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService}. An application replaces this file whole.
 */
return [
    'allowedEmailDomains' => [],
    'allowedEmailAddresses' => [],
    'allowedPhoneNumbersE164' => [],
];
