<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The checked arguments of ONE `send_owner_report` call stored before the outcome contract, when the call carried the
 * report itself: the normalized subject and body and the corrective feedback lines. Built by
 * {@see SendOwnerReportTool::validateArguments()}; a call with feedback sends nothing.
 *
 * @phpstan-consistent-constructor
 */
class SendOwnerReportLegacyArguments extends ValueObject
{
    /** @var string The subject on one line; '' when none was sent */
    public string $subject = '';

    /** @var string The report body as markdown; '' when none was sent */
    public string $body = '';

    /** @var string[] The corrective feedback lines; empty when the call is accepted */
    public array $feedback = [];

    /**
     * @param string $subject
     * @param string $body
     * @param string[] $feedback
     * @return static
     */
    public static function forSubjectBodyAndFeedback(string $subject, string $body, array $feedback): static
    {
        $sendOwnerReportLegacyArguments = new static();
        $sendOwnerReportLegacyArguments->subject = $subject;
        $sendOwnerReportLegacyArguments->body = $body;
        $sendOwnerReportLegacyArguments->feedback = $feedback;
        return $sendOwnerReportLegacyArguments;
    }

    /** @return bool Whether the call is accepted (no corrective feedback) */
    public function isAccepted(): bool
    {
        return $this->feedback === [];
    }
}
