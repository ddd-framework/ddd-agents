/**
 * How a canvas came to be open — the one question the opening rule asks (`decide-canvas-open.ts`):
 *  - `deliveredInMessage`  a canvas widget in a message delivered live (`justDelivered`); replay never opens one
 *  - `widgetClick`         the customer clicked a widget in the transcript, or the canvas toggle button on the divider
 *  - `openedByPage`        a page handed the tool over (`openAgentWithWidget` / `?agentWidget=`)
 *  - `restoredAfterReload` the pointer stored for this conversation, on the mount of the conversation
 * A restored or page-opened canvas never runs a body's creation side effects (a post editor with no created entity
 * renders its empty state instead of creating a draft).
 */
/** @deprecated The former name of the `widgetClick` origin; an app's call that still passes it opens like `widgetClick`. */
export const LEGACY_CARD_CLICK_ORIGIN = "cardClick";
export const sameCanvasIdentity = (a, b) => a.canvasId === b.canvasId && a.instanceId === b.instanceId;
export const sameCanvasAnchor = (a, b) => a.kind === "message" && b.kind === "message"
    ? a.messageId === b.messageId
    : a.kind === "page" && b.kind === "page"
        ? a.agentSpaceKey === b.agentSpaceKey
        : false;
export const messageAnchor = (messageId) => ({ kind: "message", messageId });
export const pageAnchor = (agentSpaceKey) => ({ kind: "page", agentSpaceKey });
/** A list-like canvas: one instance per message, and per occurrence when a message carries the widget twice. */
export const canvasInstanceIdForMessage = (canvasId, messageId, occurrence = 0) => occurrence > 0 ? `${canvasId}:message:${messageId}:${occurrence}` : `${canvasId}:message:${messageId}`;
/** A work surface unique per agent space (an app's editor): a later card for the same agent space never replaces it. */
export const canvasInstanceIdForAgentSpace = (canvasId, agentSpaceKey) => `${canvasId}:agentSpace:${agentSpaceKey}`;
/**
 * A short, stable, dependency-free hash of a string (djb2, base 36) — identifies a widget's payload inside a message
 * without storing the payload. Not cryptographic; two payloads colliding would only merge two identical-looking
 * canvases of one message.
 */
export const shortStringHash = (value) => {
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
export const canvasInstanceIdForMessagePayload = (canvasId, messageId, payload) => `${canvasId}:message:${messageId}:${shortStringHash(payload)}`;
/** The payload hash a message+payload instance id carries, or undefined for another shape of instance id. */
export const payloadHashOfInstanceId = (instanceId) => {
    const match = /^[a-zA-Z]+:message:\d+:([0-9a-z]+)$/.exec(instanceId);
    return match ? match[1] : undefined;
};
/** The same work surface when no agent space key is known at open time (the body resolves the active agent space itself). */
export const canvasInstanceIdForActiveAgentSpace = (canvasId) => `${canvasId}:agentSpace:active`;
/** An editor / detail unique per entity (post, event, schedule). */
export const canvasInstanceIdForEntity = (canvasId, entityId) => `${canvasId}:entity:${entityId}`;
/** The entity id an entity instance id carries, or undefined for another shape of instance id. */
export const entityIdOfInstanceId = (instanceId) => {
    const match = /^[a-zA-Z]+:entity:(\d+)$/.exec(instanceId);
    return match ? Number(match[1]) : undefined;
};
/**
 * A reference a store hands out for one of its objects (`drive/812`): one or more segments of letters, digits, `_`,
 * `-` and `.`, joined by `/` — never a path with `..`, a URL or markup.
 */
const CANVAS_REFERENCE_PATTERN = /^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,127}(\/[A-Za-z0-9_-][A-Za-z0-9_.-]{0,127}){0,7}$/;
/** Whether a value has the shape of a store reference an instance id may carry. */
export const isCanvasReference = (value) => CANVAS_REFERENCE_PATTERN.test(value);
/**
 * A viewer / editor unique per object of a store (a drive file, a folder): `<canvasId>:reference:<reference>`. The
 * body reads the reference back (`referenceOfCanvasInstanceId`) and loads the object under the viewer's rights —
 * the id carries no data, only what to ask for.
 *
 * @throws Error for a reference of another shape (`isCanvasReference`)
 */
export const canvasInstanceIdForReference = (canvasId, reference) => {
    if (!isCanvasReference(reference))
        throw new Error("A canvas instance id carries a store reference such as drive/812.");
    return `${canvasId}:reference:${reference}`;
};
/** The reference a reference instance id carries, or undefined for another shape of instance id (also a mangled one). */
export const referenceOfCanvasInstanceId = (instanceId) => {
    const match = /^[a-zA-Z][a-zA-Z0-9]*:reference:(.+)$/.exec(instanceId);
    return match && isCanvasReference(match[1]) ? match[1] : undefined;
};
/** True when the anchor message of a canvas is among the loaded messages (a restored pointer must point somewhere). */
export const isCanvasAnchorLoaded = (anchor, loadedMessageIds) => anchor.kind === "page" || loadedMessageIds.includes(anchor.messageId);
