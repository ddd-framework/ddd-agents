import { AgentsClientError, CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION, readConversationRunStatusOfRunState, } from "../../contract/conversation";
import { composeAttachmentMessageText } from "../compose-attachment-message-text";
const DEFAULT_SCRIPTED_ASSISTANT_ANSWER = "Done.";
const DEFAULT_PAGE_SIZE = 50;
/** A deep copy, so a caller can never change the client's records through a returned object. */
const copyOf = (value) => JSON.parse(JSON.stringify(value));
const sortByIndexAscending = (messages) => [...messages].sort((first, second) => (first.indexInConversation ?? 0) - (second.indexInConversation ?? 0));
const textContains = (text, searchText) => (text ?? "").toLowerCase().includes(searchText.trim().toLowerCase());
/** The message text a send stores: the typed text plus one reference line per attachment (as the host application's chat sends it). */
const buildStoredMessageText = composeAttachmentMessageText;
/**
 * The conversation client over recorded conversations, for specs, stories and the reference app
 * (`./testing`). Behaves like the server where the chat can see it: a post stores the owner's message and, at once,
 * the next scripted assistant answer — but live updates are driven by hand: `emit()` sends the conversation's next
 * signal to its subscribers (counter advanced, newest message id, the run status), `end()` ends the subscriptions.
 * With `assistantAnswerDelayMs` the answer is stored later, as a server turn would (RUNNING meanwhile); with
 * `withoutChangeSubscription` the client has no subscription at all and the chat polls it.
 */
