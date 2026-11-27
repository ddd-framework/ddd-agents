// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import React from "react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { ContinueInPersonalSpaceConversationWidget } from "./ContinueInPersonalSpaceConversationWidget";
import { parseContinueInPersonalSpacePayload } from "./continue-in-personal-space-payload";

/**
 * The button hands the request to the app's `startConversationInPersonalSpace` once; without that hook or without a
 * request the widget renders nothing.
 */
describe("ContinueInPersonalSpace widget", () => {
  let environmentBefore: AgentsEnvironment;
  let startedTexts: string[];

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    startedTexts = [];
  });
  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  const payload = JSON.stringify({ request: "  Show my newest mails  ", reason: "Only you may use this connection." });

  it("starts the conversation in the personal space with the request, once", () => {
    setAgentsEnvironment({ startConversationInPersonalSpace: (ownerText) => startedTexts.push(ownerText) });
    render(<ContinueInPersonalSpaceConversationWidget payload={payload} interactive />);
    const button = screen.getByTestId("continue-in-personal-space-widget-button");
    fireEvent.click(button);
    fireEvent.click(button);
    expect(startedTexts).toEqual(["Show my newest mails"]);
    expect(screen.getByTestId("continue-in-personal-space-widget-notice").textContent).toContain("Only you");
  });

  it("renders nothing when the app cannot start a conversation in the personal space", () => {
    setAgentsEnvironment({});
    render(<ContinueInPersonalSpaceConversationWidget payload={payload} interactive />);
    expect(screen.queryByTestId("continue-in-personal-space-widget")).toBeNull();
  });

  it("renders nothing for a block without a request", () => {
    setAgentsEnvironment({ startConversationInPersonalSpace: (ownerText) => startedTexts.push(ownerText) });
    render(<ContinueInPersonalSpaceConversationWidget payload={JSON.stringify({ request: " " })} interactive />);
    expect(screen.queryByTestId("continue-in-personal-space-widget")).toBeNull();
  });

  it("parses the payload leniently", () => {
    expect(parseContinueInPersonalSpacePayload("not json")).toBeNull();
    expect(parseContinueInPersonalSpacePayload(undefined)).toBeNull();
    expect(parseContinueInPersonalSpacePayload(JSON.stringify({ request: "x", reason: 3 }))).toEqual({ request: "x", reason: null });
  });
});
