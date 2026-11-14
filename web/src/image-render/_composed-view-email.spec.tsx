// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../renderer/widget-hydration";
import { AgentsWidgetHydrationStateContainer } from "../renderer/widget-hydration/widget-hydration-state-store";
import { registerComposedViewDataSourceFetcher } from "../renderer/widget-tree/composed-view-data-sources";
import type { ComposedNodeSchema } from "../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../renderer/widget-tree/composed-view-tree";
import parityFixture from "../renderer/widget-tree/composed-view-parity.json";
import { ComposedViewRenderer } from "../kit/ComposedViewRenderer";
import {
  buildComposedViewComponentRegistry,
  ComposedViewComponentsContext,
} from "../kit/composed-view-components";
import type { ComposedViewComponentProps } from "../kit/composed-view-component-types";
import { readComposedViewDataTimes } from "./composed-view-data-times";
import { formatDataTime } from "./ComposedViewEmailDataTimes/ComposedViewEmailDataTimes";
import { isGoogleMapCapture } from "./widget-image-google-map";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../provider/environment";
import { followWidgetHydrationReadiness } from "./WidgetImageRender";
import { createWidgetImageReadiness } from "./widget-image-readiness";
import { publishWidgetImageReadiness } from "./widget-image-readiness-signal";
import type { WidgetImageRenderBootstrap } from "./widget-image-render.types";

/**
 * A `ComposedView` in the report e-mail capture: the caption line that states when each data
 * path was read (turn-time `$from` snapshot vs mail-time `$source` read), the capture readiness that waits for the
 * tree's `$source` hydration, and the Google-map rule that opens the Maps SDK for a tree holding a map.
 */

const stubSchema = parityFixture.stubSchema as unknown as ComposedNodeSchema;
const StubText = ({ props }: ComposedViewComponentProps) => (
  <span>{props.text === undefined ? "∅" : String(props.text)}</span>
);
const StubStack = ({ slots }: ComposedViewComponentProps) => <div>{slots.children}</div>;
const stubComponents = buildComposedViewComponentRegistry([
  { nodeType: "StubText", component: StubText },
  { nodeType: "StubStack", component: StubStack },
]);

const TURN_TIME = "2026-10-24T07:30:00.000Z";
const fromSnapshot = (resolvedAt?: string) => ({
  $from: { toolCall: "call_1", path: "$.rows", state: "resolved", value: [{ position: 3 }], resolvedAt },
});

const buildStore = () => configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });

const renderEmailTree = (widgetFields: Record<string, unknown>, isEmailRender = true) => {
  const tree = readComposedViewTree(widgetFields, stubSchema);
  const store = buildStore();
  render(
    <Provider store={store}>
      <ComposedViewComponentsContext.Provider value={stubComponents}>
        <ComposedViewRenderer
          tree={tree}
          interactive={false}
          isEmailRender={isEmailRender}
          agentSpaceKey={7}
          onReport={() => {}}
        />
      </ComposedViewComponentsContext.Provider>
    </Provider>,
  );
  return store;
};

afterEach(() => {
  vi.unstubAllGlobals();
  publishWidgetImageReadiness(undefined);
});

describe("readComposedViewDataTimes — which data paths carry a time", () => {
  test("a $from snapshot is turn time with the newest resolvedAt; a $source and an embedded widget are mail time", () => {
    const tree = readComposedViewTree(
      {
        data: { calls: fromSnapshot("2026-10-24T07:00:00Z"), rows: { $source: { name: "rankings", params: {} } } },
        view: { type: "StubText", text: fromSnapshot(TURN_TIME) },
      },
      stubSchema,
    );
    expect(readComposedViewDataTimes(tree)).toEqual({
      hasTurnTimeSnapshot: true,
      turnTimeResolvedAt: TURN_TIME,
      hasMailTimeRead: true,
    });
  });

  test("inline data carries no time; a snapshot without resolvedAt names no clock time", () => {
    const inlineTree = readComposedViewTree(
      { data: { rows: [{ v: 1 }] }, view: { type: "StubText", text: "x" } },
      stubSchema,
    );
    expect(readComposedViewDataTimes(inlineTree)).toEqual({
      hasTurnTimeSnapshot: false,
      turnTimeResolvedAt: undefined,
      hasMailTimeRead: false,
    });
    const untimedTree = readComposedViewTree(
      { data: { calls: fromSnapshot() }, view: { type: "StubText", text: "x" } },
      stubSchema,
    );
    expect(readComposedViewDataTimes(untimedTree)).toMatchObject({
      hasTurnTimeSnapshot: true,
      turnTimeResolvedAt: undefined,
    });
  });
});

