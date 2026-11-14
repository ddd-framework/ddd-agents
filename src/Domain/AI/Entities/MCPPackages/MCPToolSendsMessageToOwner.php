<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method that sends a message to the owner over a channel outside the conversation (an email, a
 * text message). A run of a task schedule blocks every such tool for itself and its delegation tree: the platform
 * delivers the run's report over the schedule's report channel, and a send tool would be a second delivery path
 * ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\ScheduleRunPurpose::getToolNamesBlockedByDeclaration()}).
 *
 * Read by tool name through {@see MCPToolAttributeCatalog}, which reads the method of the class an application binds:
 * an application that declares the tool on its own package class repeats the attribute there.
 *
 *   #[MCPTool, MCPToolSendsMessageToOwner('sends an email to the owner')]
 *   public function mailTheOwner(MailToOwnerRequestDto $requestDto): MailToOwnerResponseDto {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolSendsMessageToOwner
{
    public function __construct(public string $reason = '')
    {
    }
}
