import { describe, expect, test } from "vitest";
import { CanvasBehaviour, packageCanvasBehaviours, type PackageCanvasId } from "./canvas-behaviours";
import {
  CanvasOpenRequest,
  CanvasOrigin,
  CanvasState,
  canvasInstanceIdForMessage,
  canvasInstanceIdForMessagePayload,
  LastClosedCanvas,
  messageAnchor,
} from "./canvas-state";
import { CanvasOpenDecision, CanvasOpenIgnoreReason, decideCanvasOpen } from "./decide-canvas-open";

/**
 * THE OPENING RULE, exhaustively: every situation × every origin, the precedence of the Ignore reasons, and the
 * narrow-viewport rule for every canvas of the package. `_canvas-state.spec.ts` tells the story of the rule; this spec
 * is the matrix that pins it, so a change to one rung shows up as the exact cell that moved. The rule takes a behaviour,
 * not an id: `exampleEditor` stands for an app's canvas that declares the overlay.
 */

const ORIGINS: readonly CanvasOrigin[] = ["deliveredInMessage", "cardClick", "openedByPage", "restoredAfterReload"];

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

const openFrom = (r: CanvasOpenRequest, mountGeneration = 1): CanvasState => ({
  canvasId: r.canvasId,
  instanceId: r.instanceId,
  anchor: r.anchor,
  origin: "deliveredInMessage",
  mountGeneration,
});

const closedFrom = (r: CanvasOpenRequest, closedByCustomer: boolean): LastClosedCanvas => ({
  canvasId: r.canvasId,
  instanceId: r.instanceId,
  anchor: r.anchor,
  mountGeneration: 1,
  closedByCustomer,
});

const other = request({
  instanceId: canvasInstanceIdForMessage("conversationSearchResults", 11),
  anchor: messageAnchor(11),
});

interface Situation {
  readonly name: string;
  readonly behaviour: CanvasBehaviour | undefined;
  readonly open: CanvasState | null;
  readonly lastClosed: LastClosedCanvas | null;
  readonly requestOverrides: Partial<CanvasOpenRequest>;
  /** What a LIVE DELIVERY gets in this situation. Every other origin gets Accept unless `alsoForOtherOrigins`. */
  readonly delivery: CanvasOpenDecision;
  readonly alsoForOtherOrigins?: boolean;
}

const accept: CanvasOpenDecision = { kind: "accept" };
const ignore = (reason: CanvasOpenIgnoreReason): CanvasOpenDecision => ({ kind: "ignore", reason });
const list = packageCanvasBehaviours.conversationSearchResults;
/** An app canvas whose widget renders nothing inline, so on a phone the canvas is its only surface. */
const overlayEditor: CanvasBehaviour = { lifetime: "turn", layout: "split", narrowViewport: "overlay", fullBleed: false };

const SITUATIONS: readonly Situation[] = [
  {
    name: "clean: nothing open, nothing closed",
    behaviour: list,
    open: null,
    lastClosed: null,
    requestOverrides: {},
    delivery: accept,
  },
  {
    name: "unknown canvas (no behaviour declared)",
    behaviour: undefined,
    open: null,
    lastClosed: null,
    requestOverrides: {},
    delivery: ignore("unknownCanvas"),
    alsoForOtherOrigins: true,
  },
  {
    name: "host without a canvas surface",
    behaviour: list,
    open: null,
    lastClosed: null,
    requestOverrides: { hostHasCanvasSurface: false },
    delivery: ignore("noSurface"),
    alsoForOtherOrigins: true,
  },
  {
    name: "replay: the message was not just delivered",
    behaviour: list,
    open: null,
    lastClosed: null,
    requestOverrides: { justDelivered: false },
    delivery: ignore("replay"),
  },
  {
    name: "the same identity is open",
    behaviour: list,
    open: openFrom(request()),
    lastClosed: null,
    requestOverrides: {},
    delivery: ignore("alreadyOpen"),
  },
  {
    name: "another identity is open",
    behaviour: list,
    open: openFrom(other),
    lastClosed: null,
    requestOverrides: {},
    delivery: accept,
  },
  {
    name: "the customer closed this identity",
    behaviour: list,
    open: null,
    lastClosed: closedFrom(request(), true),
    requestOverrides: {},
    delivery: ignore("customerClosedIt"),
  },
  {
    name: "the shell closed this identity (compact, side panel, narrow crossing)",
    behaviour: list,
    open: null,
    lastClosed: closedFrom(request(), false),
    requestOverrides: {},
    delivery: accept,
  },
  {
    name: "the customer closed ANOTHER identity",
    behaviour: list,
    open: null,
    lastClosed: closedFrom(other, true),
    requestOverrides: {},
    delivery: accept,
  },
  {
    name: "narrow viewport, behaviour narrowViewport: none",
    behaviour: list,
    open: null,
    lastClosed: null,
    requestOverrides: { viewportViable: false },
    delivery: ignore("noTakeoverOnNarrowViewport"),
  },
  {
    name: "narrow viewport, behaviour narrowViewport: overlay",
    behaviour: overlayEditor,
    open: null,
    lastClosed: null,
    requestOverrides: { viewportViable: false, canvasId: "exampleEditor" },
    delivery: accept,
  },
];

