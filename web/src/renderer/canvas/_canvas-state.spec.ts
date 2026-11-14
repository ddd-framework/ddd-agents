// @vitest-environment jsdom
import { afterAll, afterEach, beforeAll, describe, expect, test, vi } from "vitest";
import { getAgentsEnvironment, setAgentsEnvironment, type AgentsEnvironment } from "../../provider/environment";
import {
  type CanvasBehaviour,
  canvasBehaviourOf,
  getDeclaredCanvasIds,
  isCanvasId,
  packageCanvasBehaviours,
} from "./canvas-behaviours";
import {
  applyClearCanvas,
  applyCloseCanvas,
  applyOpenCanvas,
  applyResetCanvasForNewTurn,
  applySetCanvasCreatedEntityId,
  CanvasSliceState,
} from "./canvas-reducers";
import {
  CanvasOpenRequest,
  canvasInstanceIdForEntity,
  canvasInstanceIdForAgentSpace,
  canvasInstanceIdForMessage,
  canvasInstanceIdForMessagePayload,
  isCanvasAnchorLoaded,
  messageAnchor,
  pageAnchor,
  payloadHashOfInstanceId,
  sameCanvasAnchor,
  sameCanvasIdentity,
  shortStringHash,
} from "./canvas-state";
import { clearStoredCanvas, parseStoredCanvasPointer, readStoredCanvas, storeOpenCanvas } from "./canvasStorage";
import { decideCanvasOpen } from "./decide-canvas-open";

/**
 * The canvas lifetime state machine — every transition, every Ignore
 * reason of the opening rule, and the storage pointer. Pure objects, no React: this is the model the shell renders.
 *
 * The package's own canvases are turn canvases with an inline card; the two example canvases stand for an app's
 * canvases (declared on the agents environment, as an app declares them): a pinned work surface that fills the screen,
 * and an editor whose widget renders nothing inline (its canvas overlays on a phone).
 */

const EXAMPLE_CANVAS_BEHAVIOURS: Readonly<Record<string, CanvasBehaviour>> = {
  exampleWorkSurface: { lifetime: "pinned", layout: "canvasFillsScreen", narrowViewport: "overlay", fullBleed: true },
  exampleEditor: { lifetime: "turn", layout: "split", narrowViewport: "overlay", fullBleed: false },
};

let environmentBeforeThisSpec: AgentsEnvironment = {};
beforeAll(() => {
  environmentBeforeThisSpec = getAgentsEnvironment();
  setAgentsEnvironment({ ...environmentBeforeThisSpec, furtherCanvasBehaviours: EXAMPLE_CANVAS_BEHAVIOURS });
});
afterAll(() => {
  setAgentsEnvironment(environmentBeforeThisSpec);
});

const listBehaviour = packageCanvasBehaviours.conversationSearchResults;
const workSurfaceBehaviour = EXAMPLE_CANVAS_BEHAVIOURS.exampleWorkSurface;
const editorBehaviour = EXAMPLE_CANVAS_BEHAVIOURS.exampleEditor;

const emptyState = (): CanvasSliceState => ({ canvas: null, lastClosedCanvas: null, canvasMountCount: 0 });
/** Both slots empty — the mount count is allowed to have advanced. */
const expectEmptySlots = (state: CanvasSliceState) => {
  expect(state.canvas).toBeNull();
  expect(state.lastClosedCanvas).toBeNull();
};

const request = (overrides: Partial<CanvasOpenRequest> = {}): CanvasOpenRequest => ({
  canvasId: "conversationSearchResults",
  instanceId: canvasInstanceIdForMessage("conversationSearchResults", 10),
  anchor: messageAnchor(10),
  origin: "deliveredInMessage",
  justDelivered: true,
  viewportViable: true,
  hostHasCanvasSurface: true,
  ...overrides,
});

const workSurfaceRequest = (overrides: Partial<CanvasOpenRequest> = {}): CanvasOpenRequest =>
  request({
    canvasId: "exampleWorkSurface",
    instanceId: canvasInstanceIdForAgentSpace("exampleWorkSurface", 7),
    anchor: messageAnchor(20),
    ...overrides,
  });

