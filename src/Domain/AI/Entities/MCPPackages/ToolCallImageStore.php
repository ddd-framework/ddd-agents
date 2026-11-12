<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

/**
 * An MCP package whose tools serve DURABLE pictures (a catalog thumbnail, a stored render) and can serve them AGAIN
 * from the bytes-free {@see ToolCallImageReference} the tool call persisted. The loop composes the wire on every model
 * call and re-resolves the images of the tool results inside the wire window through the loaded packages implementing
 * this — so a picture the model is meant to decide with is still there after the 12-step worker chunk yielded, after
 * a turn boundary and after a resumed worker reloaded the conversation. Inline images (a browser viewport) stay
 * single-turn: they name no store.
 *
 * A tool declares the store on the image part it attaches ({@see \DDD\Presentation\Mcp\Dtos\MCPToolResponse::addImageContent()}
 * with `sourceStore` + `sourceImageId`); the executor carries both onto {@see ToolResultImage} and the persisted
 * reference. Resolution must be DETERMINISTIC for an id (the same picture every time — versioned ids when the
 * underlying picture may change) and cheap (a DB/cache read, a bounded composition), because it runs per model call.
 */
interface ToolCallImageStore
{
    /** The store name this package resolves (what its tools write into the image part's `sourceStore`). */
    public static function getToolCallImageStoreName(): string;

    /** The picture behind a persisted reference, or null when it no longer exists (the caller then treats the image as dropped). */
    public function resolveToolCallImage(ToolCallImageReference $toolCallImageReference): ?ToolResultImage;
}
