import type { CanvasId } from "./canvas-behaviours";

/**
 * The canvas STATE — plain, readonly, serialisable data (it lives in the Redux store, where RTK + Immer forbid class
 * instances). The value-object behaviour — invariants, equality, the transitions — lives in the pure functions of this
 * module and of `decide-canvas-open.ts` / `canvas-reducers.ts`.
 *
 * The canvas is a POINTER to its anchor: the data a canvas body needs already lives in the transcript — the
 * widget's fence in the anchor message is persisted, loaded and rendered. The shell finds the anchor message in the
 * loaded list, parses the fence with the widget's own parser and renders the body from the result. Nothing about the
 * content is stored a second time. A page-opened canvas (an app's editor opened from its page) has no message; its
 * anchor is the agent space and the body hydrates from it.
 */
export type CanvasAnchor =
  | { readonly kind: "message"; readonly messageId: number }
  | { readonly kind: "page"; readonly agentSpaceKey: number };

/**
 * How a canvas came to be open — the one question the opening rule asks (`decide-canvas-open.ts`):
 *  - `deliveredInMessage`  a canvas widget in a message delivered live (`justDelivered`); replay never opens one
 *  - `cardClick`           the customer clicked a card in the transcript, or the canvas toggle button on the divider
 *  - `openedByPage`        a page handed the tool over (`openAgentWithWidget` / `?agentWidget=`)
 *  - `restoredAfterReload` the pointer stored for this conversation, on the mount of the conversation
 * A restored or page-opened canvas never runs a body's creation side effects (a post editor with no created entity
 * renders its empty state instead of creating a draft).
 */
export type CanvasOrigin = "deliveredInMessage" | "cardClick" | "openedByPage" | "restoredAfterReload";

/** The identity of a canvas: WHICH canvas, and which instance of it. Two canvases with the same identity are one. */
export interface CanvasIdentity {
  readonly canvasId: CanvasId;
  /**
   * Distinguishes instances of one canvas: lists per message (+ occurrence in the message), a work surface per
   * agent space, an entity editor or detail per entity. Built with the helpers below.
   * Never includes the conversation id — a cold chat receives its id after the first send.
   */
  readonly instanceId: string;
}

export interface CanvasState extends CanvasIdentity {
  readonly anchor: CanvasAnchor;
  readonly origin: CanvasOrigin;
  /**
   * Bumped on every accepted open (also of the same identity from a card click) so the shell keys the body on it: a
   * list re-opened with other filters remounts and re-runs its mount effects. Monotonic within the conversation.
   */
  readonly mountGeneration: number;
  /**
   * The one datum not in a message: the id of the entity a body CREATED after opening (the post editor's draft).
   * Written back by `setCanvasCreatedEntityId`; a restored editor with no id renders its empty state.
   */
  readonly createdEntityId?: number;
}

/**
 * The canvas that was open last and is not open now — what the canvas toggle button on the divider, the narrow-
 * viewport re-open control and the compact → expand path re-open. `closedByCustomer` is the customer's veto: a live
 * delivery of the SAME identity is ignored until a send, a card click or a page hand-over clears it.
 */
export interface LastClosedCanvas extends CanvasIdentity {
  readonly anchor: CanvasAnchor;
  readonly mountGeneration: number;
  readonly closedByCustomer: boolean;
  readonly createdEntityId?: number;
}

/** What an opener hands to `openCanvas` — everything the opening rule needs to decide, nothing more. */
export interface CanvasOpenRequest extends CanvasIdentity {
  readonly anchor: CanvasAnchor;
  readonly origin: CanvasOrigin;
  /** The host message was delivered live right now (the widget's `justDelivered`). Irrelevant for other origins. */
  readonly justDelivered: boolean;
  /** The viewport is wide enough for the side split (`useCanvasViable`). */
  readonly viewportViable: boolean;
  /** The host renders the split at all (`disableSplitView` hosts have no canvas surface). */
  readonly hostHasCanvasSurface: boolean;
  /** Carried by a restored pointer so a re-opened editor keeps its created entity. */
  readonly createdEntityId?: number;
}

