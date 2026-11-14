import { isCanvasId } from "./canvas-behaviours";
import type { CanvasAnchor, CanvasState } from "./canvas-state";

/**
 * Where the open canvas of a conversation is remembered across a reload: `sessionStorage`, per conversation, holding
 * the POINTER only — which canvas, which anchor, which instance, and the entity a body created. Never
 * content: the anchor message's fence supplies the body's data on restore. Tab-local by design (the canvas is viewing
 * state of this tab); a new tab starts with no canvas.
 *
 * Written on every change of the open canvas for ANY lifetime, cleared when the canvas closes; on read the pointer is
 * validated shape by shape — a stale id or a mangled entry reads as "nothing stored". Every accessor is try/catch-
 * guarded: a private window, blocked site data or a preview context can throw on the accessor itself.
 */
export interface StoredCanvasPointer {
  readonly canvasId: CanvasState["canvasId"];
  readonly anchor: CanvasAnchor;
  readonly instanceId: string;
  readonly createdEntityId?: number;
}

const CANVAS_STORAGE_KEY_PREFIX = "agent.canvas.";

const canvasStorageKey = (conversationId: number): string => `${CANVAS_STORAGE_KEY_PREFIX}${conversationId}`;

const isPositiveInteger = (value: unknown): value is number =>
  typeof value === "number" && Number.isInteger(value) && value > 0;

const parseAnchor = (value: unknown): CanvasAnchor | null => {
  if (typeof value !== "object" || value === null) return null;
  const candidate = value as { kind?: unknown; messageId?: unknown; agentSpaceKey?: unknown };
  if (candidate.kind === "message" && isPositiveInteger(candidate.messageId)) {
    return { kind: "message", messageId: candidate.messageId };
  }
  if (candidate.kind === "page" && isPositiveInteger(candidate.agentSpaceKey)) {
    return { kind: "page", agentSpaceKey: candidate.agentSpaceKey };
  }
  return null;
};

/** Validate an untrusted value into a pointer, or null. Exported for the spec and for the shell's restore path. */
export const parseStoredCanvasPointer = (value: unknown): StoredCanvasPointer | null => {
  if (typeof value !== "object" || value === null) return null;
  const candidate = value as { canvasId?: unknown; anchor?: unknown; instanceId?: unknown; createdEntityId?: unknown };
  const anchor = parseAnchor(candidate.anchor);
  if (!isCanvasId(candidate.canvasId) || anchor === null) return null;
  if (typeof candidate.instanceId !== "string" || candidate.instanceId.length === 0) return null;
  return {
    canvasId: candidate.canvasId,
    anchor,
    instanceId: candidate.instanceId,
    ...(isPositiveInteger(candidate.createdEntityId) ? { createdEntityId: candidate.createdEntityId } : {}),
  };
};

/** Remember the canvas open in this conversation. No-op without a conversation id (a cold chat has none yet). */
export const storeOpenCanvas = (conversationId: number | undefined, canvas: CanvasState): void => {
  if (conversationId === undefined) return;
  const pointer: StoredCanvasPointer = {
    canvasId: canvas.canvasId,
    anchor: canvas.anchor,
    instanceId: canvas.instanceId,
    ...(canvas.createdEntityId !== undefined ? { createdEntityId: canvas.createdEntityId } : {}),
  };
  try {
    window.sessionStorage.setItem(canvasStorageKey(conversationId), JSON.stringify(pointer));
  } catch {
    // Storage unavailable — the canvas then simply does not survive the reload.
  }
};

/** The canvas that was open in this conversation, validated, or null (nothing stored, stale, or storage unusable). */
export const readStoredCanvas = (conversationId: number | undefined): StoredCanvasPointer | null => {
  if (conversationId === undefined) return null;
  try {
    const raw = window.sessionStorage.getItem(canvasStorageKey(conversationId));
    if (raw === null) return null;
    return parseStoredCanvasPointer(JSON.parse(raw));
  } catch {
    return null;
  }
};

/** Forget the canvas of this conversation — it closed, or its anchor is no longer among the loaded messages. */
export const clearStoredCanvas = (conversationId: number | undefined): void => {
  if (conversationId === undefined) return;
  try {
    window.sessionStorage.removeItem(canvasStorageKey(conversationId));
  } catch {
    // Storage unavailable — nothing was stored either.
  }
};
