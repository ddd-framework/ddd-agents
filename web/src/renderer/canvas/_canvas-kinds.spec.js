import { afterAll, afterEach, beforeAll, describe, expect, test } from "vitest";
import { getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { canvasBehaviourOf, canvasKindOpenerWidgetNamesOf, getDeclaredCanvasIds, isCanvasId, packageCanvasBehaviours, } from "./canvas-behaviours";
import { canvasFeatureFlagOf } from "./canvas-feature-flags";
import { registerCanvasKind, unregisterCanvasKind } from "./canvas-kinds";
import { canvasInstanceIdForReference, isCanvasReference, referenceOfCanvasInstanceId } from "./canvas-state";
import { canvasBodies } from "./canvasBodies";
import { parseStoredCanvasPointer } from "./canvasStorage";
import { decideCanvasOpen } from "./decide-canvas-open";
/**
 * A canvas kind a further package registers (`registerCanvasKind`): one declaration gives the behaviour, the body, the
 * opener widgets and the gate, next to the app's two declarations (`furtherCanvasBehaviours` + `registerCanvasBodies`),
 * which keep working unchanged.
 */
const VIEWER_BEHAVIOUR = { lifetime: "pinned", layout: "split", narrowViewport: "overlay", fullBleed: false };
const APP_BEHAVIOUR = { lifetime: "turn", layout: "split", narrowViewport: "none", fullBleed: false };
const ViewerBody = () => null;
const OtherBody = () => null;
let environmentBeforeThisSpec = {};
beforeAll(() => {
    environmentBeforeThisSpec = getAgentsEnvironment();
});
afterEach(() => {
    unregisterCanvasKind("exampleFileViewer");
    setAgentsEnvironment(environmentBeforeThisSpec);
});
afterAll(() => {
    setAgentsEnvironment(environmentBeforeThisSpec);
});
describe("registerCanvasKind", () => {
    test("declares the behaviour, the body, the opener widgets and the gate in one call", () => {
        expect(isCanvasId("exampleFileViewer")).toBe(false);
        registerCanvasKind({
            canvasId: "exampleFileViewer",
            behaviour: VIEWER_BEHAVIOUR,
            body: ViewerBody,
            openedByWidgets: ["DriveFiles"],
            featureFlag: "exampleFlag",
        });
        expect(canvasBehaviourOf("exampleFileViewer")).toEqual(VIEWER_BEHAVIOUR);
        expect(isCanvasId("exampleFileViewer")).toBe(true);
        expect(canvasBodies.exampleFileViewer).toBe(ViewerBody);
        expect(canvasKindOpenerWidgetNamesOf("exampleFileViewer")).toEqual(["DriveFiles"]);
        expect(canvasFeatureFlagOf("exampleFileViewer")).toBe("exampleFlag");
        // listed after the package's own canvases
        const declared = getDeclaredCanvasIds();
        expect(declared.slice(0, Object.keys(packageCanvasBehaviours).length)).toEqual(Object.keys(packageCanvasBehaviours));
        expect(declared).toContain("exampleFileViewer");
    });
    test("a second registration replaces the first; unregistering takes everything off", () => {
        registerCanvasKind({ canvasId: "exampleFileViewer", behaviour: VIEWER_BEHAVIOUR, body: ViewerBody, featureFlag: "exampleFlag" });
        registerCanvasKind({ canvasId: "exampleFileViewer", behaviour: APP_BEHAVIOUR, body: OtherBody });
        expect(canvasBehaviourOf("exampleFileViewer")).toEqual(APP_BEHAVIOUR);
        expect(canvasBodies.exampleFileViewer).toBe(OtherBody);
        expect(canvasFeatureFlagOf("exampleFileViewer")).toBeUndefined();
        expect(canvasKindOpenerWidgetNamesOf("exampleFileViewer")).toEqual([]);
        unregisterCanvasKind("exampleFileViewer");
        expect(isCanvasId("exampleFileViewer")).toBe(false);
        expect(canvasBodies.exampleFileViewer).toBeUndefined();
        expect(getDeclaredCanvasIds()).not.toContain("exampleFileViewer");
    });
    test("a kind cannot take a package canvas id; the package's behaviour and body stay", () => {
        const bodyBefore = canvasBodies.webSearchResults;
        expect(() => registerCanvasKind({ canvasId: "webSearchResults", behaviour: VIEWER_BEHAVIOUR, body: ViewerBody })).toThrow(/package's own canvases/);
        expect(canvasBehaviourOf("webSearchResults")).toEqual(packageCanvasBehaviours.webSearchResults);
        expect(canvasBodies.webSearchResults).toBe(bodyBefore);
    });
    test("the app's behaviour for an id wins over a registered kind's; the id is declared once", () => {
        registerCanvasKind({ canvasId: "exampleFileViewer", behaviour: VIEWER_BEHAVIOUR, body: ViewerBody });
        setAgentsEnvironment({ ...environmentBeforeThisSpec, furtherCanvasBehaviours: { exampleFileViewer: APP_BEHAVIOUR } });
        expect(canvasBehaviourOf("exampleFileViewer")).toEqual(APP_BEHAVIOUR);
        expect(getDeclaredCanvasIds().filter((canvasId) => canvasId === "exampleFileViewer")).toHaveLength(1);
    });
    test("a registered kind opens from a click and its stored pointer parses; an unregistered one is unknown", () => {
        const request = {
            canvasId: "exampleFileViewer",
            instanceId: canvasInstanceIdForReference("exampleFileViewer", "drive/812"),
            anchor: { kind: "message", messageId: 7 },
            origin: "widgetClick",
            justDelivered: false,
            viewportViable: true,
            hostHasCanvasSurface: true,
        };
        expect(decideCanvasOpen(canvasBehaviourOf(request.canvasId), null, null, request)).toEqual({ kind: "ignore", reason: "unknownCanvas" });
        expect(parseStoredCanvasPointer(request)).toBeNull();
        registerCanvasKind({ canvasId: "exampleFileViewer", behaviour: VIEWER_BEHAVIOUR, body: ViewerBody });
        expect(decideCanvasOpen(canvasBehaviourOf(request.canvasId), null, null, request)).toEqual({ kind: "accept" });
        expect(parseStoredCanvasPointer(request)).toEqual({
            canvasId: "exampleFileViewer",
            anchor: { kind: "message", messageId: 7 },
            instanceId: "exampleFileViewer:reference:drive/812",
        });
    });
});
describe("reference instance ids", () => {
    test("carry a store reference and give it back", () => {
        const instanceId = canvasInstanceIdForReference("exampleFileViewer", "drive/812");
        expect(instanceId).toBe("exampleFileViewer:reference:drive/812");
        expect(referenceOfCanvasInstanceId(instanceId)).toBe("drive/812");
        expect(referenceOfCanvasInstanceId(canvasInstanceIdForReference("exampleFileViewer", "workspace/out/deck.v2.pptx"))).toBe("workspace/out/deck.v2.pptx");
    });
    test("refuse everything that is not a reference", () => {
        for (const value of ["", "../etc/passwd", "drive/../812", "https://evil.example/x", "drive/812?x=1", "drive/<script>", "/drive/812", "drive//812", "a b"]) {
            expect(isCanvasReference(value), value).toBe(false);
            expect(() => canvasInstanceIdForReference("exampleFileViewer", value)).toThrow();
        }
        expect(referenceOfCanvasInstanceId("exampleFileViewer:reference:https://evil.example/x")).toBeUndefined();
        expect(referenceOfCanvasInstanceId("exampleFileViewer:entity:812")).toBeUndefined();
        expect(referenceOfCanvasInstanceId("exampleFileViewer:message:7:abc")).toBeUndefined();
    });
});
