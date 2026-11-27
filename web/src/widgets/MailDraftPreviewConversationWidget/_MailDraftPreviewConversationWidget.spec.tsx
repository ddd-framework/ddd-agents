// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import React from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import {
  type ConversationWidgetTranscriptMessage,
  ConversationWidgetContext,
  defaultConversationWidgetCallbacks,
} from "../../renderer/ConversationWidgetCallbacksContext";
import { MailDraftPreviewConversationWidget } from "./MailDraftPreviewConversationWidget";
import { isSentMailDraftPreviewOf, parseMailDraftPreviewPayload } from "./mail-draft-preview-payload";

/**
 * The DRAFT preview's one button posts its translated label as the owner's message, once; the button goes away after a
 * later SENT row of the same step or a later owner message; a SENT preview shows the chip and no button; a block
 * without a recipient renders nothing.
 */
describe("MailDraftPreview widget", () => {
  let environmentBefore: AgentsEnvironment;

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    // A visible translation, so the spec sees that the label the owner reads is what goes out.
    setAgentsEnvironment({ texts: { translate: (key) => `«${key}»` } });
  });
  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  const fields = {
    status: "DRAFT",
    connectionId: 4,
    from: "owner@example.org",
    to: ["first@example.org", "second@example.org"],
    subject: "Quarterly numbers",
    bodyText: "Hello,\n\n| Month | Total |\n| --- | --- |\n| May | 12 |\n\nRegards",
    contentHash: "hash-1",
  };
  const draftPayload = JSON.stringify(fields);
  const sentRow = (contentHash: string): ConversationWidgetTranscriptMessage => ({
    id: 12,
    role: "TOOL",
    message: `\`\`\`widget\n${JSON.stringify({ type: "MailDraftPreview", ...fields, status: "SENT", contentHash, emittedBy: "tool:sendMail" })}\n\`\`\``,
  });
  const hostRow: ConversationWidgetTranscriptMessage = { id: 10, role: "TOOL", message: "" };

  const renderWidget = (
    payload: string,
    messages: ConversationWidgetTranscriptMessage[],
    sendMessageToChat: (text: string) => void | Promise<boolean> = () => {},
    interactive = true,
  ) =>
    render(
      <ConversationWidgetContext.Provider value={{ ...defaultConversationWidgetCallbacks, sendMessageToChat, messages }}>
        <MailDraftPreviewConversationWidget payload={payload} interactive={interactive} messageId={10} />
      </ConversationWidgetContext.Provider>,
    );

  it("shows the header fields and the body as markdown", () => {
    renderWidget(draftPayload, [hostRow]);
    expect(screen.getByTestId("mail-draft-preview-widget-to")).toHaveTextContent("first@example.org, second@example.org");
    expect(screen.getByTestId("mail-draft-preview-widget-subject")).toHaveTextContent("Quarterly numbers");
    expect(screen.getByTestId("mail-draft-preview-widget-body").querySelector("table")).not.toBeNull();
    expect(screen.queryByTestId("mail-draft-preview-widget-cc")).toBeNull();
  });

  it("sends the translated label as the owner's message, once", async () => {
    const sentTexts: string[] = [];
    renderWidget(draftPayload, [hostRow], async (text) => {
      sentTexts.push(text);
      return true;
    });
    const button = screen.getByTestId("mail-draft-preview-widget-send");
    await act(async () => {
      fireEvent.click(button);
    });
    await act(async () => {
      fireEvent.click(screen.getByTestId("mail-draft-preview-widget-send"));
    });
    expect(sentTexts).toEqual(["«Send»"]);
    expect(screen.getByTestId("mail-draft-preview-widget-send")).toBeDisabled();
  });

  it("stays usable when the host refuses the send", async () => {
    const sendMessageToChat = vi.fn(async () => false);
    renderWidget(draftPayload, [hostRow], sendMessageToChat);
    await act(async () => {
      fireEvent.click(screen.getByTestId("mail-draft-preview-widget-send"));
    });
    expect(sendMessageToChat).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId("mail-draft-preview-widget-send")).not.toBeDisabled();
  });

  it("hides the button after a later SENT row of the same step", () => {
    renderWidget(draftPayload, [hostRow, sentRow("hash-1")]);
    expect(screen.queryByTestId("mail-draft-preview-widget-send")).toBeNull();
  });

  it("keeps the button after a later SENT row of another step", () => {
    renderWidget(draftPayload, [hostRow, sentRow("hash-2")]);
    expect(screen.getByTestId("mail-draft-preview-widget-send")).toBeInTheDocument();
  });

  it("hides the button after a later owner message", () => {
    renderWidget(draftPayload, [hostRow, { id: 11, role: "USER", message: "Please change the subject" }]);
    expect(screen.queryByTestId("mail-draft-preview-widget-send")).toBeNull();
  });

  it("hides the button on a message that is not interactive", () => {
    renderWidget(draftPayload, [hostRow], () => {}, false);
    expect(screen.queryByTestId("mail-draft-preview-widget-send")).toBeNull();
  });

  it("shows the Sent chip and no button for a SENT preview", () => {
    renderWidget(JSON.stringify({ ...fields, status: "SENT" }), [hostRow]);
    expect(screen.getByTestId("mail-draft-preview-widget-sent")).toBeInTheDocument();
    expect(screen.queryByTestId("mail-draft-preview-widget-send")).toBeNull();
  });

  it("renders nothing for an invalid block", () => {
    renderWidget("{", [hostRow]);
    renderWidget(JSON.stringify({ ...fields, to: [] }), [hostRow]);
    expect(screen.queryByTestId("mail-draft-preview-widget")).toBeNull();
  });

  it("parses the payload leniently and refuses a block without a recipient", () => {
    expect(parseMailDraftPreviewPayload(undefined)).toBeNull();
    expect(parseMailDraftPreviewPayload("[]")).toBeNull();
    expect(parseMailDraftPreviewPayload(JSON.stringify({ to: [" ", 3] }))).toBeNull();
    expect(parseMailDraftPreviewPayload(JSON.stringify({ status: "UNKNOWN", connectionId: "7", to: [" a@example.org "], cc: "x" }))).toEqual({
      status: "DRAFT",
      connectionId: 7,
      from: "",
      to: ["a@example.org"],
      cc: [],
      bcc: [],
      subject: "",
      bodyText: "",
      contentHash: "",
    });
  });

  it("recognizes the SENT row of a step only by its hash", () => {
    expect(isSentMailDraftPreviewOf(sentRow("hash-1").message, "hash-1")).toBe(true);
    expect(isSentMailDraftPreviewOf(sentRow("hash-1").message, "hash-2")).toBe(false);
    expect(isSentMailDraftPreviewOf(sentRow("hash-1").message, "")).toBe(false);
    expect(isSentMailDraftPreviewOf(`\`\`\`widget\n${JSON.stringify({ type: "MailDraftPreview", ...fields })}\n\`\`\``, "hash-1")).toBe(false);
  });

  it("shows an image of the body as its alt text, never loads it", () => {
    const payload = JSON.stringify({ ...fields, bodyText: "Hello ![Logo](https://tracker.example.org/p.png)" });
    const { container } = renderWidget(payload, [hostRow]);
    expect(container.querySelector("img")).toBeNull();
    expect(screen.getByTestId("mail-draft-preview-widget-body").textContent).toContain("Logo");
  });
});
