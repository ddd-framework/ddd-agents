<?php
declare(strict_types=1);

namespace DDD\Presentation\Mcp\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCPResponse: streams a RestResponseDto as SSE (single frame) or sends static JSON.
 * - No chunk mode, no external callbacks.
 * - SSE enabled by default (text/event-stream).
 */
class MCPResponseDto extends Response
{
    protected bool $serverSentEvents;

    protected ?RestResponseDto $restDto = null;

    protected ?string $json;

    public function __construct(
        ?RestResponseDto $restResponseDto = null,
        ?string $json = null,
        bool $serverSentEvents = true,
        int $status = 200,
        array $headers = []
    ) {
        $this->serverSentEvents = $serverSentEvents;
        $this->restDto = $restResponseDto;
        $this->json = $json;

        if ($this->serverSentEvents) {
            $headers += [
                'Content-Type'      => 'text/event-stream; charset=utf-8',
                'Cache-Control'     => 'no-cache, no-transform',
                'Connection'        => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ];
            parent::__construct('', $status, $headers);
        } else {
            // Non-SSE: send static JSON once.
            $headers += ['Content-Type' => 'application/json; charset=utf-8'];
            parent::__construct($this->dtoJson(), $status, $headers);
        }
    }

    /** Toggle SSE on/off (on: stream one frame; off: static JSON). */
    public function setServerSentEvents(bool $serverSentEvents): self
    {
        $this->serverSentEvents = $serverSentEvents;
        if ($this->serverSentEvents) {
            $this->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
            $this->headers->set('Cache-Control', 'no-cache, no-transform');
            $this->headers->set('Connection', 'keep-alive');
            $this->headers->set('X-Accel-Buffering', 'no');
            $this->setContent('');
        } else {
            $this->headers->set('Content-Type', 'application/json; charset=utf-8');
            $this->setContent($this->dtoJson());
        }
        return $this;
    }

    public function setRestResponseDto(?RestResponseDto $dto): self
    {
        $this->restDto = $dto;
        if (!$this->serverSentEvents) {
            $this->setContent($this->dtoJson());
        }
        return $this;
    }

    /** Stream exactly one SSE frame when SSE is enabled. */
    public function sendContent(): static
    {
        if (!$this->serverSentEvents) {
            return parent::sendContent(); // static JSON path
        }

        // Ensure streaming is immediate (no PHP output buffering delays).
        while (ob_get_level() > 0) { @ob_end_flush(); }

        $json = $this->dtoJson();

        // SSE spec: each line must start with "data: "
        echo "event: message\n";
        foreach (preg_split("/\r\n|\r|\n/", $json) as $line) {
            echo 'data: ' . $line . "\n";
        }
        echo "\n"; // frame terminator
        @flush();

        return $this;
    }

    /** Safely get JSON from RestResponseDto or raw JSON string. */
    protected function dtoJson(): string
    {
        if ($this->json !== null) {
            return trim($this->json);
        }
        $json = $this->restDto?->getContent();
        if ($json === false || $json === '' || $json === null) {
            $json = $this->restDto?->toJSON();
        }
        $json = trim((string)($json ?? '{}'));
        return $json;
    }
}