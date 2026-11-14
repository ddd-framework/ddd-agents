import { afterEach, beforeEach, describe, expect, test } from "vitest";
import { publishWidgetImageReadiness } from "../image-render/widget-image-readiness-signal";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../provider/environment";
import { createWidgetPreviewReadiness } from "./widget-preview-readiness";
import { publishWidgetPreviewSignal, WIDGET_PREVIEW_SIGNAL_GLOBAL_NAME } from "./widget-preview-signal";

/**
 * The preview's readiness signal is published under the package's name `__AGENTS_WIDGET_PREVIEW_V1__` and under every
 * name the app declares (`AgentsEnvironment.sandboxLegacyAliases.previewReadinessGlobalNames`); the image signal the
 * preview also publishes follows the image names. Without a declaration only the package's names exist.
 */

const DECLARED_PREVIEW_NAME = "__APP_WIDGET_PREVIEW_V1__";
const DECLARED_IMAGE_NAME = "__APP_WIDGET_IMAGE_V1__";

const windowSignals = window as unknown as Record<string, unknown>;

/** The readiness signals (preview and image) present on `window`, by name. */
const readSignalNamesOnWindow = (): string[] =>
  Object.keys(window)
    .filter((globalName) => /^__[A-Z_]+_WIDGET_(PREVIEW|IMAGE)_V1__$/.test(globalName) && windowSignals[globalName])
    .sort();

let appEnvironment: AgentsEnvironment;

const declareAliases = () =>
  setAgentsEnvironment({
    ...appEnvironment,
    sandboxLegacyAliases: {
      previewReadinessGlobalNames: [DECLARED_PREVIEW_NAME],
      imageReadinessGlobalNames: [DECLARED_IMAGE_NAME],
    },
  });

beforeEach(() => {
  appEnvironment = getAgentsEnvironment();
  setAgentsEnvironment({ ...appEnvironment, sandboxLegacyAliases: undefined });
  for (const globalName of Object.keys(window).filter((name) => /_WIDGET_(PREVIEW|IMAGE)_V1__$/.test(name))) {
    delete windowSignals[globalName];
  }
});

afterEach(() => {
  declareAliases();
  publishWidgetPreviewSignal(undefined);
  publishWidgetImageReadiness(undefined);
  setAgentsEnvironment(appEnvironment);
});

describe("the preview readiness signal", () => {
  test("is published under the package's name and under each declared name", () => {
    declareAliases();
    createWidgetPreviewReadiness("data").fail("UNKNOWN_FIXTURE");
    expect(window.__AGENTS_WIDGET_PREVIEW_V1__).toMatchObject({ state: "failed", error_code: "UNKNOWN_FIXTURE" });
    expect(windowSignals[DECLARED_PREVIEW_NAME]).toBe(window.__AGENTS_WIDGET_PREVIEW_V1__);
    expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({ state: "failed", error_code: "UNKNOWN_FIXTURE" });
    expect(windowSignals[DECLARED_IMAGE_NAME]).toBe(window.__AGENTS_WIDGET_IMAGE_V1__);
    expect(readSignalNamesOnWindow()).toEqual(
      [WIDGET_PREVIEW_SIGNAL_GLOBAL_NAME, "__AGENTS_WIDGET_IMAGE_V1__", DECLARED_PREVIEW_NAME, DECLARED_IMAGE_NAME].sort(),
    );
  });

  test("is published only under the package's names without a declaration", () => {
    createWidgetPreviewReadiness("data").fail("UNKNOWN_FIXTURE");
    expect(window.__AGENTS_WIDGET_PREVIEW_V1__).toMatchObject({ state: "failed" });
    expect(readSignalNamesOnWindow()).toEqual(["__AGENTS_WIDGET_IMAGE_V1__", WIDGET_PREVIEW_SIGNAL_GLOBAL_NAME].sort());
  });
});