describe("decideCanvasOpen — the matrix: every situation × every origin", () => {
  for (const situation of SITUATIONS) {
    for (const origin of ORIGINS) {
      const expected = origin === "deliveredInMessage" || situation.alsoForOtherOrigins ? situation.delivery : accept;
      test(`${situation.name} · origin ${origin} → ${expected.kind === "accept" ? "Accept" : `Ignore(${expected.reason})`}`, () => {
        const decision = decideCanvasOpen(
          situation.behaviour,
          situation.open,
          situation.lastClosed,
          request({ ...situation.requestOverrides, origin }),
        );
        expect(decision).toEqual(expected);
      });
    }
  }

  test("only a live delivery is judged: a click, a page hand-over and a restore open even when replayed, open, vetoed and narrow at once", () => {
    for (const origin of ORIGINS.filter((candidate) => candidate !== "deliveredInMessage")) {
      expect(
        decideCanvasOpen(
          list,
          openFrom(request()),
          closedFrom(request(), true),
          request({ origin, justDelivered: false, viewportViable: false }),
        ),
      ).toEqual(accept);
    }
  });
});

describe("decideCanvasOpen — precedence of the Ignore reasons (the first rung that fails names the reason)", () => {
  test("unknownCanvas beats noSurface", () => {
    expect(decideCanvasOpen(undefined, null, null, request({ hostHasCanvasSurface: false }))).toEqual(
      ignore("unknownCanvas"),
    );
  });
  test("noSurface beats replay", () => {
    expect(decideCanvasOpen(list, null, null, request({ hostHasCanvasSurface: false, justDelivered: false }))).toEqual(
      ignore("noSurface"),
    );
  });
  test("replay beats alreadyOpen — a replayed message never touches the open canvas, not even to say 'already open'", () => {
    expect(decideCanvasOpen(list, openFrom(request()), null, request({ justDelivered: false }))).toEqual(
      ignore("replay"),
    );
  });
  test("alreadyOpen beats customerClosedIt", () => {
    expect(decideCanvasOpen(list, openFrom(request()), closedFrom(request(), true), request())).toEqual(
      ignore("alreadyOpen"),
    );
  });
  test("customerClosedIt beats noTakeoverOnNarrowViewport", () => {
    expect(decideCanvasOpen(list, null, closedFrom(request(), true), request({ viewportViable: false }))).toEqual(
      ignore("customerClosedIt"),
    );
  });
});

describe("decideCanvasOpen — the narrow-viewport rule per canvas of the package", () => {
  // Every canvas of the package has an inline card in the transcript, so none declares the overlay. An app's canvases
  // are checked by the app.
  const packageCanvasIds = Object.keys(packageCanvasBehaviours) as PackageCanvasId[];

  test("every package canvas keeps its card on a phone (reason noTakeoverOnNarrowViewport) and opens on a wide viewport", () => {
    for (const canvasId of packageCanvasIds) {
      const narrow = request({ canvasId, instanceId: `${canvasId}:message:10`, viewportViable: false });
      expect(decideCanvasOpen(packageCanvasBehaviours[canvasId], null, null, narrow)).toEqual(
        ignore("noTakeoverOnNarrowViewport"),
      );
      expect(
        decideCanvasOpen(packageCanvasBehaviours[canvasId], null, null, { ...narrow, viewportViable: true }),
      ).toEqual(accept);
    }
  });
});

describe("decideCanvasOpen — identity", () => {
  test("two identical widgets in one message share one identity: the second live delivery is alreadyOpen", () => {
    const payload = '{"title":"A"}';
    const first = request({ instanceId: canvasInstanceIdForMessagePayload("conversationSearchResults", 10, payload) });
    expect(decideCanvasOpen(list, openFrom(first), null, first)).toEqual(ignore("alreadyOpen"));
  });

  test("the same canvas with another payload in the same message is another identity: it is accepted (and replaces)", () => {
    const first = request({ instanceId: canvasInstanceIdForMessagePayload("conversationSearchResults", 10, '{"title":"A"}') });
    const second = request({ instanceId: canvasInstanceIdForMessagePayload("conversationSearchResults", 10, '{"title":"B"}') });
    expect(decideCanvasOpen(list, openFrom(first), null, second)).toEqual(accept);
  });

  test("the rule is pure: it mutates neither the open canvas, the last closed one nor the request", () => {
    const open = Object.freeze(openFrom(request()));
    const closed = Object.freeze(closedFrom(other, true));
    const frozenRequest = Object.freeze(request());
    expect(() => decideCanvasOpen(list, open, closed, frozenRequest)).not.toThrow();
  });
});