describe("ComposedView e-mail caption — the time of each data path, one line", () => {
  test("a mail that mixes a turn-time snapshot and a mail-time read states both, joined by a middle dot", async () => {
    registerComposedViewDataSourceFetcher(
      { name: "rankings", fetchResponse: async () => ({ rankings: [{ position: 4 }] }) },
      stubSchema,
    );
    renderEmailTree({
      data: { calls: fromSnapshot(TURN_TIME), rows: { $source: { name: "rankings", params: { days: 30 } } } },
      view: { type: "StubText", text: "Rankings" },
    });
    const caption = await screen.findByTestId("composed-view-email-data-times");
    const turnTime = formatDataTime(new Date(TURN_TIME), "en-GB", undefined);
    expect(caption.textContent).toContain(`Conversation data as of ${turnTime}`);
    expect(caption.textContent).toMatch(/ · Live data as of /);
  });

  test("a snapshot without resolvedAt names the report time in words", () => {
    renderEmailTree({ data: { calls: fromSnapshot() }, view: { type: "StubText", text: "x" } });
    expect(screen.getByTestId("composed-view-email-data-times").textContent).toBe(
      "Conversation data as of the time of the report",
    );
  });

  test("inline data only, and every non-e-mail render, show no caption", () => {
    renderEmailTree({ data: { rows: [{ v: 1 }] }, view: { type: "StubText", text: "inline" } });
    expect(screen.queryByTestId("composed-view-email-data-times")).toBeNull();
    renderEmailTree({ data: { calls: fromSnapshot(TURN_TIME) }, view: { type: "StubText", text: "chat" } }, false);
    expect(screen.queryByTestId("composed-view-email-data-times")).toBeNull();
  });
});

describe("the capture readiness waits for the tree's $source hydration", () => {
  const bootstrap: WidgetImageRenderBootstrap = {
    snapshotId: "snapshot",
    attemptId: "attempt",
    inputSha256: "hash",
    renderVersion: "build",
    userId: 1,
    siteId: 7,
    expiresAt: Math.floor(Date.now() / 1000) + 120,
    authToken: "token",
    input: { mode: "live", widgetName: "ComposedView", props: {}, hydration: {}, settings: {}, locale: "en-GB" },
  };
  let environmentBefore: AgentsEnvironment;
  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
  });
  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  test("the hydration of a $source binding holds the readiness pending until its rows arrive", async () => {
    let deliverRows: (rows: unknown) => void = () => {};
    registerComposedViewDataSourceFetcher(
      {
        name: "rankings",
        fetchResponse: () =>
          new Promise((resolve) => {
            deliverRows = resolve;
          }),
      },
      stubSchema,
    );
    const readiness = createWidgetImageReadiness(bootstrap);
    const setHydrationPending = vi.spyOn(readiness, "setHydrationPending");
    const tree = readComposedViewTree(
      { data: { rows: { $source: { name: "rankings", params: { days: 7 } } } }, view: { type: "StubText", text: "x" } },
      stubSchema,
    );
    // The capture follows the store the tree's hydration dispatches into: the environment's state store.
    const store = new AgentsWidgetHydrationStateContainer();
    setAgentsEnvironment({ stateStore: store });
    followWidgetHydrationReadiness(store, readiness);
    render(
      <ComposedViewComponentsContext.Provider value={stubComponents}>
        <ComposedViewRenderer tree={tree} interactive={false} isEmailRender agentSpaceKey={7} onReport={() => {}} />
      </ComposedViewComponentsContext.Provider>,
    );
    await waitFor(() => expect(setHydrationPending).toHaveBeenLastCalledWith(true));
    deliverRows({ rankings: [{ position: 2 }] });
    await waitFor(() => expect(setHydrationPending).toHaveBeenLastCalledWith(false));
    expect(window.__AGENTS_WIDGET_IMAGE_V1__?.state).not.toBe("failed");
  });
});

describe("isGoogleMapCapture — the Maps SDK only for a capture that draws a map", () => {
  const payload = (view: unknown) => ({ payload: JSON.stringify({ view }) });
  // The app's map widgets are declared on the environment; this spec declares one stub map widget of its own.
  let environmentBefore: AgentsEnvironment;
  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    setAgentsEnvironment({ ...environmentBefore, googleMapWidgetNames: ["ExampleMap"] });
  });
  afterEach(() => {
    setAgentsEnvironment(environmentBefore);
  });
  test.each([
    ["ExampleMap", {}, true],
    ["ExampleList", {}, false],
    ["ComposedView", payload({ type: "Stack", children: [{ type: "Map", points: [] }] }), true],
    [
      "ComposedView",
      payload({ type: "Stack", children: [{ type: "Widget", widget: "ExampleMapDirective" }] }),
      true,
    ],
    ["ComposedView", payload({ type: "Widget", widget: "CustomVisualization", fields: {} }), false],
    [
      "ComposedView",
      { payload: JSON.stringify({ data: { rows: [{ type: "Map" }] }, view: { type: "Table" } }) },
      false,
    ],
    ["ComposedView", { payload: '{"view":' }, false],
    ["QuickReplies", payload({ type: "Map" }), false],
  ])("%s %j → %s", (widgetName, props, expected) => {
    expect(isGoogleMapCapture(widgetName, props as Record<string, unknown>)).toBe(expected);
  });
});
