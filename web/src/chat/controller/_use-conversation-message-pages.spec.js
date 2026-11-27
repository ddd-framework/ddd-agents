import { act, renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it } from "vitest";
import { createAgentsChatStore } from "../store/create-agents-chat-store";
import { useConversationMessagePages } from "./use-conversation-message-pages";
const CONVERSATION_ID = 7;
const PAGE_SIZE = 50;
const message = (indexInConversation) => ({ id: 100 + indexInConversation, conversationId: CONVERSATION_ID, indexInConversation });
let sent = [];
/** A read that stays on its way until the spec answers or refuses it. */
const sendRead = (conversationId, changedMessagesRequest, answer) => new Promise((resolve, reject) => {
    sent.push({
        conversationId,
        changedMessagesRequest,
        respond: async (messages) => {
            await act(async () => {
                resolve(answer(messages));
            });
        },
        fail: async () => {
            await act(async () => {
                reject(new Error("refused"));
            });
        },
    });
});
/** The conversation client's message reads; every other port function stays unused by the hook. */
const conversationClient = {
    getNewestMessagesPage: (conversationId) => sendRead(conversationId, null, (messages) => ({ messages, hasMoreOlder: false })),
    getChangedMessages: (conversationId, request) => sendRead(conversationId, request, (messages) => ({ messages })),
};
const renderMessagePages = (store = createAgentsChatStore()) => {
    const { result } = renderHook(() => useConversationMessagePages(store, conversationClient, PAGE_SIZE));
    return { store, pages: result.current };
};
const shownIds = (store) => (store.getState().messages ?? []).map((shown) => shown.id);
beforeEach(() => {
    sent = [];
});
describe("useConversationMessagePages — one newest page on open", () => {
    it("holds the live refetches while the open's page is on its way, then reads only what was written since", async () => {
        const { store, pages } = renderMessagePages();
        act(() => pages.fetchOpeningPage(CONVERSATION_ID));
        // Three live updates (a signal, a run-state check, a convergence tick), all before the page lands.
        act(() => {
            pages.refetchChangedTail(CONVERSATION_ID);
            pages.refetchChangedTail(CONVERSATION_ID);
            pages.refetchChangedTail(CONVERSATION_ID);
        });
        expect(sent).toHaveLength(1);
        expect(sent[0].changedMessagesRequest).toBeNull();
        await sent[0].respond([message(0), message(1), message(2)]);
        expect(sent).toHaveLength(2);
        expect(sent[1].changedMessagesRequest).toEqual({ highestKnownId: 102, fromIndex: 3, pageSize: PAGE_SIZE });
        await sent[1].respond([message(3)]);
        expect(shownIds(store)).toEqual([100, 101, 102, 103]);
        expect(sent.filter((read) => read.changedMessagesRequest === null)).toHaveLength(1);
    });
    it("sends nothing after the page when no refetch was asked meanwhile", async () => {
        const { pages } = renderMessagePages();
        act(() => pages.fetchOpeningPage(CONVERSATION_ID));
        await sent[0].respond([message(0)]);
        expect(sent).toHaveLength(1);
    });
    it("refetches straight away, past the loaded rows, once the page is in", async () => {
        const { pages } = renderMessagePages();
        act(() => pages.fetchOpeningPage(CONVERSATION_ID));
        await sent[0].respond([message(0), message(1)]);
        act(() => pages.refetchChangedTail(CONVERSATION_ID));
        expect(sent).toHaveLength(2);
        expect(sent[1].changedMessagesRequest).toEqual({ highestKnownId: 101, fromIndex: 2, pageSize: PAGE_SIZE });
    });
    it("drops the held refetch when the chat was reset before the page landed", async () => {
        const { store, pages } = renderMessagePages();
        act(() => pages.fetchOpeningPage(CONVERSATION_ID));
        act(() => pages.refetchChangedTail(CONVERSATION_ID));
        act(() => {
            store.dispatch({ type: "reset" });
        });
        await sent[0].respond([message(0)]);
        expect(sent).toHaveLength(1);
        expect(store.getState().messages).toBeNull();
    });
    it("does not hold another conversation's refetch", () => {
        const { pages } = renderMessagePages();
        act(() => pages.fetchOpeningPage(CONVERSATION_ID));
        act(() => pages.refetchChangedTail(CONVERSATION_ID + 1));
        expect(sent.map((read) => read.conversationId)).toEqual([CONVERSATION_ID, CONVERSATION_ID + 1]);
    });
    it("keeps holding the new conversation's refetch when the replaced open's page lands", async () => {
        const { pages } = renderMessagePages();
        act(() => pages.fetchOpeningPage(CONVERSATION_ID));
        act(() => pages.fetchOpeningPage(CONVERSATION_ID + 1));
        await sent[0].respond([message(0)]);
        act(() => pages.refetchChangedTail(CONVERSATION_ID + 1));
        expect(sent.map((read) => read.conversationId)).toEqual([CONVERSATION_ID, CONVERSATION_ID + 1]);
    });
    it("retries the newest page once when the open's page fails with a refetch owed, then holds nothing", async () => {
        const { pages } = renderMessagePages();
        act(() => pages.fetchOpeningPage(CONVERSATION_ID));
        act(() => pages.refetchChangedTail(CONVERSATION_ID));
        await sent[0].fail();
        expect(sent).toHaveLength(2);
        expect(sent[1].changedMessagesRequest).toBeNull();
        await sent[1].respond([message(0)]);
        act(() => pages.refetchChangedTail(CONVERSATION_ID));
        expect(sent).toHaveLength(3);
        expect(sent[2].changedMessagesRequest).toEqual({ highestKnownId: 100, fromIndex: 1, pageSize: PAGE_SIZE });
    });
});
