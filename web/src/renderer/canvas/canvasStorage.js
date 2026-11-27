import { isCanvasId } from "./canvas-behaviours";
const CANVAS_STORAGE_KEY_PREFIX = "agent.canvas.";
const canvasStorageKey = (conversationId) => `${CANVAS_STORAGE_KEY_PREFIX}${conversationId}`;
const isPositiveInteger = (value) => typeof value === "number" && Number.isInteger(value) && value > 0;
const parseAnchor = (value) => {
    if (typeof value !== "object" || value === null)
        return null;
    const candidate = value;
    if (candidate.kind === "message" && isPositiveInteger(candidate.messageId)) {
        return { kind: "message", messageId: candidate.messageId };
    }
    if (candidate.kind === "page" && isPositiveInteger(candidate.agentSpaceKey)) {
        return { kind: "page", agentSpaceKey: candidate.agentSpaceKey };
    }
    return null;
};
/** Validate an untrusted value into a pointer, or null. Exported for the spec and for the shell's restore path. */
export const parseStoredCanvasPointer = (value) => {
    if (typeof value !== "object" || value === null)
        return null;
    const candidate = value;
    const anchor = parseAnchor(candidate.anchor);
    if (!isCanvasId(candidate.canvasId) || anchor === null)
        return null;
    if (typeof candidate.instanceId !== "string" || candidate.instanceId.length === 0)
        return null;
    return {
        canvasId: candidate.canvasId,
        anchor,
        instanceId: candidate.instanceId,
        ...(isPositiveInteger(candidate.createdEntityId) ? { createdEntityId: candidate.createdEntityId } : {}),
    };
};
/** Remember the canvas open in this conversation. No-op without a conversation id (a cold chat has none yet). */
export const storeOpenCanvas = (conversationId, canvas) => {
    if (conversationId === undefined)
        return;
    const pointer = {
        canvasId: canvas.canvasId,
        anchor: canvas.anchor,
        instanceId: canvas.instanceId,
        ...(canvas.createdEntityId !== undefined ? { createdEntityId: canvas.createdEntityId } : {}),
    };
    try {
        window.sessionStorage.setItem(canvasStorageKey(conversationId), JSON.stringify(pointer));
    }
    catch {
        // Storage unavailable — the canvas then simply does not survive the reload.
    }
};
/** The canvas that was open in this conversation, validated, or null (nothing stored, stale, or storage unusable). */
export const readStoredCanvas = (conversationId) => {
    if (conversationId === undefined)
        return null;
    try {
        const raw = window.sessionStorage.getItem(canvasStorageKey(conversationId));
        if (raw === null)
            return null;
        return parseStoredCanvasPointer(JSON.parse(raw));
    }
    catch {
        return null;
    }
};
/** Forget the canvas of this conversation — it closed, or its anchor is no longer among the loaded messages. */
export const clearStoredCanvas = (conversationId) => {
    if (conversationId === undefined)
        return;
    try {
        window.sessionStorage.removeItem(canvasStorageKey(conversationId));
    }
    catch {
        // Storage unavailable — nothing was stored either.
    }
};