describe("canvas behaviours", () => {
  test("declare every canvas exactly once, with a lifetime and a narrow-viewport rule", () => {
    expect(Object.keys(packageCanvasBehaviours)).toEqual([
      "conversationSearchResults",
      "webSearchResults",
      "entityDetail",
      "mapFullScreen",
    ]);
    const declaredCanvasIds = getDeclaredCanvasIds();
    expect(declaredCanvasIds).toEqual([...new Set(declaredCanvasIds)]);
    expect(declaredCanvasIds).toEqual(expect.arrayContaining(Object.keys(EXAMPLE_CANVAS_BEHAVIOURS)));
    for (const canvasId of declaredCanvasIds) {
      const behaviour = canvasBehaviourOf(canvasId);
      expect(["turn", "pinned"]).toContain(behaviour?.lifetime);
      expect(["split", "canvasFillsScreen"]).toContain(behaviour?.layout);
      expect(["none", "overlay"]).toContain(behaviour?.narrowViewport);
    }
  });

  test("an app's canvases are read from the environment; an app cannot replace a package canvas's behaviour", () => {
    expect(canvasBehaviourOf("exampleWorkSurface")).toEqual(workSurfaceBehaviour);
    expect(canvasBehaviourOf("exampleEditor")).toEqual(editorBehaviour);
    expect(canvasBehaviourOf("undeclaredCanvas")).toBeUndefined();
    const environmentWithExamples = getAgentsEnvironment();
    setAgentsEnvironment({
      ...environmentWithExamples,
      furtherCanvasBehaviours: { ...EXAMPLE_CANVAS_BEHAVIOURS, conversationSearchResults: workSurfaceBehaviour },
    });
    try {
      expect(canvasBehaviourOf("conversationSearchResults")).toEqual(listBehaviour);
      expect(getDeclaredCanvasIds().filter((canvasId) => canvasId === "conversationSearchResults")).toHaveLength(1);
    } finally {
      setAgentsEnvironment(environmentWithExamples);
    }
  });

  test("isCanvasId guards untrusted input", () => {
    expect(isCanvasId("conversationSearchResults")).toBe(true);
    expect(isCanvasId("exampleEditor")).toBe(true);
    expect(isCanvasId("ConversationSearchResults")).toBe(false);
    expect(isCanvasId("toString")).toBe(false);
    expect(isCanvasId(42)).toBe(false);
  });
});

describe("canvas identity", () => {
  test("identity = canvas id + instance id; the conversation id plays no part", () => {
    expect(sameCanvasIdentity(request(), request())).toBe(true);
    expect(
      sameCanvasIdentity(request(), request({ instanceId: canvasInstanceIdForMessage("conversationSearchResults", 11) })),
    ).toBe(false);
    expect(sameCanvasIdentity(request(), request({ canvasId: "webSearchResults" }))).toBe(false);
  });

  test("instance ids: lists per message and occurrence, a work surface per agent space, entities per id", () => {
    expect(canvasInstanceIdForMessage("conversationSearchResults", 10)).toBe("conversationSearchResults:message:10");
    expect(canvasInstanceIdForMessage("conversationSearchResults", 10, 1)).toBe(
      "conversationSearchResults:message:10:1",
    );
    expect(canvasInstanceIdForAgentSpace("exampleWorkSurface", 7)).toBe("exampleWorkSurface:agentSpace:7");
    expect(canvasInstanceIdForEntity("exampleEditor", 99)).toBe("exampleEditor:entity:99");
  });

  test("a payload instance id carries a short stable hash of the payload, readable back", () => {
    const payload = JSON.stringify({ query: "local SEO", groups: [1, 2, 3] });
    expect(shortStringHash(payload)).toBe(shortStringHash(payload));
    expect(shortStringHash(payload)).not.toBe(shortStringHash(payload + " "));
    const instanceId = canvasInstanceIdForMessagePayload("conversationSearchResults", 10, payload);
    expect(instanceId).toBe(`conversationSearchResults:message:10:${shortStringHash(payload)}`);
    expect(payloadHashOfInstanceId(instanceId)).toBe(shortStringHash(payload));
    expect(payloadHashOfInstanceId(canvasInstanceIdForMessage("conversationSearchResults", 10))).toBeUndefined();
    expect(payloadHashOfInstanceId("exampleWorkSurface:agentSpace:7")).toBeUndefined();
  });

  test("anchors compare by kind and id; a page anchor is always 'loaded'", () => {
    expect(sameCanvasAnchor(messageAnchor(1), messageAnchor(1))).toBe(true);
    expect(sameCanvasAnchor(messageAnchor(1), messageAnchor(2))).toBe(false);
    expect(sameCanvasAnchor(messageAnchor(1), pageAnchor(1))).toBe(false);
    expect(isCanvasAnchorLoaded(messageAnchor(5), [3, 4, 5])).toBe(true);
    expect(isCanvasAnchorLoaded(messageAnchor(5), [3, 4])).toBe(false);
    expect(isCanvasAnchorLoaded(pageAnchor(7), [])).toBe(true);
  });
});

