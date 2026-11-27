// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import React from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { ConversationWidgetAgentSpaceHeader } from "./ConversationWidgetAgentSpaceHeader";

/**
 * The name of an agent space is data. The chip and its aria-label are rendered by React as written; the tooltip's
 * explanation reaches the tooltip component through the environment's `escapePlainTextForText`, so an app whose
 * tooltip reads a string as markup escapes it there.
 */

const hydration = vi.hoisted(() => ({ name: "" }));
vi.mock("../widget-hydration", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useWidgetHydration: () => ({ data: { name: hydration.name } }),
}));

const names = ['<span class="injected">x</span> &amp;', "<iframe src=x></iframe>", 'Ordinary & "Ω"'] as const;

describe("agent space header literal display", () => {
  let environmentBefore: AgentsEnvironment;

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
  });
  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  it.each(names)("shows the name as written in the chip, the aria-label and the tooltip: %s", (name) => {
    hydration.name = name;
    setAgentsEnvironment({});
    const { container } = render(<ConversationWidgetAgentSpaceHeader agentSpaceKey={12} />);
    const note = screen.getByRole("note");
    expect(note.textContent).toBe(name);
    expect(note.getAttribute("aria-label")).toBe(`Showing data for ${name}`);
    fireEvent.mouseEnter(note.parentElement!);
    expect(screen.getByRole("tooltip").textContent).toContain(name);
    expect(container.querySelector(".injected, iframe")).toBeNull();
  });

  it.each(names)("hands the tooltip's explanation through escapePlainTextForText, not the chip: %s", (name) => {
    hydration.name = name;
    setAgentsEnvironment({
      escapePlainTextForText: (text) => `escaped:${text}`,
      buildWidgetOfAnotherAgentSpaceExplanation: (label) => `Data of ${label}`,
    });
    render(<ConversationWidgetAgentSpaceHeader agentSpaceKey={12} />);
    const note = screen.getByRole("note");
    expect(note.textContent).toBe(name);
    fireEvent.mouseEnter(note.parentElement!);
    expect(screen.getByRole("tooltip").textContent).toBe(`escaped:Data of ${name}`);
  });
});
