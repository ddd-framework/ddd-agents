<?php
declare(strict_types=1);

namespace DDD\Presentation\Mcp\Dtos;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindableResultRecord;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * MCP Tool Call Response
 * Encapsulates tool execution results in MCP format with content and structured data
 *
 * @phpstan-consistent-constructor
 */
class MCPToolResponse extends ValueObject
{
    /** @var array Content array with text/image/resource entries */
    public array $content = [];

    /** @var object|array|null Structured content payload for programmatic access */
    public object|array|null $structuredContent = null;

    /** @var bool Whether this response represents an error */
    public bool $isError = false;

    /** @var string The mandate gate refused: no active mandate for the action class (grant one, then call again). */
    public const string REFUSAL_MANDATE_REQUIRED = 'MANDATE_REQUIRED';

    /** @var string The owner-confirmation gate refused: the widget was not clicked yet (emit it, wait, call again). */
    public const string REFUSAL_OWNER_CONFIRMATION_REQUIRED = 'OWNER_CONFIRMATION_REQUIRED';

    /** The space or account the call works for is not eligible for automated AI work — locked, cancelled, deleted or erased. */
    public const string REFUSAL_SCOPE_NOT_ELIGIBLE = 'SCOPE_NOT_ELIGIBLE';

    /** A tool of the admin audience was called by an account that is not an admin with access to all resources. */
    public const string REFUSAL_ADMIN_ACCESS_REQUIRED = 'ADMIN_ACCESS_REQUIRED';

    /**
     * @var string|null Set when the executor's GATE refused the call BEFORE it ran (one of the REFUSAL_* consts).
     *      A gate refusal is a sanctioned "call again once the gate opens" — the loop's repeat guards must not count
     *      it as a no-progress repeat (a mandate refusal, a confirmation refusal and the authorized call made three
     *      identical calls and the guard swallowed the real one). Null for a real tool error and for success.
     */
    public ?string $refusalKind = null;

    /**
     * @var array<string, int|float|string>|null The FACTS of a markdown-rendered result, extracted by the platform
     *      from the result's serialized shape: every number and state the result carries, keyed by path. PERSISTED
     *      with the result so the application's account record can preserve what a tool read, verbatim; NEVER on the
     *      model wire ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::toOpenAiToolResultArray()}
     *      strips it) — the model reads the markdown. Null when the result is structured already (it carries everything)
     *      or a plain text.
     */
    public ?array $preservedFacts = null;

    /**
     * The tool's platform note for the model ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider}).
     * PROTECTED on purpose: the serializer writes only public fields into the stored result, and the note must never
     * be part of it (the injection screening reads the result).
     */
    protected ?string $platformNoteForModel = null;

    public function setPlatformNoteForModel(?string $platformNoteForModel): void
    {
        $this->platformNoteForModel = $platformNoteForModel !== null && trim($platformNoteForModel) !== '' ? $platformNoteForModel : null;
    }

    public function getPlatformNoteForModel(): ?string
    {
        return $this->platformNoteForModel;
    }

    /**
     * The widget type the tool's note dictates without a condition ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolResponseDictatedWidgetTypeProvider}).
     * PROTECTED like the platform note: a platform fact for the agent loop, never part of the HTTP MCP response.
     */
    protected ?string $dictatedWidgetType = null;

    public function setDictatedWidgetType(?string $dictatedWidgetType): void
    {
        $this->dictatedWidgetType = $dictatedWidgetType !== null && $dictatedWidgetType !== '' ? $dictatedWidgetType : null;
    }

    public function getDictatedWidgetType(): ?string
    {
        return $this->dictatedWidgetType;
    }

    /**
     * What a bindable result keeps for a `ComposedView`
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindableResultRecord}).
     * PROTECTED like the platform note: the HTTP MCP response never carries it; the agent path folds it into the
     * persisted result ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService}).
     */
    protected ?ComposedViewBindableResultRecord $bindableResultRecord = null;

    public function setBindableResultRecord(?ComposedViewBindableResultRecord $bindableResultRecord): void
    {
        $this->bindableResultRecord = $bindableResultRecord;
    }

    public function getBindableResultRecord(): ?ComposedViewBindableResultRecord
    {
        return $this->bindableResultRecord;
    }

    /**
     * @param array|string $content Content array or single text message
     * @param object|array|null $structuredContent Structured data payload
     * @param bool $isError Whether this is an error response
     */
    public function __construct(
        array|string $content = [],
        object|array|null $structuredContent = null,
        bool $isError = false
    )
    {
        parent::__construct();
        // If content is a string, wrap it in standard format
        if (is_string($content)) {
            $this->content = [
                ['type' => 'text', 'text' => $content]
            ];
        } else {
            $this->content = $content;
        }
        
        $this->structuredContent = $structuredContent;
        $this->isError = $isError;
    }

    /**
     * Create a successful tool response with data
     */
    public static function success(string $message, object|array|null $structuredContent = null): static
    {
        return new static(
            content: [
                ['type' => 'text', 'text' => $message],
            ],
            structuredContent: $structuredContent,
            isError: false
        );
    }

    /**
     * Create an ERROR tool response — a text message with the error flag set.
     */
    /** A GATE refusal: an error response the loop recognizes as "not executed, call again when the gate opens". */
    public static function gateRefusal(string $message, string $refusalKind): self
    {
        $response = self::error($message);
        $response->refusalKind = $refusalKind;
        return $response;
    }

    public static function error(string $message): static
    {
        return new static(
            content: [
                ['type' => 'text', 'text' => $message],
            ],
            isError: true
        );
    }

    /**
     * Add a text content item
     */
    public function addTextContent(string $text): self
    {
        $this->content[] = ['type' => 'text', 'text' => $text];
        return $this;
    }

    /**
     * Add an image content item. A DURABLE picture names its `$sourceStore` (the name a
     * {@see \DDD\Domain\AI\Entities\MCPPackages\ToolCallImageStore} package declares) + `$sourceImageId`, so the loop can
     * re-resolve it on later model calls; without them the image is inline — it rides once and is gone.
     */
    public function addImageContent(string $data, string $mimeType = 'image/png', ?string $sourceStore = null, ?string $sourceImageId = null): self
    {
        $imagePart = [
            'type' => 'image',
            'data' => $data,
            'mimeType' => $mimeType
        ];
        if ($sourceStore !== null && $sourceStore !== '' && $sourceImageId !== null && $sourceImageId !== '') {
            $imagePart['sourceStore'] = $sourceStore;
            $imagePart['sourceImageId'] = $sourceImageId;
        }
        $this->content[] = $imagePart;
        return $this;
    }

    /**
     * Add a resource content item
     */
    public function addResourceContent(string $uri, string $mimeType = 'text/plain', ?string $text = null): self
    {
        $resource = [
            'type' => 'resource',
            'resource' => [
                'uri' => $uri,
                'mimeType' => $mimeType
            ]
        ];
        
        if ($text !== null) {
            $resource['resource']['text'] = $text;
        }
        
        $this->content[] = $resource;
        return $this;
    }
}