describe("decideCanvasOpen — the opening rule", () => {
  test("a live delivery on a viable viewport is accepted", () => {
    expect(decideCanvasOpen(listBehaviour, null, null, request())).toEqual({ kind: "accept" });
  });

  test("an unknown canvas and a host without a surface are ignored before anything else", () => {
    expect(decideCanvasOpen(undefined, null, null, request())).toEqual({ kind: "ignore", reason: "unknownCanvas" });
    expect(
      decideCanvasOpen(listBehaviour, null, null, request({ hostHasCanvasSurface: false })),
    ).toEqual({ kind: "ignore", reason: "noSurface" });
  });

  test("REPLAY is rejected before any other rule — a replayed message never touches the open canvas", () => {
    const openWorkSurface = { ...workSurfaceRequest(), mountGeneration: 1 };
    // Same identity as the open canvas, but not just delivered: replay wins over alreadyOpen.
    expect(
      decideCanvasOpen(workSurfaceBehaviour, openWorkSurface, null, workSurfaceRequest({ justDelivered: false })),
    ).toEqual({ kind: "ignore", reason: "replay" });
    expect(decideCanvasOpen(listBehaviour, null, null, request({ justDelivered: false }))).toEqual({
      kind: "ignore",
      reason: "replay",
    });
  });

  test("the agent re-emitting the OPEN canvas's identity keeps the live one (no remount of the customer's work surface)", () => {
    const openWorkSurface = { ...workSurfaceRequest(), mountGeneration: 1 };
    expect(
      decideCanvasOpen(workSurfaceBehaviour, openWorkSurface, null, workSurfaceRequest({ anchor: messageAnchor(21) })),
    ).toEqual({ kind: "ignore", reason: "alreadyOpen" });
  });

  test("the customer's close vetoes a live re-delivery of the SAME identity, not of another canvas", () => {
    const lastClosed = { ...request(), mountGeneration: 1, closedByCustomer: true };
    expect(decideCanvasOpen(listBehaviour, null, lastClosed, request())).toEqual({
      kind: "ignore",
      reason: "customerClosedIt",
    });
    expect(
      decideCanvasOpen(
        listBehaviour,
        null,
        lastClosed,
        request({ instanceId: canvasInstanceIdForMessage("conversationSearchResults", 11), anchor: messageAnchor(11) }),
      ),
    ).toEqual({ kind: "accept" });
    // A shell-driven close (compact, side panel) is no veto.
    expect(
      decideCanvasOpen(listBehaviour, null, { ...lastClosed, closedByCustomer: false }, request()),
    ).toEqual({ kind: "accept" });
  });

  test("on a narrow viewport a live delivery opens only a canvas that declares the overlay", () => {
    expect(decideCanvasOpen(listBehaviour, null, null, request({ viewportViable: false }))).toEqual({
      kind: "ignore",
      reason: "noTakeoverOnNarrowViewport",
    });
    // An editor whose widget renders nothing inline declares the overlay: on a phone the canvas is its only surface.
    expect(
      decideCanvasOpen(
        editorBehaviour,
        null,
        null,
        request({ canvasId: "exampleEditor", viewportViable: false }),
      ),
    ).toEqual({ kind: "accept" });
  });

  test("a card click, a page hand-over and a restore always open — the customer, the page or the pointer decided", () => {
    const lastClosed = { ...request(), mountGeneration: 1, closedByCustomer: true };
    const openWorkSurface = { ...workSurfaceRequest(), mountGeneration: 1 };
    for (const origin of ["cardClick", "openedByPage", "restoredAfterReload"] as const) {
      expect(
        decideCanvasOpen(listBehaviour, null, lastClosed, request({ origin, justDelivered: false })),
      ).toEqual({ kind: "accept" });
      expect(
        decideCanvasOpen(listBehaviour, null, null, request({ origin, viewportViable: false })),
      ).toEqual({ kind: "accept" });
      expect(
        decideCanvasOpen(
          workSurfaceBehaviour,
          openWorkSurface,
          null,
          workSurfaceRequest({ origin, justDelivered: false }),
        ),
      ).toEqual({ kind: "accept" });
    }
  });
});