export const sameCanvasIdentity = (a: CanvasIdentity, b: CanvasIdentity): boolean =>
  a.canvasId === b.canvasId && a.instanceId === b.instanceId;

export const sameCanvasAnchor = (a: CanvasAnchor, b: CanvasAnchor): boolean =>
  a.kind === "message" && b.kind === "message"
    ? a.messageId === b.messageId
    : a.kind === "page" && b.kind === "page"
      ? a.agentSpaceKey === b.agentSpaceKey
      : false;

export const messageAnchor = (messageId: number): CanvasAnchor => ({ kind: "message", messageId });

export const pageAnchor = (agentSpaceKey: number): CanvasAnchor => ({ kind: "page", agentSpaceKey });

/** A list-like canvas: one instance per message, and per occurrence when a message carries the widget twice. */
export const canvasInstanceIdForMessage = (canvasId: CanvasId, messageId: number, occurrence = 0): string =>
  occurrence > 0 ? `${canvasId}:message:${messageId}:${occurrence}` : `${canvasId}:message:${messageId}`;

/** A work surface unique per agent space (an app's editor): a later card for the same agent space never replaces it. */
export const canvasInstanceIdForAgentSpace = (canvasId: CanvasId, agentSpaceKey: number): string =>
  `${canvasId}:agentSpace:${agentSpaceKey}`;

/**
 * A short, stable, dependency-free hash of a string (djb2, base 36) — identifies a widget's payload inside a message
 * without storing the payload. Not cryptographic; two payloads colliding would only merge two identical-looking
 * canvases of one message.
 */
export const shortStringHash = (value: string): string => {
  let hash = 5381;
  for (let index = 0; index < value.length; index += 1) {
    hash = ((hash << 5) + hash + value.charCodeAt(index)) | 0;
  }
  return (hash >>> 0).toString(36);
};

/**
 * A canvas opened by a widget WITH a payload (a list with filters, a search result): one instance per message and
 * payload, so two different widgets of one kind in one message are two canvases while two identical ones are one.
 * The body finds its fence again through the hash (`findWidgetFenceByPayloadHash`).
 */
export const canvasInstanceIdForMessagePayload = (canvasId: CanvasId, messageId: number, payload: string): string =>
  `${canvasId}:message:${messageId}:${shortStringHash(payload)}`;

/** The payload hash a message+payload instance id carries, or undefined for another shape of instance id. */
export const payloadHashOfInstanceId = (instanceId: string): string | undefined => {
  const match = /^[a-zA-Z]+:message:\d+:([0-9a-z]+)$/.exec(instanceId);
  return match ? match[1] : undefined;
};

/** The same work surface when no agent space key is known at open time (the body resolves the active agent space itself). */
export const canvasInstanceIdForActiveAgentSpace = (canvasId: CanvasId): string => `${canvasId}:agentSpace:active`;

/** An editor / detail unique per entity (post, event, schedule). */
export const canvasInstanceIdForEntity = (canvasId: CanvasId, entityId: number): string =>
  `${canvasId}:entity:${entityId}`;

/** The entity id an entity instance id carries, or undefined for another shape of instance id. */
export const entityIdOfInstanceId = (instanceId: string): number | undefined => {
  const match = /^[a-zA-Z]+:entity:(\d+)$/.exec(instanceId);
  return match ? Number(match[1]) : undefined;
};

/** True when the anchor message of a canvas is among the loaded messages (a restored pointer must point somewhere). */
export const isCanvasAnchorLoaded = (
  anchor: CanvasAnchor,
  loadedMessageIds: ReadonlyArray<number | undefined | null>,
): boolean => anchor.kind === "page" || loadedMessageIds.includes(anchor.messageId);
