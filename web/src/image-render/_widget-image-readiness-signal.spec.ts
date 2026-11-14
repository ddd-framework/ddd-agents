import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../provider/environment";
import { createWidgetImageReadiness } from "./widget-image-readiness";
import {
  publishWidgetImageReadiness,
  readWidgetImageReadiness,
  WIDGET_IMAGE_READINESS_GLOBAL_NAME,
} from "./widget-image-readiness-signal";
import type { WidgetImageReadiness, WidgetImageRenderBootstrap } from "./widget-image-render.types";

/**
 * The image capture's readiness signal is published under the package's name `__AGENTS_WIDGET_IMAGE_V1__` and under
 * every name the app declares (`AgentsEnvironment.sandboxLegacyAliases.imageReadinessGlobalNames`) — one value, written
 * to all names; without a declaration only under the package's name. A `failed` written under a declared name (by the
 * app's own capture code) is seen by the readiness.
 */

const DECLARED_NAME = "__APP_WIDGET_IMAGE_V1__";

const bootstrap: WidgetImageRenderBootstrap = {
  snapshotId: "snapshot",
  attemptId: "attempt",
  inputSha256: "hash",
  renderVersion: "build",
  userId: 1,
  siteId: 2,
  expiresAt: Math.floor(Date.now() / 1000) + 120,
  authToken: null,
  input: { widgetName: "ToolTrace", props: {}, hydration: {}, settings: {}, locale: "en-GB" },
};

type WindowSignals = Record<string, WidgetImageReadiness | undefined>;
const windowSignals = window as unknown as WindowSignals;

const readImageSignalNamesOnWindow = (): string[] =>
  Object.keys(window).filter((globalName) => /^__[A-Z_]+_WIDGET_IMAGE_V1__$/.test(globalName) && windowSignals[globalName]);

let appEnvironment: AgentsEnvironment;

beforeEach(() => {
  appEnvironment = getAgentsEnvironment();
  setAgentsEnvironment({ ...appEnvironment, sandboxLegacyAliases: undefined });
  for (const globalName of Object.keys(window).filter((name) => name.endsWith("_WIDGET_IMAGE_V1__"))) {
    delete windowSignals[globalName];
  }
  vi.stubGlobal("requestAnimationFrame", (callback: FrameRequestCallback) => {
    callback(0);
    return 1;
  });
});

afterEach(() => {
  setAgentsEnvironment({ ...appEnvironment, sandboxLegacyAliases: { imageReadinessGlobalNames: [DECLARED_NAME] } });
  publishWidgetImageReadiness(undefined);
  setAgentsEnvironment(appEnvironment);
  vi.unstubAllGlobals();
});

describe("the image readiness signal", () => {
  test("is published under the package's name and under each declared name, as one value", () => {
    setAgentsEnvironment({ ...appEnvironment, sandboxLegacyAliases: { imageReadinessGlobalNames: [DECLARED_NAME] } });
    createWidgetImageReadiness(bootstrap).fail("TOKEN_EXPIRED");
    expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({ state: "failed", error_code: "TOKEN_EXPIRED" });
    expect(windowSignals[DECLARED_NAME]).toBe(window.__AGENTS_WIDGET_IMAGE_V1__);
    expect(readImageSignalNamesOnWindow().sort()).toEqual([DECLARED_NAME, WIDGET_IMAGE_READINESS_GLOBAL_NAME].sort());
  });

  test("is published only under the package's name without a declaration", () => {
    createWidgetImageReadiness(bootstrap);
    expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({ state: "loading", snapshot_id: "snapshot" });
    expect(readImageSignalNamesOnWindow()).toEqual([WIDGET_IMAGE_READINESS_GLOBAL_NAME]);
  });

  test("a failed state the app wrote under its declared name is seen: the readiness stays failed", () => {
    setAgentsEnvironment({ ...appEnvironment, sandboxLegacyAliases: { imageReadinessGlobalNames: [DECLARED_NAME] } });
    windowSignals[DECLARED_NAME] = { version: 1, state: "failed", error_code: "RENDER_FAILED" };
    const readiness = createWidgetImageReadiness(bootstrap);
    expect(readiness.isFailed()).toBe(true);
    expect(readWidgetImageReadiness()).toMatchObject({ state: "failed", error_code: "RENDER_FAILED" });
    readiness.fail("TOKEN_EXPIRED");
    expect(windowSignals[DECLARED_NAME]).toMatchObject({ error_code: "RENDER_FAILED" });
  });
});