describe("canvas reducers — the lifetime state machine", () => {
  test("Accept opens the canvas with generation 1 and no lastClosedCanvas", () => {
    const state = emptyState();
    expect(applyOpenCanvas(state, request())).toEqual({ kind: "accept" });
    expect(state.canvas).toMatchObject({
      canvasId: "conversationSearchResults",
      anchor: messageAnchor(10),
      origin: "deliveredInMessage",
      mountGeneration: 1,
    });
    expect(state.lastClosedCanvas).toBeNull();
  });

  test("Ignore mutates nothing", () => {
    const state = emptyState();
    expect(applyOpenCanvas(state, request({ justDelivered: false }))).toEqual({ kind: "ignore", reason: "replay" });
    expect(state).toEqual(emptyState());
    expect(state.canvasMountCount).toBe(0);
  });

  test("another canvas accepted replaces the open one, which becomes lastClosedCanvas (not closed by the customer)", () => {
    const state = emptyState();
    applyOpenCanvas(state, workSurfaceRequest());
    applyOpenCanvas(
      state,
      request({ anchor: messageAnchor(30), instanceId: canvasInstanceIdForMessage("conversationSearchResults", 30) }),
    );
    expect(state.canvas?.canvasId).toBe("conversationSearchResults");
    expect(state.canvas?.mountGeneration).toBe(2);
    expect(state.lastClosedCanvas).toMatchObject({
      canvasId: "exampleWorkSurface",
      instanceId: canvasInstanceIdForAgentSpace("exampleWorkSurface", 7),
      closedByCustomer: false,
      mountGeneration: 1,
    });
  });

  test("two Accepts in one commit: the generation advances twice and lastClosedCanvas is the first one", () => {
    const state = emptyState();
    applyOpenCanvas(state, request());
    applyOpenCanvas(state, request({ canvasId: "webSearchResults", instanceId: canvasInstanceIdForMessage("webSearchResults", 10) }));
    expect(state.canvas?.mountGeneration).toBe(2);
    expect(state.lastClosedCanvas?.canvasId).toBe("conversationSearchResults");
  });

  test("two canvas widgets in one message: the last Accept wins, the first becomes lastClosedCanvas", () => {
    const state = emptyState();
    applyOpenCanvas(state, request({ instanceId: canvasInstanceIdForMessage("conversationSearchResults", 10, 0) }));
    applyOpenCanvas(state, request({ instanceId: canvasInstanceIdForMessage("conversationSearchResults", 10, 1) }));
    expect(state.canvas?.instanceId).toBe("conversationSearchResults:message:10:1");
    expect(state.lastClosedCanvas?.instanceId).toBe("conversationSearchResults:message:10");
  });

  test("a card click on the SAME identity re-opens it with a new generation (a list with other filters remounts)", () => {
    const state = emptyState();
    applyOpenCanvas(state, request());
    applyOpenCanvas(state, request({ origin: "cardClick", justDelivered: false }));
    expect(state.canvas?.mountGeneration).toBe(2);
    expect(state.lastClosedCanvas).toBeNull();
  });

  test("close by the customer → lastClosedCanvas carries the veto; a live re-delivery of that identity is ignored, a click re-opens it and clears the veto", () => {
    const state = emptyState();
    applyOpenCanvas(state, request());
    applyCloseCanvas(state, true);
    expect(state.canvas).toBeNull();
    expect(state.lastClosedCanvas).toMatchObject({
      canvasId: "conversationSearchResults",
      closedByCustomer: true,
      mountGeneration: 1,
    });

    expect(applyOpenCanvas(state, request())).toEqual({ kind: "ignore", reason: "customerClosedIt" });
    expect(state.canvas).toBeNull();

    expect(applyOpenCanvas(state, request({ origin: "cardClick" }))).toEqual({ kind: "accept" });
    expect(state.canvas?.mountGeneration).toBe(2);
    expect(state.lastClosedCanvas).toBeNull();
  });

  test("a shell close (compact, side panel, narrow crossing) keeps the canvas re-openable without a veto", () => {
    const state = emptyState();
    applyOpenCanvas(state, request());
    applyCloseCanvas(state, false);
    expect(state.lastClosedCanvas?.closedByCustomer).toBe(false);
    expect(applyOpenCanvas(state, request({ origin: "cardClick" }))).toEqual({ kind: "accept" });
  });

  test("closing nothing changes nothing", () => {
    const state = emptyState();
    applyCloseCanvas(state, true);
    expect(state).toEqual(emptyState());
  });

  test("the mount generation never repeats: clear + re-open of the same identity yields a higher generation", () => {
    const state = emptyState();
    applyOpenCanvas(state, workSurfaceRequest());
    expect(state.canvas?.mountGeneration).toBe(1);
    applyClearCanvas(state);
    applyOpenCanvas(state, workSurfaceRequest());
    expect(state.canvas?.mountGeneration).toBe(2);
    // A late write from the body of generation 1 is dropped.
    expect(
      applySetCanvasCreatedEntityId(state, {
        canvasId: "exampleWorkSurface",
        instanceId: canvasInstanceIdForAgentSpace("exampleWorkSurface", 7),
        mountGeneration: 1,
        createdEntityId: 9,
      }),
    ).toBe(false);
    expect(state.canvas?.createdEntityId).toBeUndefined();
  });

  test("SEND closes a turn canvas for good — it leaves lastClosedCanvas too", () => {
    const state = emptyState();
    applyOpenCanvas(state, request());
    applyResetCanvasForNewTurn(state);
    expectEmptySlots(state);

    // Closed before the send: dropped as well.
    applyOpenCanvas(state, request({ origin: "cardClick" }));
    applyCloseCanvas(state, true);
    applyResetCanvasForNewTurn(state);
    expectEmptySlots(state);
  });

  test("SEND keeps a pinned canvas open; a closed pinned one stays re-openable and loses the customer's veto", () => {
    const state = emptyState();
    applyOpenCanvas(state, workSurfaceRequest());
    applyResetCanvasForNewTurn(state);
    expect(state.canvas?.canvasId).toBe("exampleWorkSurface");

    applyCloseCanvas(state, true);
    applyResetCanvasForNewTurn(state);
    expect(state.canvas).toBeNull();
    expect(state.lastClosedCanvas).toMatchObject({ canvasId: "exampleWorkSurface", closedByCustomer: false });
    // The veto is gone, so the agent re-emitting the work surface re-opens it.
    expect(applyOpenCanvas(state, workSurfaceRequest({ anchor: messageAnchor(40) }))).toEqual({ kind: "accept" });
  });

  test("a pinned work surface survives a widget-bearing reply: opening an inline-only turn never touches it", () => {
    const state = emptyState();
    applyOpenCanvas(state, workSurfaceRequest());
    // Nothing is dispatched for an inline widget message; the work surface is still there.
    expect(state.canvas?.canvasId).toBe("exampleWorkSurface");
    // A turn canvas replaces it, and it stays re-openable.
    applyOpenCanvas(
      state,
      request({ anchor: messageAnchor(50), instanceId: canvasInstanceIdForMessage("conversationSearchResults", 50) }),
    );
    expect(state.lastClosedCanvas?.canvasId).toBe("exampleWorkSurface");
    applyResetCanvasForNewTurn(state);
    expect(state.canvas).toBeNull();
    expect(state.lastClosedCanvas?.canvasId).toBe("exampleWorkSurface");
  });

  test("clear (conversation switch, closeAgent) forgets both", () => {
    const state = emptyState();
    applyOpenCanvas(state, workSurfaceRequest());
    applyCloseCanvas(state, false);
    applyClearCanvas(state);
    expectEmptySlots(state);
  });

  test("a restore re-opens the pointer as origin restoredAfterReload with its created entity", () => {
    const state = emptyState();
    applyOpenCanvas(
      state,
      request({
        canvasId: "exampleEditor",
        instanceId: canvasInstanceIdForEntity("exampleEditor", 5),
        origin: "restoredAfterReload",
        justDelivered: false,
        createdEntityId: 5,
      }),
    );
    expect(state.canvas).toMatchObject({ origin: "restoredAfterReload", createdEntityId: 5 });
  });

  test("the created entity is written only onto the canvas it was created for, in the generation it was mounted with", () => {
    const state = emptyState();
    applyOpenCanvas(
      state,
      request({ canvasId: "exampleEditor", instanceId: canvasInstanceIdForMessage("exampleEditor", 10) }),
    );
    const stale = {
      canvasId: "exampleEditor" as const,
      instanceId: canvasInstanceIdForMessage("exampleEditor", 10),
      mountGeneration: 0,
      createdEntityId: 77,
    };
    expect(applySetCanvasCreatedEntityId(state, stale)).toBe(false);
    expect(state.canvas?.createdEntityId).toBeUndefined();

    expect(applySetCanvasCreatedEntityId(state, { ...stale, mountGeneration: 1 })).toBe(true);
    expect(state.canvas?.createdEntityId).toBe(77);

    // Re-opening the same identity (a click after a close) carries the created entity along.
    applyCloseCanvas(state, true);
    applyOpenCanvas(
      state,
      request({
        canvasId: "exampleEditor",
        instanceId: canvasInstanceIdForMessage("exampleEditor", 10),
        origin: "cardClick",
      }),
    );
    expect(state.canvas?.createdEntityId).toBe(77);
  });
});

