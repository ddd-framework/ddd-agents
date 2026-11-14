// @vitest-environment jsdom
import React from "react";
import { describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import type { ComposedViewComponentProps } from "../../composed-view-component-types";
import { registerConversationWidgets } from "../../../renderer/conversationWidgetRegistry";
import { composedViewComponent } from "./WidgetNode.composed";

vi.mock("../../../renderer/_access/ConversationWidgetFeatureGate", () => ({
  ConversationWidgetFeatureGate: ({ featureFlag }: { featureFlag: string }) => (
    <div data-testid="feature-gate">{featureFlag}</div>
  ),
}));

/**
 * An embedded domain widget keeps the registry's feature gate, so a tree cannot
 * show a gated widget to an account without the flag. `ExampleGatedWidget` stands for an app widget with a flag.
 */
registerConversationWidgets([
  { name: "ExampleGatedWidget", component: () => <div>gated content</div>, featureFlag: "EXAMPLE.FEATURE" },
]);

const renderWidgetNode = (widgetName: string) => {
  const WidgetNode = composedViewComponent.component;
  const nodeProps = {
    node: { type: "Widget", id: null, props: {}, nodesBySlotName: {} },
    props: { widget: widgetName, fields: {} },
    isPending: false,
    slots: {},
    sizeClass: "regular",
    renderSlotForItem: () => [],
    runtime: {
      state: {},
      setStateValue: () => {},
      runEvent: () => {},
      interactive: false,
      isEmailRender: false,
      reportNodeStatus: () => {},
    },
  } as unknown as ComposedViewComponentProps;
  return render(<WidgetNode {...nodeProps} />);
};

describe("the Widget node", () => {
  test("a flag-gated widget renders through its feature gate", () => {
    renderWidgetNode("ExampleGatedWidget");
    expect(screen.getByTestId("feature-gate").textContent).toBe("EXAMPLE.FEATURE");
  });

  test("an unknown widget renders nothing", () => {
    const { container } = renderWidgetNode("NoSuchWidget");
    expect(container.innerHTML).toBe("");
  });
});
