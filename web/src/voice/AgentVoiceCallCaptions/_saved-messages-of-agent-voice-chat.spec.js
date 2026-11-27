import { describe, expect, it } from "vitest";
import { highestIndexInConversationOf, savedMessagesOfTheVoiceChat } from "./saved-messages-of-agent-voice-chat";
const chatMessage = (role, message, indexInConversation) => ({ role, message, indexInConversation });
describe("highestIndexInConversationOf", () => {
    it("is -1 for no message and for messages without an index yet (the owner’s optimistic message)", () => {
        expect(highestIndexInConversationOf([])).toBe(-1);
        expect(highestIndexInConversationOf([chatMessage("USER", "Hello"), chatMessage("USER", "Hi", null)])).toBe(-1);
    });
    it("is the highest index, in any order", () => {
        expect(highestIndexInConversationOf([chatMessage("USER", "a", 4), chatMessage("ASSISTANT", "b", 9), chatMessage("USER", "c", 2)])).toBe(9);
        expect(highestIndexInConversationOf([chatMessage("SYSTEM", "a", 0)])).toBe(0);
    });
});
describe("savedMessagesOfTheVoiceChat", () => {
    it("takes the agent’s and the owner’s text messages after the voice chat started, in the conversation’s order", () => {
        const messages = [
            chatMessage("ASSISTANT", "Before the call.", 3),
            chatMessage("USER", "Move my meeting.", 6),
            chatMessage("ASSISTANT", "Hello, how can I help?", 5),
            chatMessage("ASSISTANT", "Done.", 7),
        ];
        expect(savedMessagesOfTheVoiceChat(messages, 4)).toStrictEqual([
            { speaker: "agent", text: "Hello, how can I help?" },
            { speaker: "owner", text: "Move my meeting." },
            { speaker: "agent", text: "Done." },
        ]);
    });
    it("leaves out a message at the start index, a system message, a message without text and one without an index", () => {
        const messages = [
            chatMessage("USER", "At the start index.", 4),
            chatMessage("SYSTEM", "A system note.", 5),
            chatMessage("ASSISTANT", null, 6),
            chatMessage("ASSISTANT", { widget: "map" }, 7),
            chatMessage("USER", "Optimistic, no index yet."),
            chatMessage(null, "No role.", 8),
            chatMessage("ASSISTANT", "Kept.", 9),
        ];
        expect(savedMessagesOfTheVoiceChat(messages, 4)).toStrictEqual([{ speaker: "agent", text: "Kept." }]);
    });
    it("takes every message with an index when nothing was held before the voice chat", () => {
        expect(savedMessagesOfTheVoiceChat([chatMessage("USER", "First.", 0)], -1)).toStrictEqual([{ speaker: "owner", text: "First." }]);
    });
    it("leaves the messages it was given in their order", () => {
        const messages = [chatMessage("USER", "b", 6), chatMessage("USER", "a", 5)];
        savedMessagesOfTheVoiceChat(messages, -1);
        expect(messages.map(message => message.indexInConversation)).toStrictEqual([6, 5]);
    });
});
