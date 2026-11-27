// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import React from "react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../provider/environment";
import { installWidgetPreviewAppHooks } from "./widget-preview-app-hooks";
import { WidgetPreview } from "./WidgetPreviewHost";
import { findWidgetPreviewFixture, findWidgetPreviewFixtures } from "./widget-preview-fixtures";
import { createWidgetPreviewReadiness } from "./widget-preview-readiness";

/**
 * The connection widgets have preview fixtures, and the two that need an app hook (`ContinueInPersonalSpace`,
 * `ExtendConnectionPermission`) render in the preview: the preview's stand-in logs the click instead of an app opening
 * a space or its settings. A hook the app set stays the app's.
 */
describe("the connection widgets in the preview", () => {
  let environmentBefore: AgentsEnvironment;

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    setAgentsEnvironment({});
  });
  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  it.each(["ContinueInPersonalSpace", "ExtendConnectionPermission", "MailDraftPreview"])("%s has preview fixtures", (wireName) => {
    expect(findWidgetPreviewFixtures(wireName).length).toBeGreaterThan(0);
  });

  it("renders ContinueInPersonalSpace and logs the click as an app action", () => {
    const fixture = findWidgetPreviewFixture("ContinueInPersonalSpace", "default");
    expect(fixture).toBeDefined();
    if (!fixture) return;
    render(
      <WidgetPreview
        bootstrap={{ widgetName: "ContinueInPersonalSpace", fixtureName: "default", mode: "interactive" }}
        fixture={fixture}
        readiness={createWidgetPreviewReadiness("data")}
        hasHydrationError={() => false}
      />,
    );
    act(() => {
      fireEvent.click(screen.getByTestId("continue-in-personal-space-widget-button"));
    });
    expect(screen.getByTestId("widget-preview-composer-log").textContent).toContain(
      "app: start in personal space: Was steht heute in meinem Postfach?",
    );
  });

  it("keeps a hook the app set", () => {
    const openedTargets: unknown[] = [];
    const appOpenConnectionPermissions: NonNullable<AgentsEnvironment["openConnectionPermissions"]> = (target) => {
      openedTargets.push(target);
    };
    setAgentsEnvironment({ openConnectionPermissions: appOpenConnectionPermissions });
    installWidgetPreviewAppHooks(() => {});
    expect(getAgentsEnvironment().openConnectionPermissions).toBe(appOpenConnectionPermissions);
    expect(getAgentsEnvironment().startConversationInPersonalSpace).toBeDefined();
  });
});
