// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import React from "react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import {
  type AgentsConnectionPermissionTarget,
  type AgentsEnvironment,
  getAgentsEnvironment,
  setAgentsEnvironment,
} from "../../provider/environment";
import { ExtendConnectionPermissionConversationWidget } from "./ExtendConnectionPermissionConversationWidget";
import { parseExtendConnectionPermissionPayload } from "./extend-connection-permission-payload";

/** The button opens the app's connection settings with the needed permission; without the hook nothing renders. */
describe("ExtendConnectionPermission widget", () => {
  let environmentBefore: AgentsEnvironment;
  let openedTargets: AgentsConnectionPermissionTarget[];

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    openedTargets = [];
  });
  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  const payload = JSON.stringify({ reason: "Sending needs a higher level.", connectionId: 3, capability: "MAIL", level: "SEND_WITH_APPROVAL" });

  it("opens the connection's permissions with the capability and level", () => {
    setAgentsEnvironment({ openConnectionPermissions: (target) => openedTargets.push(target) });
    render(<ExtendConnectionPermissionConversationWidget payload={payload} interactive />);
    fireEvent.click(screen.getByTestId("extend-connection-permission-widget-button"));
    expect(openedTargets).toEqual([{ connectionId: 3, capability: "MAIL", level: "SEND_WITH_APPROVAL" }]);
  });

  it("renders nothing when the app cannot open the permissions", () => {
    setAgentsEnvironment({});
    render(<ExtendConnectionPermissionConversationWidget payload={payload} interactive />);
    expect(screen.queryByTestId("extend-connection-permission-widget")).toBeNull();
  });

  it("parses the payload leniently and refuses incomplete blocks", () => {
    expect(parseExtendConnectionPermissionPayload("{")).toBeNull();
    expect(parseExtendConnectionPermissionPayload(JSON.stringify({ connectionId: 0, capability: "MAIL", level: "READ" }))).toBeNull();
    expect(parseExtendConnectionPermissionPayload(JSON.stringify({ connectionId: 3, capability: "", level: "READ" }))).toBeNull();
    expect(parseExtendConnectionPermissionPayload(JSON.stringify({ connectionId: "7", capability: "FILES", level: "WRITE" }))).toEqual({
      target: { connectionId: 7, capability: "FILES", level: "WRITE" },
      reason: null,
    });
  });
});
