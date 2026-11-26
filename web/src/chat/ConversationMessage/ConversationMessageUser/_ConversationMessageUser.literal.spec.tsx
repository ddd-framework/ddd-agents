// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import React from "react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../../provider/environment";
import { ConversationMessageUser } from "./ConversationMessageUser";

/**
 * The owner's own message is data: it is shown as written. The prose reaches the text component through the
 * environment's `escapePlainTextForText`, so an app whose text component reads a string as markup escapes it there.
 */

const examples = ["<img src=“x” alt=“x”>", '<img src="x" alt="x">', "Zażółć 日本語 & café &amp; <hello>"] as const;

describe("user message literal display", () => {
  let environmentBefore: AgentsEnvironment;

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
  });
  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  it.each(examples)("shows the prose as written with the default text component: %s", (value) => {
    setAgentsEnvironment({});
    render(<ConversationMessageUser text={`${value}\nSecond line`} />);
    const output = screen.getByTestId("conversation-message-user-text");
    expect(output.textContent).toContain(value);
    expect(output.textContent).toContain("Second line");
    expect(output.querySelector("img")).toBeNull();
  });

  it.each(examples)("hands the prose to the text component through escapePlainTextForText: %s", (value) => {
    const escapedTexts: string[] = [];
    setAgentsEnvironment({
      escapePlainTextForText: (text) => {
        escapedTexts.push(text);
        return `escaped:${text}`;
      },
    });
    render(<ConversationMessageUser text={value} />);
    expect(escapedTexts).toEqual([value]);
    expect(screen.getByTestId("conversation-message-user-text").textContent).toBe(`escaped:${value}`);
  });
});
