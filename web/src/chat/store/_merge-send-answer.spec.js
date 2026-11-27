import { describe, expect, it } from "vitest";
import { INITIAL_AGENTS_CHAT_STATE } from "./agents-chat-state";
import { reduceAgentsChatState } from "./reduce-agents-chat-state";
const saved = (index, role, message) => ({ id: 100 + index, indexInConversation: index, role, messageType: "TEXT", message, conversationId: 7 });
const optimistic = (message) => ({ role: "USER", messageType: "TEXT", message });
const postReceived = (messages, answer) => reduceAgentsChatState({ ...INITIAL_AGENTS_CHAT_STATE, conversation: { id: 7 }, messages }, { type: "messagePostReceived", conversationId: 7, posted: { messages: answer } }).messages ?? [];
describe("a send's answer replaces exactly its optimistic owner row", () => {
    it("replaces the optimistic row although a live read put indexed rows behind it", () => {
        const merged = postReceived([saved(1, "USER", "first"), optimistic("second"), saved(2, "ASSISTANT", "answer one")], [saved(3, "USER", "second")]);
        expect(merged.filter((message) => message.indexInConversation == null)).toHaveLength(0);
        expect(merged.map((message) => message.indexInConversation)).toEqual([1, 2, 3]);
    });
    it("keeps the bubble of a second message still in flight", () => {
        const merged = postReceived([saved(1, "ASSISTANT", "hello"), optimistic("one"), optimistic("two")], [saved(2, "USER", "one")]);
        expect(merged.filter((message) => message.indexInConversation == null).map((message) => message.message)).toEqual([
            "two",
        ]);
    });
});
