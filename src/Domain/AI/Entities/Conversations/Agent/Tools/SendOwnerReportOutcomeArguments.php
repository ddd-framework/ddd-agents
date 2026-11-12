<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The checked arguments of ONE `send_owner_report` call under the outcome contract: the normalized outcome and subject
 * and the corrective feedback lines. Built by {@see SendOwnerReportTool::validateOutcomeArguments()}; a call with
 * feedback is refused.
 *
 * @phpstan-consistent-constructor
 */
class SendOwnerReportOutcomeArguments extends ValueObject
{
    /** @var string The outcome in upper case ({@see SendOwnerReportTool::OUTCOMES}); '' when missing or not a string */
    public string $outcome = '';

    /** @var string The subject on one line; '' when none was sent */
    public string $subject = '';

    /** @var string[] The corrective feedback lines; empty when the call is accepted */
    public array $feedback = [];

    /**
     * @param string $outcome
     * @param string $subject
     * @param string[] $feedback
     * @return static
     */
    public static function forOutcomeSubjectAndFeedback(string $outcome, string $subject, array $feedback): static
    {
        $sendOwnerReportOutcomeArguments = new static();
        $sendOwnerReportOutcomeArguments->outcome = $outcome;
        $sendOwnerReportOutcomeArguments->subject = $subject;
        $sendOwnerReportOutcomeArguments->feedback = $feedback;
        return $sendOwnerReportOutcomeArguments;
    }

    /** @return bool Whether the call is accepted (no corrective feedback) */
    public function isAccepted(): bool
    {
        return $this->feedback === [];
    }

    /** @return bool Whether the outcome is {@see SendOwnerReportTool::OUTCOME_REPORT} */
    public function isReport(): bool
    {
        return $this->outcome === SendOwnerReportTool::OUTCOME_REPORT;
    }
}