describe("canvasStorage — the pointer", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    window.sessionStorage.clear();
  });

  test("stores, reads back and clears the pointer per conversation — never content", () => {
    const state = emptyState();
    applyOpenCanvas(state, request({ createdEntityId: 3 }));
    storeOpenCanvas(42, state.canvas!);
    expect(readStoredCanvas(42)).toEqual({
      canvasId: "conversationSearchResults",
      anchor: messageAnchor(10),
      instanceId: "conversationSearchResults:message:10",
      createdEntityId: 3,
    });
    expect(readStoredCanvas(43)).toBeNull();
    expect(JSON.parse(window.sessionStorage.getItem("agent.canvas.42") as string)).not.toHaveProperty("origin");
    clearStoredCanvas(42);
    expect(readStoredCanvas(42)).toBeNull();
  });

  test("a cold chat (no conversation id) stores and reads nothing", () => {
    storeOpenCanvas(undefined, { ...request(), mountGeneration: 1 });
    expect(readStoredCanvas(undefined)).toBeNull();
    expect(() => clearStoredCanvas(undefined)).not.toThrow();
  });

  test("a stale or mangled entry reads as nothing", () => {
    expect(parseStoredCanvasPointer({ canvasId: "gone", anchor: messageAnchor(1), instanceId: "x" })).toBeNull();
    expect(
      parseStoredCanvasPointer({ canvasId: "conversationSearchResults", anchor: { kind: "message" }, instanceId: "x" }),
    ).toBeNull();
    expect(parseStoredCanvasPointer({ canvasId: "conversationSearchResults", anchor: messageAnchor(1), instanceId: "" })).toBeNull();
    expect(parseStoredCanvasPointer("ExampleWorkSurface")).toBeNull();
    expect(
      parseStoredCanvasPointer({
        canvasId: "exampleWorkSurface",
        anchor: pageAnchor(7),
        instanceId: "exampleWorkSurface:agentSpace:7",
        createdEntityId: "no",
      }),
    ).toEqual({
      canvasId: "exampleWorkSurface",
      anchor: pageAnchor(7),
      instanceId: "exampleWorkSurface:agentSpace:7",
    });
    window.sessionStorage.setItem("agent.canvas.42", "{not json");
    expect(readStoredCanvas(42)).toBeNull();
  });

  test("unusable storage degrades to 'nothing stored' instead of throwing", () => {
    vi.spyOn(window.sessionStorage.__proto__, "setItem").mockImplementation(() => {
      throw new Error("blocked");
    });
    vi.spyOn(window.sessionStorage.__proto__, "getItem").mockImplementation(() => {
      throw new Error("blocked");
    });
    vi.spyOn(window.sessionStorage.__proto__, "removeItem").mockImplementation(() => {
      throw new Error("blocked");
    });
    expect(() => storeOpenCanvas(42, { ...request(), mountGeneration: 1 })).not.toThrow();
    expect(readStoredCanvas(42)).toBeNull();
    expect(() => clearStoredCanvas(42)).not.toThrow();
  });
});
