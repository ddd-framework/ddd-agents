import { describe, expect, test, vi } from "vitest";
import { AGENTS_CLIENT_CONTEXT_LIMITS, AgentsClientError } from "../../contract/conversation";
import { mapClientContextToWire } from "./agents-http-mappers";
import { createAccountRealtimePoll } from "../../ui/account-realtime";
import { createAgentsHttpConversationClient } from "./createAgentsHttpConversationClient";
/** A fetch that answers every request with the next scripted answer and records what it got. */
const createScriptedFetch = (answers) => {
    const requests = [];
    const fetchFunction = vi.fn(async (input, init) => {
        requests.push({
            url: new URL(String(input), "https://host.test"),
            method: init?.method ?? "GET",
            body: init?.body ? JSON.parse(String(init.body)) : undefined,
            headers: new Headers(init?.headers),
        });
        const answer = answers.shift() ?? { body: {} };
        return new Response(answer.body === undefined ? "" : JSON.stringify(answer.body), {
            status: answer.status ?? 200,
            headers: answer.headers,
        });
    });
    return { fetchFunction: fetchFunction, requests };
};
const buildClient = (answers, options = {}) => {
    const { fetchFunction, requests } = createScriptedFetch(answers);
    const client = createAgentsHttpConversationClient({
        baseUrl: "/api/agents/conversations/",
        fetch: fetchFunction,
        polling: false,
        ...options,
    });
    return { client, requests };
};
const wireMessage = {
    id: 41,
    aiConversationId: 7,
    indexInConversation: 3,
    role: "ASSISTANT",
    messageType: "TOOL_CALL",
    status: "COMPLETED",
    authorType: "ASSISTANT",
    signalSource: null,
    message: "Looking it up.",
    createdDate: "2026-11-01",
    toolCall: { toolCallId: "call-1", name: "delegate", arguments: { tasks: [] }, status: "RUNNING" },
    delegatedConversations: { elements: [{ id: 90, runState: "RUNNING" }] },
    delegationResults: { subAgent: { currentRunNumber: 2, runs: { elements: [{ runNumber: 2, status: "running" }] } } },
};
describe("createAgentsHttpConversationClient", () => {
    test("reads the newest page newest first with the delegated children and maps each row", async () => {
        const { client, requests } = buildClient([{ body: { messages: { elements: [wireMessage] }, hasMoreOlder: true } }]);
        const page = await client.getNewestMessagesPage(7, 20);
        expect(requests[0].url.pathname).toBe("/api/agents/conversations/7/messages");
        expect(requests[0].url.searchParams.get("orderBy")).toBe("indexInConversation desc");
        expect(requests[0].url.searchParams.get("top")).toBe("20");
        expect(requests[0].url.searchParams.get("expand")).toBe("delegatedConversations(select=id,runState)");
        expect(requests[0].url.searchParams.has("filters")).toBe(false);
        expect(page.hasMoreOlder).toBe(true);
        expect(page.messages[0]).toEqual({
            id: 41,
            conversationId: 7,
            indexInConversation: 3,
            role: "ASSISTANT",
            messageType: "TOOL_CALL",
            status: "COMPLETED",
            authorType: "ASSISTANT",
            signalSource: null,
            message: "Looking it up.",
            createdDate: "2026-11-01",
            toolCall: { toolCallId: "call-1", name: "delegate", arguments: { tasks: [] }, status: "RUNNING" },
            delegatedConversations: [{ id: 90, runState: "RUNNING" }],
            subAgent: { currentRunNumber: 2, runs: [{ runNumber: 2, status: "running" }] },
        });
    });
    test("an older page and a changed-messages read filter by index and id", async () => {
        const { client, requests } = buildClient([{ body: { messages: [], hasMoreOlder: false } }, { body: { messages: [] } }]);
        await client.getOlderMessagesPage(7, 12, 20);
        await client.getChangedMessages(7, { highestKnownId: 55, fromIndex: 9 });
        expect(requests[0].url.searchParams.get("filters")).toBe("indexInConversation lt '12'");
        expect(requests[1].url.searchParams.get("filters")).toBe("(id gt '55' or indexInConversation ge '9')");
        expect(requests[1].url.searchParams.get("top")).toBe("50");
    });
    test("maps a conversation: summary, change history, progress steps, agent space", async () => {
        const { client, requests } = buildClient([
            {
                body: {
                    aiConversation: {
                        id: 7,
                        agentSpaceId: 3,
                        runState: "IDLE",
                        agentTurnOwedAt: null,
                        summary: { title: "Visits", text: "About visits", manuallyEdited: true },
                        changeHistory: { createdTime: "2026-11-01 10:00:00", modifiedTime: "2026-11-02 10:00:00" },
                        purposeName: null,
                        initiatorType: "SCHEDULE",
                        scheduleId: 5,
                        entryPointKey: null,
                        customerProgressSteps: { elements: [{ key: "a", customerLabel: "First", status: "active" }] },
                    },
                },
            },
        ]);
        const conversation = await client.getConversationMetadata(7);
        expect(requests[0].url.pathname).toBe("/api/agents/conversations/7");
        expect(conversation).toEqual({
            id: 7,
            agentSpaceKey: 3,
            runState: "IDLE",
            agentTurnOwedAt: null,
            title: "Visits",
            titleManuallyEdited: true,
            summary: "About visits",
            created: "2026-11-01 10:00:00",
            updated: "2026-11-02 10:00:00",
            purposeName: null,
            initiatorType: "SCHEDULE",
            scheduleId: 5,
            entryPointKey: null,
            progressSteps: [{ key: "a", label: "First", status: "active" }],
        });
    });
    test("lists the root conversations of an agent space newest first, without untitled ones unless asked", async () => {
        const conversations = {
            aiConversations: { elements: [{ id: 2, summary: { title: "Titled" } }, { id: 1, summary: null }] },
        };
        const { client, requests } = buildClient([{ body: conversations }, { body: conversations }]);
        const titled = await client.listConversations(3, { paging: { top: 50, skip: 0 } });
        const all = await client.listConversations(0, { includeUnsummarized: true });
        expect(requests[0].url.pathname).toBe("/api/agents/conversations/listAllConversations");
        expect(requests[0].url.searchParams.get("filters")).toBe("rootConversationId eq null and agentSpaceId eq '3'");
        expect(requests[0].url.searchParams.get("orderBy")).toBe("id desc");
        expect(requests[0].url.searchParams.get("top")).toBe("50");
        expect(requests[1].url.searchParams.get("filters")).toBe("rootConversationId eq null");
        expect(titled.map((conversation) => conversation.id)).toEqual([2]);
        expect(all.map((conversation) => conversation.id)).toEqual([2, 1]);
    });
    test("starts at /start without an agent space and at /start/{key} with one; a reopened start has no messages", async () => {
        const { client, requests } = buildClient([
            { body: { aiConversation: { id: 8, messages: { elements: [wireMessage] } }, reopenedExistingConversation: false } },
            { body: { aiConversation: { id: 9, messages: { elements: [wireMessage] } }, reopenedExistingConversation: true } },
        ]);
        const started = await client.startConversation({ agentSpaceKey: 0, text: "Hi", model: "model-a" });
        const reopened = await client.startConversation({ agentSpaceKey: 4, text: "", startSignalText: "Begin", entryPointKey: "hub.a" });
        expect(requests[0].method).toBe("POST");
        expect(requests[0].url.pathname).toBe("/api/agents/conversations/start");
        expect(requests[0].url.searchParams.get("aiModelName")).toBe("model-a");
        expect(requests[0].body).toEqual({ aiConversationMessage: { message: "Hi" } });
        expect(requests[1].url.pathname).toBe("/api/agents/conversations/start/4");
        expect(requests[1].body).toEqual({ aiConversationMessage: { message: "" }, signalText: "Begin", entryPointKey: "hub.a" });
        expect(started.messages).toHaveLength(1);
        expect(started.reopenedExisting).toBe(false);
        expect(reopened).toMatchObject({ reopenedExisting: true, messages: [] });
    });
    test("a purposed start sends the purpose, the space, the subject and the prefill and answers the conversation id", async () => {
        const { client, requests } = buildClient([
            { body: { aiConversationId: 31, resumedExistingConversation: false, prefillDelivered: true } },
            { body: { aiConversationId: 31, resumedExistingConversation: true, prefillDelivered: false } },
            { body: { aiConversationId: 32, resumedExistingConversation: false, prefillDelivered: false } },
        ]);
        const started = await client.startPurpose?.({
            purposeId: "Support.AnswerTicket",
            agentSpaceKey: 4,
            subject: { entityClass: "App\\Domain\\Support\\Entities\\Tickets\\SupportTicket", entityId: 13082 },
            prefill: "Ticket #13082",
        });
        const reopened = await client.startPurpose?.({
            purposeId: "Support.AnswerTicket",
            agentSpaceKey: 4,
            subject: { entityClass: "App\\Domain\\Support\\Entities\\Tickets\\SupportTicket", entityId: 13082 },
        });
        await client.startPurpose?.({ purposeId: "Support.Inbox" });
        expect(requests[0].method).toBe("POST");
        expect(requests[0].url.pathname).toBe("/api/agents/conversations/startPurpose");
        expect(requests[0].body).toEqual({
            purposeId: "Support.AnswerTicket",
            agentSpaceId: 4,
            subject: { subjectEntityClass: "App\\Domain\\Support\\Entities\\Tickets\\SupportTicket", subjectEntityId: 13082 },
            prefill: "Ticket #13082",
        });
        expect(requests[2].body).toEqual({ purposeId: "Support.Inbox" });
        expect(started).toEqual({ conversationId: 31, resumedExisting: false, prefillDelivered: true });
        expect(reopened).toEqual({ conversationId: 31, resumedExisting: true, prefillDelivered: false });
    });
    test("a start with a subject names the record; the record's conversations are listed newest first; a conversation's subject is read", async () => {
        const { client, requests } = buildClient([
            { body: { aiConversation: { id: 7 } } },
            {
                body: {
                    aiConversations: { elements: [{ id: 9, summary: { title: "Budget" } }, { id: 8 }] },
                    subject: {
                        subjectEntityClass: "App\\Domain\\Shop\\Entities\\Orders\\Order",
                        subjectEntityId: 10683,
                        name: "Spring order 2026",
                        link: "/orders/10683",
                    },
                },
            },
            {
                body: {
                    subject: {
                        subjectEntityClass: "App\\Domain\\Shop\\Entities\\Orders\\Order",
                        subjectEntityId: 10683,
                        name: "Spring order 2026",
                        link: "/orders/10683",
                        purposeName: null,
                    },
                },
            },
            { body: { subject: null } },
            {
                body: {
                    subjects: {
                        elements: [
                            {
                                aiConversationId: 9,
                                subjectEntityClass: "App\\Domain\\Shop\\Entities\\Orders\\Order",
                                subjectEntityId: 10683,
                                name: "Spring order 2026",
                                link: "/orders/10683",
                            },
                            { aiConversationId: 7, subjectEntityClass: "", subjectEntityId: 0 },
                        ],
                    },
                },
            },
        ]);
        const subject = { entityClass: "App\\Domain\\Shop\\Entities\\Orders\\Order", entityId: 10683 };
        await client.startConversation({ agentSpaceKey: 0, text: "How is it going?", subject });
        const conversationsOfSubject = await client.listConversationsOfSubject?.(subject, { paging: { top: 20 } });
        const bound = await client.getConversationSubject?.(9);
        const unbound = await client.getConversationSubject?.(8);
        const subjectsOfThePage = await client.listSubjectsOfConversations?.(0, { paging: { top: 50 } });
        expect(requests[0].body).toMatchObject({
            subject: { subjectEntityClass: "App\\Domain\\Shop\\Entities\\Orders\\Order", subjectEntityId: 10683 },
        });
        expect(requests[1].method).toBe("GET");
        expect(requests[1].url.pathname).toBe("/api/agents/conversations/listConversationsOfSubject");
        expect(requests[1].url.searchParams.get("subjectEntityClass")).toBe("App\\Domain\\Shop\\Entities\\Orders\\Order");
        expect(requests[1].url.searchParams.get("subjectEntityId")).toBe("10683");
        expect(requests[1].url.searchParams.get("orderBy")).toBe("id desc");
        expect(requests[1].url.searchParams.get("top")).toBe("20");
        expect(conversationsOfSubject?.conversations.map((conversation) => conversation.id)).toEqual([9]);
        // the record itself as the application names it now, also before a conversation about it exists
        expect(conversationsOfSubject?.subject).toEqual({
            conversationId: null,
            entityClass: "App\\Domain\\Shop\\Entities\\Orders\\Order",
            entityId: 10683,
            name: "Spring order 2026",
            link: "/orders/10683",
            purposeId: null,
        });
        expect(requests[2].url.pathname).toBe("/api/agents/conversations/9/subject");
        expect(bound).toEqual({
            conversationId: null,
            entityClass: "App\\Domain\\Shop\\Entities\\Orders\\Order",
            entityId: 10683,
            name: "Spring order 2026",
            link: "/orders/10683",
            purposeId: null,
        });
        expect(unbound).toBeNull();
        // the records of a page of the list: the list's own query, one entry per bound conversation
        expect(requests[4].url.pathname).toBe("/api/agents/conversations/listSubjectsOfConversations");
        expect(requests[4].url.searchParams.get("filters")).toBe("rootConversationId eq null");
        expect(requests[4].url.searchParams.get("orderBy")).toBe("id desc");
        expect(requests[4].url.searchParams.get("top")).toBe("50");
        expect(subjectsOfThePage).toEqual([
            {
                conversationId: 9,
                entityClass: "App\\Domain\\Shop\\Entities\\Orders\\Order",
                entityId: 10683,
                name: "Spring order 2026",
                link: "/orders/10683",
                purposeId: null,
            },
        ]);
    });
    test("a purposed start without a conversation id in the answer is refused", async () => {
        const { client } = buildClient([{ body: {} }]);
        await expect(client.startPurpose?.({ purposeId: "Support.AnswerTicket" })).rejects.toThrow("no conversation id");
    });
    test("a post carries its attachments in the text, the model and the host's extra parameters", async () => {
        const { client, requests } = buildClient([{ body: { aiConversation: { id: 7, messages: [wireMessage] } } }], {
            extraRequestParameters: (operation) => (operation === "postMessage" ? { partition: "side" } : {}),
        });
        const posted = await client.postMessage(7, {
            text: "See this",
            model: "model-b",
            attachments: [{ fileId: 1, fileName: "a.png", publicUrl: "https://files.test/a.png" }],
        });
        expect(requests[0].url.pathname).toBe("/api/agents/conversations/7/postMessage");
        expect(requests[0].url.searchParams.get("aiModelName")).toBe("model-b");
        expect(requests[0].url.searchParams.get("partition")).toBe("side");
        expect(requests[0].body).toEqual({
            aiConversationMessage: { message: "See this\n\n[Attached file: a.png — https://files.test/a.png]" },
        });
        expect(client.composeSentMessageText?.("See this", [{ fileId: 1, fileName: "a.png", publicUrl: "https://files.test/a.png" }])).toBe("See this\n\n[Attached file: a.png — https://files.test/a.png]");
        expect(posted.messages[0].id).toBe(41);
    });
    test("a start, a post and a signal carry the host's client context; the other routes and a host without one carry none", async () => {
        const operationsAsked = [];
        const { client, requests } = buildClient([{ body: { aiConversation: { id: 7 } } }, { body: {} }, { body: {} }, { body: {} }], {
            clientContext: (operation) => {
                operationsAsked.push(operation);
                return { routePath: "/tickets/42", routeParameters: { ticketId: "42", view: "compact" }, recordKind: "ticket", recordId: 42, tab: " details " };
            },
        });
        await client.startConversation({ agentSpaceKey: 0, text: "What is this?" });
        await client.postMessage(7, { text: "And this?" });
        await client.injectSignal(7, { text: "Opened the ticket" });
        await client.retryConversation(7);
        const expectedClientContext = {
            routePath: "/tickets/42",
            routeParameters: [
                { name: "ticketId", value: "42" },
                { name: "view", value: "compact" },
            ],
            recordKind: "ticket",
            recordId: "42",
            tab: "details",
        };
        expect(requests[0].body.clientContext).toEqual(expectedClientContext);
        expect(requests[1].body.clientContext).toEqual(expectedClientContext);
        expect(requests[2].body.clientContext).toEqual(expectedClientContext);
        expect(requests[3].body).toBeUndefined();
        expect(operationsAsked).toEqual(["startConversation", "postMessage", "injectSignal"]);
        const { client: clientWithoutContext, requests: requestsWithoutContext } = buildClient([{ body: {} }]);
        await clientWithoutContext.postMessage(7, { text: "x" });
        expect(requestsWithoutContext[0].body).toEqual({ aiConversationMessage: { message: "x" } });
    });
    test("a client-context field over its cap or with a control character is left out, never cut", () => {
        const longPath = `/${"a".repeat(AGENTS_CLIENT_CONTEXT_LIMITS.routePathChars)}`;
        const manyParameters = Object.fromEntries(Array.from({ length: AGENTS_CLIENT_CONTEXT_LIMITS.routeParameterCount + 1 }, (_, position) => [`p${position}`, "1"]));
        expect(mapClientContextToWire({ routePath: longPath, tab: "details" })).toEqual({ tab: "details" });
        expect(mapClientContextToWire({ routePath: "/x", routeParameters: manyParameters })).toEqual({ routePath: "/x" });
        expect(mapClientContextToWire({ routeParameters: { query: "q".repeat(510) } })).toBeUndefined();
        expect(mapClientContextToWire({ recordKind: "tic\nket", recordId: "7" })).toEqual({ recordId: "7" });
        expect(mapClientContextToWire({ routePath: "  ", routeParameters: {} })).toBeUndefined();
        expect(mapClientContextToWire(null)).toBeUndefined();
        expect(mapClientContextToWire({ routePath: `/${"a".repeat(AGENTS_CLIENT_CONTEXT_LIMITS.routePathChars - 1)}` })?.routePath).toHaveLength(AGENTS_CLIENT_CONTEXT_LIMITS.routePathChars);
    });
    test("signal, retry, title, delete and the searches use their routes", async () => {
        const { client, requests } = buildClient([
            { body: {} },
            { body: {} },
            { body: { aiConversation: { id: 7, summary: { title: "New", manuallyEdited: true } } } },
            { body: { message: "Entity deleted" } },
            { body: { conversations: [{ conversationId: 7, title: "T", relevance: 0.5, hits: [{ messageId: 1, excerpt: "x", relevance: 0.4 }] }] } },
            { body: { hits: [{ messageId: 2, excerpt: "y", relevance: 0.3 }] } },
            { body: { turnMessageId: 40, turnRunning: true, elements: { elements: [{ toolCallMessageId: 41, state: "running", kind: "tool", line: "Reading" }] } } },
        ]);
        await client.injectSignal(7, { text: "Clicked B", source: "FRONTEND_WIDGET_CLICK" });
        await client.retryConversation(7);
        const renamed = await client.renameConversation(7, "New");
        await client.deleteConversation(7);
        const groups = await client.searchConversations("visits", { limit: 10, excerptLength: 420 });
        const hits = await client.searchConversationMessages(7, "visits");
        const activity = await client.getConversationActivity(7, 40);
        expect(requests.map((request) => `${request.method} ${request.url.pathname}`)).toEqual([
            "POST /api/agents/conversations/7/injectSignal",
            "POST /api/agents/conversations/7/retry",
            "PATCH /api/agents/conversations/7/title",
            "DELETE /api/agents/conversations/7",
            "GET /api/agents/conversations/search",
            "GET /api/agents/conversations/7/messages/search",
            "GET /api/agents/conversations/7/activity",
        ]);
        expect(requests[0].body).toEqual({ signalText: "Clicked B", signalSource: "FRONTEND_WIDGET_CLICK" });
        expect(requests[2].body).toEqual({ title: "New" });
        expect(requests[4].url.searchParams.get("query")).toBe("visits");
        expect(requests[4].url.searchParams.get("limit")).toBe("10");
        expect(requests[4].url.searchParams.get("excerptChars")).toBe("420");
        expect(requests[6].url.searchParams.get("turnMessageId")).toBe("40");
        expect(renamed).toMatchObject({ title: "New", titleManuallyEdited: true });
        expect(groups[0].hits[0]).toMatchObject({ messageId: 1, excerpt: "x", relevance: 0.4 });
        expect(hits[0]).toMatchObject({ messageId: 2, excerpt: "y" });
        expect(activity).toEqual({
            turnMessageId: 40,
            turnRunning: true,
            elements: [
                { toolCallMessageId: 41, batchId: null, state: "running", kind: "tool", childCount: null, helperIndex: null, line: "Reading" },
            ],
        });
    });
    test("reads the run state and offers the model picker only for a controller with its route", async () => {
        const { client, requests } = buildClient([
            { body: { id: 7, runState: "RUNNING", agentTurnOwedAt: null, lastMessageId: 41, title: "T", titleManuallyEdited: false, summary: null, updated: "u" } },
            { body: { models: { elements: [{ name: "m", displayName: "M", description: "d", strengthLevel: 2, speedLevel: 3, creditsMultiplier: 1.5 }] } } },
        ], { hasSelectableModelsRoute: true });
        const withoutModels = buildClient([]).client;
        const runState = await client.getConversationRunState?.(7);
        const models = await client.getSelectableModels?.();
        expect(requests[0].url.pathname).toBe("/api/agents/conversations/7/runState");
        expect(runState).toEqual({ id: 7, runState: "RUNNING", agentTurnOwedAt: null, lastMessageId: 41, title: "T", titleManuallyEdited: false, summary: null, updated: "u" });
        expect(requests[1].url.pathname).toBe("/api/agents/conversations/selectable-models");
        expect(models).toEqual([{ name: "m", displayName: "M", description: "d", strengthLevel: 2, speedLevel: 3, creditsMultiplier: 1.5 }]);
        expect(withoutModels.getSelectableModels).toBeUndefined();
    });
    test("reads the account-level realtime messages with the UI client's id, also through the polling default", async () => {
        const uiClient = { clientId: "client-a", readUIState: () => null, acceptClientToolRequest: vi.fn() };
        const { client, requests } = buildClient([
            {
                body: {
                    realtimeMessages: { elements: [{ messageType: "demo.voice.ring", messageKey: "call-1", payloadJson: "{}", secondsLeft: 20 }] },
                    realtimeListenerCredential: { transportName: "fake", credential: "token-of-3", listenPath: "accounts/3" },
                },
            },
        ], { uiClient, polling: {} });
        const read = await client.getAccountRealtimeMessages?.();
        expect(requests[0].method).toBe("GET");
        expect(requests[0].url.pathname).toBe("/api/agents/conversations/realtimeMessages");
        expect(requests[0].url.searchParams.get("clientId")).toBe("client-a");
        expect(read).toEqual({
            messages: [{ messageType: "demo.voice.ring", messageKey: "call-1", payloadJson: "{}", secondsLeft: 20 }],
            listenerCredential: { transportName: "fake", credential: "token-of-3", listenPath: "accounts/3" },
        });
        expect(uiClient.acceptClientToolRequest).not.toHaveBeenCalled();
    });
    test("every failure rejects with an AgentsClientError of the matching kind", async () => {
        const { client } = buildClient([
            { status: 404, body: { error: "AIConversation 7 not found." } },
            { status: 400, body: { error: "closed", errorCode: "CONVERSATION_CLOSED" } },
            { status: 429, body: { error: "Rate limit exceeded." }, headers: { "Retry-After": "30" } },
            { status: 403, body: { error: "No access" } },
            { status: 500, body: "oops" },
        ]);
        const rejections = await Promise.all([
            () => client.getConversationMetadata(7),
            () => client.postMessage(7, { text: "x" }),
            () => client.postMessage(7, { text: "x" }),
            () => client.getConversationMetadata(7),
            () => client.getConversationMetadata(7),
        ].map(async (call) => call().catch((failure) => failure)));
        expect(rejections.every((rejection) => rejection instanceof AgentsClientError)).toBe(true);
        expect(rejections.map((rejection) => rejection.kind)).toEqual([
            "notFound",
            "closed",
            "rateLimit",
            "forbidden",
            "network",
        ]);
        expect(rejections[2].retryAfterSeconds).toBe(30);
        expect(rejections[1].code).toBe("CONVERSATION_CLOSED");
    });
    test("a transport failure is a network error and a cancelled request carries REQUEST_ABORTED", async () => {
        const failingFetch = vi
            .fn()
            .mockRejectedValueOnce(new TypeError("Failed to fetch"))
            .mockRejectedValueOnce(Object.assign(new Error("aborted"), { name: "AbortError" }));
        const client = createAgentsHttpConversationClient({ baseUrl: "/c", fetch: failingFetch, polling: false });
        const networkFailure = await client.getConversationMetadata(1).catch((failure) => failure);
        const abortedFailure = await client.getConversationMetadata(1).catch((failure) => failure);
        if (!(networkFailure instanceof AgentsClientError))
            throw new Error("the transport failure did not reject with an AgentsClientError");
        expect(networkFailure.kind).toBe("network");
        expect(abortedFailure).toMatchObject({ kind: "other", code: "REQUEST_ABORTED" });
    });
    test("reads the fetch options per request and sends JSON", async () => {
        let token = "first";
        const { client, requests } = buildClient([{ body: {} }, { body: {} }], {
            fetchOptions: () => ({ headers: { Authorization: `Bearer ${token}` }, credentials: "include" }),
        });
        await client.retryConversation(1);
        token = "second";
        await client.retryConversation(1);
        expect(requests[0].headers.get("Authorization")).toBe("Bearer first");
        expect(requests[1].headers.get("Authorization")).toBe("Bearer second");
        expect(requests[0].headers.get("Accept")).toBe("application/json");
    });
    test("comes with live updates polled over the run-state read", async () => {
        const { fetchFunction, requests } = createScriptedFetch([
            { body: { id: 7, runState: "IDLE", lastMessageId: 41, title: null, summary: null, updated: "u" } },
        ]);
        const client = createAgentsHttpConversationClient({
            baseUrl: "/c",
            fetch: fetchFunction,
            polling: { documentVisibility: null },
        });
        const onSignal = vi.fn();
        const unsubscribe = client.subscribeToConversationChanges?.(7, onSignal, vi.fn());
        await vi.waitFor(() => expect(onSignal).toHaveBeenCalledTimes(1));
        unsubscribe?.();
        expect(requests[0].url.pathname).toBe("/c/7/runState");
        expect(onSignal).toHaveBeenCalledWith({ changeCounter: 0, lastMessageId: 41, runStatus: "idle", summaryUpdatedAt: 0 });
    });
});
describe("the client id of the account read", () => {
    const boundUIClient = () => ({ clientId: "client-a", readUIState: () => null, acceptClientToolRequest: vi.fn() });
    const accountReadClientIdOf = (request) => {
        expect(request.url.pathname).toBe("/api/agents/conversations/realtimeMessages");
        return request.url.searchParams.get("clientId");
    };
    test("is the bound UI client's id", async () => {
        const { client, requests } = buildClient([], { uiClient: boundUIClient() });
        await client.getAccountRealtimeMessages?.();
        await client.getAccountRealtimeMessages?.("");
        expect(requests.map(accountReadClientIdOf)).toEqual(["client-a", "client-a"]);
    });
    test("without a UI client is an id the client creates once and keeps; another client instance creates another", async () => {
        const { client, requests } = buildClient([]);
        const { client: otherClient, requests: otherRequests } = buildClient([]);
        await client.getAccountRealtimeMessages?.();
        await client.getAccountRealtimeMessages?.();
        await otherClient.getAccountRealtimeMessages?.();
        const [firstId, secondId] = requests.map(accountReadClientIdOf);
        const otherId = accountReadClientIdOf(otherRequests[0]);
        expect(firstId).toMatch(/^ui-.+/);
        expect(secondId).toBe(firstId);
        expect(otherId).toMatch(/^ui-.+/);
        expect(otherId).not.toBe(firstId);
    });
    test("is the id the caller hands in, over the bound UI client's and over the created one; an empty id counts as none", async () => {
        const bound = buildClient([], { uiClient: boundUIClient() });
        await bound.client.getAccountRealtimeMessages?.("client-given");
        const unbound = buildClient([]);
        await unbound.client.getAccountRealtimeMessages?.();
        await unbound.client.getAccountRealtimeMessages?.("client-given");
        await unbound.client.getAccountRealtimeMessages?.("");
        expect(accountReadClientIdOf(bound.requests[0])).toBe("client-given");
        const [createdId, givenId, emptyId] = unbound.requests.map(accountReadClientIdOf);
        expect(givenId).toBe("client-given");
        expect(createdId).toMatch(/^ui-.+/);
        expect(emptyId).toBe(createdId);
    });
    test("is the poll's clientId option, which the poll hands to the read", async () => {
        vi.useFakeTimers();
        try {
            const { client, requests } = buildClient([], { uiClient: boundUIClient() });
            const readAccountRealtime = client.getAccountRealtimeMessages;
            if (readAccountRealtime === undefined)
                throw new Error("the HTTP client has no account read");
            const poll = createAccountRealtimePoll({ readAccountRealtime, clientId: "client-of-the-poll", isTransportBound: () => true });
            await vi.advanceTimersByTimeAsync(0);
            await poll.readNow();
            poll.stop();
            expect(requests.map(accountReadClientIdOf)).toEqual(["client-of-the-poll", "client-of-the-poll"]);
        }
        finally {
            vi.useRealTimers();
        }
    });
    test("does not reach the conversation routes without a UI client, also after the account read created it", async () => {
        const { client, requests } = buildClient([
            { body: {} },
            { body: { id: 7, runState: "IDLE", lastMessageId: 41, title: null, summary: null, updated: "u" } },
            { body: { aiConversation: { id: 7 } } },
            { body: { messages: { elements: [] }, hasMoreOlder: false } },
            { body: { aiConversation: { id: 7, messages: [] } } },
            { body: {} },
        ]);
        await client.getAccountRealtimeMessages?.();
        await client.getConversationRunState?.(7);
        await client.getConversationMetadata(7);
        await client.getNewestMessagesPage(7, 20);
        await client.postMessage(7, { text: "x" });
        await client.injectSignal(7, { text: "Opened the ticket" });
        expect(accountReadClientIdOf(requests[0])).toMatch(/^ui-.+/);
        for (const request of requests.slice(1)) {
            expect(request.url.searchParams.has("clientId"), request.url.pathname).toBe(false);
            expect(JSON.stringify(request.body ?? {}), request.url.pathname).not.toContain("clientId");
        }
        expect(requests).toHaveLength(6);
    });
    test("reaches the wrapped client through the polling default", async () => {
        const { client, requests } = buildClient([], { polling: { documentVisibility: null } });
        await client.getAccountRealtimeMessages?.("client-given");
        expect(accountReadClientIdOf(requests[0])).toBe("client-given");
    });
});
