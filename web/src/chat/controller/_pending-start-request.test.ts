import { describe, expect, it } from "vitest";
import {
  ChatStateForPendingStartRequest,
  isChatReadyToSendPendingStartRequest,
  pickPendingStartRequest,
} from "./pending-start-request";

/** A cold chat with a request waiting — the one state in which the request is sent. */
const coldChatWithPendingRequest: ChatStateForPendingStartRequest = {
  pendingStartRequest: { kind: "message", text: "Publish my business to the directories I'm still missing." },
  activeConversationId: undefined,
  loadedConversationId: undefined,
  startingNewConversation: false,
  alreadySentStartRequest: null,
};

const coldChatWithPendingSignal: ChatStateForPendingStartRequest = {
  ...coldChatWithPendingRequest,
  pendingStartRequest: { kind: "signal", text: "The customer wants to finish the directory publishing." },
};

describe("isChatReadyToSendPendingStartRequest", () => {
  it("sends on a cold chat with a request waiting", () => {
    expect(isChatReadyToSendPendingStartRequest(coldChatWithPendingRequest)).toBe(true);
  });

  it("does nothing when no request is pending", () => {
    expect(isChatReadyToSendPendingStartRequest({ ...coldChatWithPendingRequest, pendingStartRequest: null })).toBe(
      false,
    );
  });

  it("waits while the widget still has a conversation open — the request is never appended to it (BUF-4009: the CTA promises a fresh chat)", () => {
    expect(isChatReadyToSendPendingStartRequest({ ...coldChatWithPendingRequest, activeConversationId: 42 })).toBe(
      false,
    );
  });

  it("waits while the slice still holds the previous conversation, after the widget already dropped to a new chat", () => {
    expect(isChatReadyToSendPendingStartRequest({ ...coldChatWithPendingRequest, loadedConversationId: 42 })).toBe(
      false,
    );
  });

  it("waits while a start is already in flight — sending now would hit the send handler's in-flight guard and lose the request", () => {
    expect(isChatReadyToSendPendingStartRequest({ ...coldChatWithPendingRequest, startingNewConversation: true })).toBe(
      false,
    );
  });

  it("does not send the same request twice", () => {
    expect(
      isChatReadyToSendPendingStartRequest({
        ...coldChatWithPendingRequest,
        alreadySentStartRequest: coldChatWithPendingRequest.pendingStartRequest,
      }),
    ).toBe(false);
  });

  it("sends again when a DIFFERENT request arrives after one was already sent", () => {
    expect(
      isChatReadyToSendPendingStartRequest({
        ...coldChatWithPendingRequest,
        pendingStartRequest: { kind: "message", text: "Reply to the reviews I haven't answered yet." },
        alreadySentStartRequest: coldChatWithPendingRequest.pendingStartRequest,
      }),
    ).toBe(true);
  });

  it("sends a start signal on a cold chat, under the same readiness rules as a message", () => {
    expect(isChatReadyToSendPendingStartRequest(coldChatWithPendingSignal)).toBe(true);
    expect(isChatReadyToSendPendingStartRequest({ ...coldChatWithPendingSignal, activeConversationId: 42 })).toBe(
      false,
    );
    expect(isChatReadyToSendPendingStartRequest({ ...coldChatWithPendingSignal, loadedConversationId: 42 })).toBe(
      false,
    );
    expect(isChatReadyToSendPendingStartRequest({ ...coldChatWithPendingSignal, startingNewConversation: true })).toBe(
      false,
    );
  });

  it("does not send the same start signal twice", () => {
    expect(
      isChatReadyToSendPendingStartRequest({
        ...coldChatWithPendingSignal,
        alreadySentStartRequest: coldChatWithPendingSignal.pendingStartRequest,
      }),
    ).toBe(false);
  });

  it("a signal with the text of an already-sent message is a different request", () => {
    expect(
      isChatReadyToSendPendingStartRequest({
        ...coldChatWithPendingRequest,
        pendingStartRequest: { kind: "signal", text: coldChatWithPendingRequest.pendingStartRequest!.text },
        alreadySentStartRequest: coldChatWithPendingRequest.pendingStartRequest,
      }),
    ).toBe(true);
  });
});

describe("pickPendingStartRequest", () => {
  it("returns the message or the signal, whichever is set", () => {
    expect(pickPendingStartRequest("Publish my business.", null)).toEqual({
      kind: "message",
      text: "Publish my business.",
    });
    expect(pickPendingStartRequest(null, "Start the review replies.")).toEqual({
      kind: "signal",
      text: "Start the review replies.",
    });
    expect(pickPendingStartRequest(null, null)).toBeNull();
  });

  it("lets the message win when both are set", () => {
    expect(pickPendingStartRequest("Publish my business.", "Start the review replies.")?.kind).toBe("message");
  });
});
