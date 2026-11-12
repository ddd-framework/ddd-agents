<?php

declare(strict_types=1);

namespace AgentsReference\Presentation\Mcp\Demo;

use AgentsReference\Domain\Demo\Services\DemoNotesService;
use AgentsReference\Presentation\Mcp\Demo\Dtos\DemoNoteAddRequestDto;
use AgentsReference\Presentation\Mcp\Demo\Dtos\DemoNotesGetRequestDto;
use AgentsReference\Presentation\Mcp\Demo\Dtos\DemoNotesMcpResponseDto;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;

/**
 * DEMO ONLY — the reference application's one MCP package: a read tool and a write tool over the demo owner's notes,
 * offered through the skill `Demo.Notes` (`config/app/AI/Skills/Demo/Notes.md`, bound in `config/app/AI/skillPackages.php`).
 */
class DemoNotesMCPPackage extends MCPPackage
{
    public static ?string $name = 'demo.notes';

    public static ?string $description = 'Read and add the owner\'s notes.';

    /**
     * The owner's notes, oldest first.
     *
     * @example {}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('The owner\'s notes, oldest first.')]
    public function getDemoNotes(DemoNotesGetRequestDto $requestDto, DemoNotesService $demoNotesService): DemoNotesMcpResponseDto
    {
        $responseDto = new DemoNotesMcpResponseDto();
        $responseDto->notes = $demoNotesService->findNotesOfCurrentAccount();
        return $responseDto;
    }

    /**
     * Keeps one note for the owner; returns all notes afterwards.
     *
     * @example {"text": "Order coffee beans on Friday"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolSummary('Keep one note for the owner (the owner\'s words); returns all notes.')]
    public function addDemoNote(DemoNoteAddRequestDto $requestDto, DemoNotesService $demoNotesService): DemoNotesMcpResponseDto
    {
        $responseDto = new DemoNotesMcpResponseDto();
        $responseDto->notes = $demoNotesService->addNoteForCurrentAccount($requestDto->text);
        return $responseDto;
    }
}
