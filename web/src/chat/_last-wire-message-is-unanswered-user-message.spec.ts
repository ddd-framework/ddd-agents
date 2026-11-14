import { describe, expect, test } from "vitest";
import { lastWireMessageIsUnansweredUserMessage } from "./last-wire-message-is-unanswered-user-message";
import type { ChatMessage } from "./chat-message";

const message = (
  indexInConversation: number,
  authorType: "USER" | "ASSISTANT" | "AGENT" | "TOOL",
  messageType: "TEXT" | "ERROR" | "CHECKPOINT" | "TOOL_MESSAGE" | "SIGNAL" = "TEXT",
  signalSource?: string,
): ChatMessage =>
  ({
    id: indexInConversation,
    indexInConversation,
    authorType,
    messageType,
    signalSource,
    message: "x",
  }) as ChatMessage;

describe("lastWireMessageIsUnansweredUserMessage", () => {
  test("an empty or missing list is never owed", () => {
    expect(lastWireMessageIsUnansweredUserMessage(null)).toBe(false);
    expect(lastWireMessageIsUnansweredUserMessage([])).toBe(false);
  });

  test("a trailing user message is owed", () => {
    expect(lastWireMessageIsUnansweredUserMessage([message(0, "ASSISTANT"), message(1, "USER")])).toBe(true);
  });

  test("a user message followed only by ERROR / CHECKPOINT bookkeeping rows is still owed", () => {
    expect(
      lastWireMessageIsUnansweredUserMessage([
        message(0, "ASSISTANT"),
        message(1, "USER"),
        message(2, "AGENT", "ERROR"),
        message(3, "AGENT", "CHECKPOINT"),
      ]),
    ).toBe(true);
  });

  test("an answered user message stays answered behind a bookkeeping row", () => {
    expect(
      lastWireMessageIsUnansweredUserMessage([
        message(0, "USER"),
        message(1, "ASSISTANT"),
        message(2, "AGENT", "ERROR"),
      ]),
    ).toBe(false);
  });

  test("a tool's own widget after the user message is not the agent's answer — still owed", () => {
    expect(
      lastWireMessageIsUnansweredUserMessage([
        message(0, "ASSISTANT"),
        message(1, "USER"),
        message(2, "TOOL", "TOOL_MESSAGE"),
        message(3, "AGENT", "ERROR"),
      ]),
    ).toBe(true);
  });

  test("the platform's widget notice after the final text is walked past, never owed", () => {
    expect(
      lastWireMessageIsUnansweredUserMessage([
        message(0, "USER"),
        message(1, "ASSISTANT"),
        message(2, "TOOL", "TOOL_MESSAGE"),
        message(3, "AGENT", "SIGNAL", "PLATFORM_WIDGET_EMITTED"),
      ]),
    ).toBe(false);
    expect(
      lastWireMessageIsUnansweredUserMessage([
        message(0, "ASSISTANT"),
        message(1, "AGENT", "SIGNAL", "FRONTEND_WIDGET_CLICK"),
        message(2, "AGENT", "SIGNAL", "PLATFORM_WIDGET_EMITTED"),
      ]),
    ).toBe(true);
  });

  test("a trailing widget-click signal is owed work; a phone-call lifecycle chip is walked past", () => {
    expect(
      lastWireMessageIsUnansweredUserMessage([message(0, "ASSISTANT"), message(1, "AGENT", "SIGNAL", "FRONTEND_WIDGET_CLICK")]),
    ).toBe(true);
    expect(
      lastWireMessageIsUnansweredUserMessage([
        message(0, "USER"),
        message(1, "ASSISTANT"),
        message(2, "AGENT", "SIGNAL", "PHONE_CALL_ENDED"),
      ]),
    ).toBe(false);
    expect(
      lastWireMessageIsUnansweredUserMessage([
        message(0, "ASSISTANT"),
        message(1, "USER"),
        message(2, "AGENT", "SIGNAL", "PHONE_CALL_STARTED"),
      ]),
    ).toBe(true);
  });

  test("the walk uses the conversation order, not the array order", () => {
    expect(lastWireMessageIsUnansweredUserMessage([message(1, "USER"), message(0, "ASSISTANT")])).toBe(true);
    expect(lastWireMessageIsUnansweredUserMessage([message(1, "ASSISTANT"), message(0, "USER")])).toBe(false);
  });
});