export class InMemoryAgentsConversationClient {
    recordsByConversationId = new Map();
    subscribersByConversationId = new Map();
    signalStateByConversationId = new Map();
    scriptedAssistantAnswers;
    defaultPageSize;
    selectableModels;
    sentPayloadsByMessageId;
    assistantAnswerDelayMs;
    requestCountsByOperation = new Map();
    scriptedAssistantAnswersUsed = 0;
    nextMessageId;
    nextConversationId;
    /** Present only when the data names selectable models (the port function is optional). */
    getSelectableModels;
    /** Present only when the data names sent payloads (the port function is optional). */
    getMessageSentPayload;
    /** Present unless the data asks for a client without push (`withoutChangeSubscription`). */
    subscribeToConversationChanges;
    constructor(data) {
        for (const recordedConversation of copyOf(data.conversations)) {
            this.recordsByConversationId.set(recordedConversation.conversation.id, recordedConversation);
        }
        const scriptedAssistantAnswers = data.scriptedAssistantAnswers ?? [];
        this.scriptedAssistantAnswers =
            scriptedAssistantAnswers.length > 0 ? [...scriptedAssistantAnswers] : [DEFAULT_SCRIPTED_ASSISTANT_ANSWER];
        this.defaultPageSize = data.defaultPageSize ?? DEFAULT_PAGE_SIZE;
        this.selectableModels = data.selectableModels ? copyOf(data.selectableModels) : undefined;
        if (this.selectableModels) {
            this.getSelectableModels = this.readSelectableModels;
        }
        this.sentPayloadsByMessageId = data.sentPayloadsByMessageId ? copyOf(data.sentPayloadsByMessageId) : undefined;
        if (this.sentPayloadsByMessageId) {
            this.getMessageSentPayload = this.readMessageSentPayload;
        }
        this.assistantAnswerDelayMs = data.assistantAnswerDelayMs ?? 0;
        if (!data.withoutChangeSubscription) {
            this.subscribeToConversationChanges = this.subscribeToRecordedConversationChanges;
        }
        const allMessageIds = data.conversations.flatMap((recorded) => recorded.messages.map((message) => message.id));
        const allConversationIds = data.conversations.map((recorded) => recorded.conversation.id);
        this.nextMessageId = Math.max(0, ...allMessageIds) + 1;
        this.nextConversationId = Math.max(0, ...allConversationIds) + 1;
    }
    // ── Port ──────────────────────────────────────────────────────────────────────────────────────────────────────
    listConversations = async (agentSpaceKey, options = {}) => {
        this.countRequest("listConversations");
        const { includeUnsummarized = false, paging = {} } = options;
        const { top, skip = 0 } = paging;
        const conversationsOfAgentSpace = [...this.recordsByConversationId.values()]
            .map((recorded) => recorded.conversation)
            .filter((conversation) => conversation.agentSpaceKey === agentSpaceKey)
            .filter((conversation) => includeUnsummarized || conversation.title !== null)
            .sort((first, second) => second.id - first.id);
        const end = top === undefined ? undefined : skip + top;
        return copyOf(conversationsOfAgentSpace.slice(skip, end));
    };
    /** Account-wide, like the server: every recorded conversation is searched. */
    searchConversations = async (text, options = {}) => {
        this.countRequest("searchConversations");
        const searchGroups = [];
        for (const recorded of this.recordsByConversationId.values()) {
            const hits = this.findMessageSearchHits(recorded, text);
            if (hits.length === 0)
                continue;
            searchGroups.push({
                conversationId: recorded.conversation.id,
                title: recorded.conversation.title,
                summary: null,
                created: recorded.messages[0]?.createdDate ?? null,
                updated: recorded.messages[recorded.messages.length - 1]?.createdDate ?? null,
                relevance: 1,
                hits,
            });
        }
        return copyOf(searchGroups.slice(0, options.limit));
    };
    /** The stored conversation with `lastMessageId` (the newest stored message), as a backend without push answers it. */
    getConversationMetadata = async (conversationId) => {
        this.countRequest("getConversationMetadata");
        const recorded = this.readRecord(conversationId);
        return copyOf({ ...recorded.conversation, lastMessageId: sortByIndexAscending(recorded.messages).pop()?.id ?? null });
    };
    getNewestMessagesPage = async (conversationId, pageSize) => {
        this.countRequest("getNewestMessagesPage");
        const newestFirst = this.readMessagesNewestFirst(conversationId);
        return copyOf({ messages: newestFirst.slice(0, pageSize), hasMoreOlder: newestFirst.length > pageSize });
    };
    getOlderMessagesPage = async (conversationId, beforeIndex, pageSize) => {
        this.countRequest("getOlderMessagesPage");
        const olderMessages = this.readMessagesNewestFirst(conversationId).filter((message) => (message.indexInConversation ?? Number.MAX_SAFE_INTEGER) < beforeIndex);
        return copyOf({ messages: olderMessages.slice(0, pageSize), hasMoreOlder: olderMessages.length > pageSize });
    };
    getChangedMessages = async (conversationId, request) => {
        this.countRequest("getChangedMessages");
        const changedMessages = this.readMessagesNewestFirst(conversationId).filter((message) => message.id > request.highestKnownId || (message.indexInConversation ?? -1) >= request.fromIndex);
        return copyOf({ messages: changedMessages.slice(0, request.pageSize ?? this.defaultPageSize) });
    };
    searchConversationMessages = async (conversationId, text, options = {}) => {
        this.countRequest("searchConversationMessages");
        return copyOf(this.findMessageSearchHits(this.readRecord(conversationId), text).slice(0, options.limit));
    };
    getConversationActivity = async (conversationId) => {
        this.countRequest("getConversationActivity");
        return copyOf(this.readRecord(conversationId).activity ?? { turnMessageId: null, turnRunning: false, elements: [] });
    };
    startConversation = async (request) => {
        this.countRequest("startConversation");
        if (request.text === "" && !request.startSignalText) {
            throw new AgentsClientError("other", { httpStatus: 400, message: "A start needs a message or a signal." });
        }
        const reopenedConversation = request.entryPointKey
            ? [...this.recordsByConversationId.values()].find((recorded) => recorded.conversation.agentSpaceKey === request.agentSpaceKey &&
                recorded.conversation.entryPointKey === request.entryPointKey)
            : undefined;
        if (reopenedConversation) {
            return { conversation: copyOf(reopenedConversation.conversation), messages: [], reopenedExisting: true };
        }
        const recorded = {
            conversation: {
                id: this.nextConversationId,
                agentSpaceKey: request.agentSpaceKey,
                runState: "IDLE",
                agentTurnOwedAt: null,
                title: null,
                titleManuallyEdited: false,
                summary: null,
                created: null,
                updated: null,
                purposeName: null,
                initiatorType: null,
                scheduleId: null,
                entryPointKey: request.entryPointKey ?? null,
                progressSteps: [],
            },
            messages: [],
        };
        this.nextConversationId += 1;
        this.recordsByConversationId.set(recorded.conversation.id, recorded);
        if (request.startSignalText) {
            this.appendMessage(recorded, { role: "USER", messageType: "SIGNAL", authorType: "USER", message: request.startSignalText });
        }
        if (request.text !== "") {
            this.appendMessage(recorded, {
                role: "USER",
                messageType: "TEXT",
                authorType: "USER",
                message: buildStoredMessageText(request.text, request.attachments),
            });
        }
        const messagesBeforeTheAnswer = copyOf(recorded.messages);
        this.appendScriptedAssistantAnswer(recorded);
        return { conversation: copyOf(recorded.conversation), messages: messagesBeforeTheAnswer, reopenedExisting: false };
    };
    postMessage = async (conversationId, message) => {
        this.countRequest("postMessage");
        const recorded = this.readOpenRecord(conversationId);
        this.appendMessage(recorded, {
            role: "USER",
            messageType: "TEXT",
            authorType: "USER",
            message: buildStoredMessageText(message.text, message.attachments),
        });
        const messagesBeforeTheAnswer = copyOf(recorded.messages);
        this.appendScriptedAssistantAnswer(recorded);
        return { messages: messagesBeforeTheAnswer };
    };
    injectSignal = async (conversationId, signal) => {
        this.countRequest("injectSignal");
        const recorded = this.readOpenRecord(conversationId);
        this.appendMessage(recorded, {
            role: "USER",
            messageType: "SIGNAL",
            authorType: "USER",
            message: signal.text,
            signalSource: signal.source ?? "FRONTEND_WIDGET_CLICK",
        });
        this.appendScriptedAssistantAnswer(recorded);
    };
    retryConversation = async (conversationId) => {
        this.countRequest("retryConversation");
        const recorded = this.readRecord(conversationId);
        const newestMessage = sortByIndexAscending(recorded.messages).pop();
        if (newestMessage === undefined || newestMessage.role === "ASSISTANT") {
            throw new AgentsClientError("other", { httpStatus: 400, message: "Nothing to retry." });
        }
        this.appendScriptedAssistantAnswer(recorded);
    };
    renameConversation = async (conversationId, title) => {
        this.countRequest("renameConversation");
        const recorded = this.readRecord(conversationId);
        recorded.conversation.title = title;
        recorded.conversation.titleManuallyEdited = true;
        return copyOf(recorded.conversation);
    };
    deleteConversation = async (conversationId) => {
        this.countRequest("deleteConversation");
        this.readRecord(conversationId);
        this.recordsByConversationId.delete(conversationId);
    };
    /** The port's `subscribeToConversationChanges` unless `withoutChangeSubscription`; signals come from `emit()`. */
    subscribeToRecordedConversationChanges = (conversationId, onChange, onEnded) => {
        const subscriber = { onChange, onEnded };
        const subscribers = this.subscribersByConversationId.get(conversationId) ?? new Set();
        subscribers.add(subscriber);
        this.subscribersByConversationId.set(conversationId, subscribers);
        // Like the realtime database: the current value arrives at once, before any change.
        const record = this.recordsByConversationId.get(conversationId);
        if (record) {
            onChange(this.buildSignal(record, this.readSignalState(conversationId), null));
        }
        return () => {
            subscribers.delete(subscriber);
        };
    };
    // ── Driving live updates by hand ──────────────────────────────────────────────────────────────────────────────
    /**
     * Sends the conversation's next signal to its subscribers: the counter advances, `lastMessageId` is the newest
     * stored message, the run status follows the stored run state unless `runStatus` is given. `summaryChanged` also
     * advances `summaryUpdatedAt`. Returns the sent signal.
     */
    emit = (conversationId, change = {}) => {
        const record = this.readRecord(conversationId);
        const signalState = this.readSignalState(conversationId);
        signalState.changeCounter += 1;
        if (change.summaryChanged) {
            signalState.summaryUpdatedAt = (signalState.summaryUpdatedAt ?? 0) + 1;
        }
        const signal = this.buildSignal(record, signalState, change.runStatus ?? null);
        for (const subscriber of [...(this.subscribersByConversationId.get(conversationId) ?? [])]) {
            subscriber.onChange(copyOf(signal));
        }
        return signal;
    };
    /** Ends every subscription of the conversation with `reason`, as the server's deletion or a lost access would. */
    end = (conversationId, reason) => {
        const subscribers = [...(this.subscribersByConversationId.get(conversationId) ?? [])];
        this.subscribersByConversationId.delete(conversationId);
        for (const subscriber of subscribers) {
            subscriber.onEnded(reason);
        }
    };
    /** Sets a conversation's stored run state (a turn that runs, fails, or a conversation that closes). */
    setRunState = (conversationId, runState) => {
        this.readRecord(conversationId).conversation.runState = runState;
    };
    /**
     * How many requests the client answered — of one port function, or of all when `operation` is omitted (the
     * subscription is no request). For assertions such as "no read after the chat unmounted".
     */
    readRequestCount = (operation) => {
        if (operation !== undefined)
            return this.requestCountsByOperation.get(operation) ?? 0;
        let requestCount = 0;
        for (const operationRequestCount of this.requestCountsByOperation.values())
            requestCount += operationRequestCount;
        return requestCount;
    };
    /** Every stored message of a conversation in index order — for assertions. */
    readStoredMessages = (conversationId) => copyOf(sortByIndexAscending(this.readRecord(conversationId).messages));
    // ── Internals ─────────────────────────────────────────────────────────────────────────────────────────────────
    countRequest(operation) {
        this.requestCountsByOperation.set(operation, (this.requestCountsByOperation.get(operation) ?? 0) + 1);
    }
    readSelectableModels = async () => {
        this.countRequest("getSelectableModels");
        return copyOf(this.selectableModels ?? []);
    };
    readMessageSentPayload = async (conversationId, messageId) => {
        this.countRequest("getMessageSentPayload");
        this.readRecord(conversationId);
        const sentPayload = this.sentPayloadsByMessageId?.[messageId];
        if (sentPayload === undefined) {
            throw new AgentsClientError("notFound", { httpStatus: 404, message: `No sent payload for message ${messageId}.` });
        }
        return copyOf(sentPayload);
    };
    readMessagesNewestFirst(conversationId) {
        return sortByIndexAscending(this.readRecord(conversationId).messages).reverse();
    }
    readRecord(conversationId) {
        const recorded = this.recordsByConversationId.get(conversationId);
        if (!recorded) {
            throw new AgentsClientError("notFound", { httpStatus: 404, message: `Conversation ${conversationId} not found.` });
        }
        return recorded;
    }
    /** The record of a conversation that still takes input; a completed or cancelled one refuses like the server. */
    readOpenRecord(conversationId) {
        const recorded = this.readRecord(conversationId);
        const runState = recorded.conversation.runState;
        if (runState !== null && CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION.includes(runState)) {
            throw new AgentsClientError("closed", { httpStatus: 400, code: "CONVERSATION_CLOSED" });
        }
        return recorded;
    }
    readSignalState(conversationId) {
        const existingSignalState = this.signalStateByConversationId.get(conversationId);
        if (existingSignalState)
            return existingSignalState;
        const signalState = { changeCounter: 0, summaryUpdatedAt: null };
        this.signalStateByConversationId.set(conversationId, signalState);
        return signalState;
    }
    buildSignal(recorded, signalState, runStatus) {
        const newestMessage = sortByIndexAscending(recorded.messages).pop();
        return {
            changeCounter: signalState.changeCounter,
            lastMessageId: newestMessage?.id ?? null,
            runStatus: runStatus ?? readConversationRunStatusOfRunState(recorded.conversation.runState),
            summaryUpdatedAt: signalState.summaryUpdatedAt,
        };
    }
    appendMessage(recorded, fields) {
        const highestIndex = Math.max(-1, ...recorded.messages.map((message) => message.indexInConversation ?? -1));
        const storedMessage = {
            id: this.nextMessageId,
            conversationId: recorded.conversation.id,
            indexInConversation: highestIndex + 1,
            status: "COMPLETED",
            signalSource: null,
            createdDate: null,
            toolCall: null,
            delegatedConversations: [],
            subAgent: null,
            ...fields,
        };
        this.nextMessageId += 1;
        recorded.messages.push(storedMessage);
        return storedMessage;
    }
    /** The next scripted answer: stored at once, or after `assistantAnswerDelayMs` with the run state RUNNING meanwhile. */
    appendScriptedAssistantAnswer(recorded) {
        const answerIndex = Math.min(this.scriptedAssistantAnswersUsed, this.scriptedAssistantAnswers.length - 1);
        this.scriptedAssistantAnswersUsed += 1;
        const answerText = this.scriptedAssistantAnswers[answerIndex];
        if (this.assistantAnswerDelayMs <= 0) {
            this.storeAssistantAnswer(recorded, answerText);
            return;
        }
        recorded.conversation.runState = "RUNNING";
        setTimeout(this.storeAssistantAnswer, this.assistantAnswerDelayMs, recorded, answerText);
    }
    storeAssistantAnswer = (recorded, answerText) => {
        this.appendMessage(recorded, {
            role: "ASSISTANT",
            messageType: "TEXT",
            authorType: "ASSISTANT",
            message: answerText,
        });
        recorded.conversation.runState = "IDLE";
    };
    findMessageSearchHits(recorded, text) {
        if (text.trim() === "")
            return [];
        return sortByIndexAscending(recorded.messages)
            .filter((message) => message.messageType === "TEXT" && textContains(message.message, text))
            .map((message) => ({
            messageId: message.id,
            conversationId: recorded.conversation.id,
            indexInConversation: message.indexInConversation,
            role: message.role,
            excerpt: message.message,
            relevance: 1,
            created: message.createdDate,
            updated: null,
            title: recorded.conversation.title,
        }));
    }
}
/** The in-memory conversation client over recorded conversations (plain data). */
export const createInMemoryAgentsConversationClient = (data) => new InMemoryAgentsConversationClient(data);
