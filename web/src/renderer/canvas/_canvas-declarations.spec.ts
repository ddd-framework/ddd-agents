import { describe, expect, test } from "vitest";
import { getDeclaredCanvasIds, isCanvasId, packageCanvasBehaviours, type PackageCanvasId } from "./canvas-behaviours";
import { canvasFeatureFlags } from "./canvas-feature-flags";
import { canvasBodies } from "./canvasBodies";
import { conversationWidgetRegistry, packageConversationWidgets } from "../conversationWidgetRegistry";

/**
 * The three declarations a canvas of the package is made of must agree: a behaviour (`packageCanvasBehaviours`), a body
 * (`canvasBodies.tsx`) and at least one opener among the package's widgets (`opensCanvases`); the package's canvases
 * are ungated. Every opener in the registry — the package's and an app's — names declared canvases only. An app's
 * canvases are checked by the app. Shell-side spec: it may import the registry and the bodies.
 */

const packageCanvasIds = Object.keys(packageCanvasBehaviours) as PackageCanvasId[];

const packageOpenersOf = (canvasId: PackageCanvasId) =>
  packageConversationWidgets.filter((entry) => entry.opensCanvases?.includes(canvasId));

describe("canvas declarations of the package — behaviours, bodies and openers agree", () => {
  test("every package canvas has a body and a package opener; every opener names declared canvases only", () => {
    for (const canvasId of packageCanvasIds) {
      expect(typeof canvasBodies[canvasId], `${canvasId} has a body`).toBe("function");
      expect(packageOpenersOf(canvasId).length, `${canvasId} has an opener`).toBeGreaterThan(0);
    }
    for (const entry of conversationWidgetRegistry.widgets) {
      for (const canvasId of entry.opensCanvases ?? []) {
        expect(isCanvasId(canvasId), `${entry.name} opens a declared canvas (${canvasId})`).toBe(true);
      }
    }
  });

  test("the package's canvases are ungated and listed first among the declared canvases", () => {
    for (const canvasId of packageCanvasIds) {
      expect(canvasFeatureFlags[canvasId], `${canvasId} carries no feature flag`).toBeUndefined();
    }
    expect(getDeclaredCanvasIds().slice(0, packageCanvasIds.length)).toEqual(packageCanvasIds);
  });

  test("the package's canvases are turn canvases in the split; none is pinned or fills the screen", () => {
    for (const canvasId of packageCanvasIds) {
      expect(packageCanvasBehaviours[canvasId].lifetime, `${canvasId} lifetime`).toBe("turn");
      expect(packageCanvasBehaviours[canvasId].layout, `${canvasId} layout`).toBe("split");
    }
  });

  test("no package widget may be mounted by name from a page — the deep-link allow-list is the app's", () => {
    expect(packageConversationWidgets.filter((entry) => entry.deepLinkable).map((entry) => entry.name)).toEqual([]);
  });
});
