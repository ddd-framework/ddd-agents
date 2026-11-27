import { AGENTS_CLIENT_ERROR_CODE_REQUEST_ABORTED, AgentsClientError, } from "../../contract/conversation";
import { createAgentUIClientId } from "../../ui/agent-ui-state";
import { acceptArrivedRealtimeMessage, } from "../../ui/realtime-arrival";
import { composeAttachmentMessageText } from "../compose-attachment-message-text";
import { withPollingChangeSubscription } from "../polling/withPollingChangeSubscription";
import { mapClientContextToWire, mapHttpFailureToAgentsClientError, mapWireBoundConversationSubject, mapWireConversation, mapWireConversationActivity, mapWireConversationMessage, mapWireConversationRunState, mapWireSearchGroup, mapWireSearchHit, mapWireSelectableModel, readWireSetElements, } from "./agents-http-mappers";
/** The page size of a changed-messages read when the request names none (as the chat sends it). */
export const AGENTS_HTTP_DEFAULT_CHANGED_MESSAGES_PAGE_SIZE = 50;
/** The expand of every message page: the delegated children with the two fields the chat reads. */
const MESSAGES_EXPAND = "delegatedConversations(select=id,runState)";
const defaultBuildStartPath = (agentSpaceKey) => (agentSpaceKey > 0 ? `/start/${agentSpaceKey}` : "/start");
/** A filter value as the query-options grammar writes it: quoted. */
const quoteFilterValue = (value) => `'${String(value).replace(/'/g, "\\'")}'`;
/** The module's conversation routes over fetch: one request per port function, the wire mapped onto the contract. */
class AgentsHttpConversationClient {
    options;
    getSelectableModels;
    /** The client id this client created for its account read (no `uiClient` bound): once, kept while it lives. */
    ownAccountReadClientId;
    constructor(options) {
        this.options = options;
        if (options.hasSelectableModelsRoute)
            this.getSelectableModels = this.readSelectableModels;
    }
    // ── Reads ─────────────────────────────────────────────────────────────────────────────────────────────────────
    getConversationMetadata = async (conversationId) => {
        const response = await this.request({
            operation: "getConversationMetadata",
            method: "GET",
            path: `/${conversationId}`,
        });
        return mapWireConversation(response.aiConversation ?? {});
    };
    getConversationRunState = async (conversationId) => {
        const uiClient = this.options.uiClient;
        const uiState = uiClient?.readUIState() ?? null;
        const response = await this.request({
            operation: "getConversationRunState",
            method: "GET",
            path: `/${conversationId}/runState`,
            query: uiClient ? { clientId: uiClient.clientId, uiStateJson: uiState ? JSON.stringify(uiState) : undefined } : undefined,
        });
        const sendAnswer = (requestId, answer) => this.answerClientToolRequest(conversationId, requestId, answer);
        if (uiClient && response.clientToolRequest) {
            uiClient.acceptClientToolRequest(conversationId, response.clientToolRequest, sendAnswer);
        }
        for (const message of readWireSetElements(response.realtimeMessages)) {
            if (uiClient)
                acceptArrivedRealtimeMessage(conversationId, message, uiClient, sendAnswer, this.options.onRealtimeMessage);
            else
                this.options.onRealtimeMessage?.(conversationId, message);
        }
        this.options.onRealtimeListenerCredential?.(conversationId, response.realtimeListenerCredential ?? null);
        return mapWireConversationRunState(response);
    };
    /**
     * The account-level realtime read (`GET /realtimeMessages?clientId=…`): the messages addressed to the signed-in
     * account for this client and the listener credential without a conversation. It always states a client id
     * (`resolveAccountReadClientId`), so the server knows a client that only polls. Arrival is the caller's
     * (`createAccountRealtimePoll` of `/ui`).
     *
     * @param clientId the client id the caller wants stated (the `clientId` option of the poll)
     */
    getAccountRealtimeMessages = async (clientId) => {
        const response = await this.request({
            operation: "getAccountRealtimeMessages",
            method: "GET",
            path: "/realtimeMessages",
            query: { clientId: this.resolveAccountReadClientId(clientId) },
        });
        return {
            messages: readWireSetElements(response.realtimeMessages),
            listenerCredential: response.realtimeListenerCredential ?? null,
        };
    };
    /**
     * The client id the account read states, decided here and nowhere else: the caller's, else the bound `uiClient`'s,
     * else one this client creates once and keeps while it lives. Only the account read asks: every other request
     * states a client id only with a `uiClient`.
     */
    resolveAccountReadClientId(clientId) {
        if (clientId)
            return clientId;
        if (this.options.uiClient)
            return this.options.uiClient.clientId;
        this.ownAccountReadClientId ??= createAgentUIClientId();
        return this.ownAccountReadClientId;
    }
    /** Posts the answer of the UI client to a client tool request (`Agent.UI`); a refused answer is the server's word. */
    answerClientToolRequest = async (conversationId, requestId, answer) => {
        await this.request({
            operation: "answerClientToolRequest",
            method: "POST",
            path: `/${conversationId}/clientToolRequests/${requestId}/answer`,
            body: {
                clientId: this.options.uiClient?.clientId ?? "",
                outcome: answer.outcome,
                text: answer.text,
                detailsJson: JSON.stringify(answer.details ?? {}),
                pictureBase64: answer.pictureBase64,
                uiStateJson: this.readUIStateJsonAfterAnswer(),
            },
        });
    };
    /** What the UI shows after the request was carried out, so the server's `[SIGNAL:ui]` note is current at once. */
    readUIStateJsonAfterAnswer() {
        const uiState = this.options.uiClient?.readUIState() ?? null;
        return uiState ? JSON.stringify(uiState) : undefined;
    }
    getNewestMessagesPage = (conversationId, pageSize) => this.readMessagesPage("getNewestMessagesPage", conversationId, pageSize, undefined);
    getOlderMessagesPage = (conversationId, beforeIndex, pageSize) => this.readMessagesPage("getOlderMessagesPage", conversationId, pageSize, `indexInConversation lt ${quoteFilterValue(beforeIndex)}`);
    getChangedMessages = async (conversationId, request) => {
        const page = await this.readMessagesPage("getChangedMessages", conversationId, request.pageSize ?? AGENTS_HTTP_DEFAULT_CHANGED_MESSAGES_PAGE_SIZE, `(id gt ${quoteFilterValue(request.highestKnownId)} or indexInConversation ge ${quoteFilterValue(request.fromIndex)})`);
        return { messages: page.messages };
    };
    /** The query of the root conversations list (of one space, or of all), newest first: shared by the list and the read of its records. */
    buildConversationsListQuery(agentSpaceKey, options) {
        const filters = ["rootConversationId eq null"];
        if (agentSpaceKey > 0)
            filters.push(`agentSpaceId eq ${quoteFilterValue(agentSpaceKey)}`);
        return {
            filters: filters.join(" and "),
            orderBy: "id desc",
            top: options.paging?.top,
            skip: options.paging?.skip,
        };
    }
    listConversations = async (agentSpaceKey, options = {}) => {
        const response = await this.request({
            operation: "listConversations",
            method: "GET",
            path: "/listAllConversations",
            query: this.buildConversationsListQuery(agentSpaceKey, options),
        });
        const conversations = readWireSetElements(response.aiConversations).map(mapWireConversation);
        // Without a summary there is no title yet; the list leaves such a conversation out unless asked for it.
        return options.includeUnsummarized ? conversations : conversations.filter((conversation) => conversation.title !== null);
    };
    listConversationsOfSubject = async (subject, options = {}) => {
        const response = await this.request({
            operation: "listConversationsOfSubject",
            method: "GET",
            path: "/listConversationsOfSubject",
            query: {
                subjectEntityClass: subject.entityClass,
                subjectEntityId: subject.entityId,
                filters: "rootConversationId eq null",
                orderBy: "id desc",
                top: options.paging?.top,
                skip: options.paging?.skip,
            },
        });
        const conversations = readWireSetElements(response.aiConversations).map(mapWireConversation);
        return {
            subject: mapWireBoundConversationSubject(response.subject),
            conversations: options.includeUnsummarized ? conversations : conversations.filter((conversation) => conversation.title !== null),
        };
    };
    listSubjectsOfConversations = async (agentSpaceKey, options = {}) => {
        const response = await this.request({
            operation: "listSubjectsOfConversations",
            method: "GET",
            path: "/listSubjectsOfConversations",
            query: this.buildConversationsListQuery(agentSpaceKey, options),
        });
        return readWireSetElements(response.subjects)
            .map(mapWireBoundConversationSubject)
            .filter((subject) => subject !== null);
    };
    getConversationSubject = async (conversationId) => {
        const response = await this.request({
            operation: "getConversationSubject",
            method: "GET",
            path: `/${conversationId}/subject`,
        });
        return mapWireBoundConversationSubject(response.subject);
    };
    searchConversations = async (text, options = {}) => {
        const response = await this.request({
            operation: "searchConversations",
            method: "GET",
            path: "/search",
            query: { query: text, limit: options.limit, excerptChars: options.excerptLength },
        });
        return readWireSetElements(response.conversations).map(mapWireSearchGroup);
    };
    searchConversationMessages = async (conversationId, text, options = {}) => {
        const response = await this.request({
            operation: "searchConversationMessages",
            method: "GET",
            path: `/${conversationId}/messages/search`,
            query: { query: text, limit: options.limit, excerptChars: options.excerptLength },
        });
        return readWireSetElements(response.hits).map(mapWireSearchHit);
    };
    getConversationActivity = async (conversationId, turnMessageId) => {
        const response = await this.request({
            operation: "getConversationActivity",
            method: "GET",
            path: `/${conversationId}/activity`,
            query: { turnMessageId: turnMessageId ?? undefined },
        });
        return mapWireConversationActivity(response);
    };
    composeSentMessageText = (text, attachments) => composeAttachmentMessageText(text, attachments);
    // ── Writes ────────────────────────────────────────────────────────────────────────────────────────────────────
    startConversation = async (request) => {
        const buildStartPath = this.options.buildStartPath ?? defaultBuildStartPath;
        const response = await this.request({
            operation: "startConversation",
            method: "POST",
            path: buildStartPath(request.agentSpaceKey),
            query: { aiModelName: request.model },
            body: {
                aiConversationMessage: { message: composeAttachmentMessageText(request.text, request.attachments) },
                ...(request.startSignalText ? { signalText: request.startSignalText } : {}),
                ...(request.entryPointKey ? { entryPointKey: request.entryPointKey } : {}),
                ...(request.subject
                    ? { subject: { subjectEntityClass: request.subject.entityClass, subjectEntityId: request.subject.entityId } }
                    : {}),
                ...this.readClientContextBody("startConversation"),
            },
        });
        const wireConversation = response.aiConversation ?? {};
        const reopenedExisting = response.reopenedExistingConversation === true;
        return {
            conversation: mapWireConversation(wireConversation),
            messages: reopenedExisting ? [] : readWireSetElements(wireConversation.messages).map(mapWireConversationMessage),
            reopenedExisting,
        };
    };
    startPurpose = async (request) => {
        const response = await this.request({
            operation: "startPurpose",
            method: "POST",
            path: "/startPurpose",
            body: {
                purposeId: request.purposeId,
                ...(request.agentSpaceKey ? { agentSpaceId: request.agentSpaceKey } : {}),
                ...(request.subject
                    ? { subject: { subjectEntityClass: request.subject.entityClass, subjectEntityId: request.subject.entityId } }
                    : {}),
                ...(request.prefill ? { prefill: request.prefill } : {}),
            },
        });
        const conversationId = Number(response.aiConversationId ?? 0);
        if (!Number.isInteger(conversationId) || conversationId < 1) {
            throw new AgentsClientError("other", { message: "The purposed start answered no conversation id." });
        }
        return {
            conversationId,
            resumedExisting: response.resumedExistingConversation === true,
            prefillDelivered: response.prefillDelivered === true,
        };
    };
    postMessage = async (conversationId, message) => {
        const response = await this.request({
            operation: "postMessage",
            method: "POST",
            path: `/${conversationId}/postMessage`,
            query: { aiModelName: message.model },
            body: {
                aiConversationMessage: { message: composeAttachmentMessageText(message.text, message.attachments) },
                ...this.readClientContextBody("postMessage"),
            },
        });
        return { messages: readWireSetElements(response.aiConversation?.messages).map(mapWireConversationMessage) };
    };
    injectSignal = async (conversationId, signal) => {
        await this.request({
            operation: "injectSignal",
            method: "POST",
            path: `/${conversationId}/injectSignal`,
            body: {
                signalText: signal.text,
                ...(signal.source ? { signalSource: signal.source } : {}),
                ...this.readClientContextBody("injectSignal"),
            },
        });
    };
    retryConversation = async (conversationId) => {
        await this.request({
            operation: "retryConversation",
            method: "POST",
            path: `/${conversationId}/retry`,
        });
    };
    renameConversation = async (conversationId, title) => {
        const response = await this.request({
            operation: "renameConversation",
            method: "PATCH",
            path: `/${conversationId}/title`,
            body: { title },
        });
        return mapWireConversation(response.aiConversation ?? {});
    };
    deleteConversation = async (conversationId) => {
        await this.request({ operation: "deleteConversation", method: "DELETE", path: `/${conversationId}` });
    };
    // ── Transport ─────────────────────────────────────────────────────────────────────────────────────────────────
    readSelectableModels = async () => {
        const response = await this.request({
            operation: "getSelectableModels",
            method: "GET",
            path: "/selectable-models",
        });
        return readWireSetElements(response.models).map(mapWireSelectableModel);
    };
    async readMessagesPage(operation, conversationId, pageSize, filters) {
        const response = await this.request({
            operation,
            method: "GET",
            path: `/${conversationId}/messages`,
            query: { filters, orderBy: "indexInConversation desc", top: pageSize, expand: MESSAGES_EXPAND },
        });
        return {
            messages: readWireSetElements(response.messages).map(mapWireConversationMessage),
            hasMoreOlder: response.hasMoreOlder === true,
        };
    }
    /** The body field `clientContext` of a write, from the host's option; nothing when it gives none. */
    readClientContextBody(operation) {
        const wireClientContext = mapClientContextToWire(this.options.clientContext?.(operation));
        const uiClient = this.options.uiClient;
        const uiState = uiClient?.readUIState() ?? null;
        return {
            ...(wireClientContext ? { clientContext: wireClientContext } : {}),
            ...(uiClient ? { clientId: uiClient.clientId } : {}),
            ...(uiState ? { uiState } : {}),
        };
    }
    /** The URL of a route: the base, the path, the binding's query parameters with the host's extra ones over them. */
    buildUrl(request) {
        const searchParameters = new URLSearchParams();
        const extraParameters = this.options.extraRequestParameters?.(request.operation) ?? {};
        for (const [name, value] of Object.entries({ ...request.query, ...extraParameters })) {
            if (value !== undefined && value !== null)
                searchParameters.set(name, String(value));
        }
        const query = searchParameters.toString();
        const baseUrl = this.options.baseUrl.replace(/\/+$/, "");
        return `${baseUrl}${request.path}${query ? `?${query}` : ""}`;
    }
    async readFetchOptions() {
        const { fetchOptions } = this.options;
        return typeof fetchOptions === "function" ? await fetchOptions() : (fetchOptions ?? {});
    }
    /** One request; every failure becomes an `AgentsClientError` (the port's one error shape). */
    async request(request) {
        const fetchFunction = this.options.fetch ?? globalThis.fetch.bind(globalThis);
        let response;
        try {
            const fetchOptions = await this.readFetchOptions();
            const headers = new Headers(fetchOptions.headers);
            if (!headers.has("Accept"))
                headers.set("Accept", "application/json");
            if (request.body !== undefined && !headers.has("Content-Type"))
                headers.set("Content-Type", "application/json");
            response = await fetchFunction(this.buildUrl(request), {
                ...fetchOptions,
                headers,
                method: request.method,
                body: request.body === undefined ? undefined : JSON.stringify(request.body),
            });
        }
        catch (failure) {
            if (failure instanceof AgentsClientError)
                throw failure;
            const isAbort = failure instanceof Error && failure.name === "AbortError";
            throw isAbort
                ? new AgentsClientError("other", { code: AGENTS_CLIENT_ERROR_CODE_REQUEST_ABORTED, message: "The request was cancelled." })
                : new AgentsClientError("network", { message: failure instanceof Error ? failure.message : "The request failed." });
        }
        const responseText = await response.text().catch(() => "");
        const responseBody = readJsonBody(responseText);
        if (!response.ok) {
            throw mapHttpFailureToAgentsClientError(response.status, isPlainObject(responseBody) ? responseBody : null, response.headers.get("Retry-After"));
        }
        return (responseBody ?? {});
    }
}
const isPlainObject = (value) => typeof value === "object" && value !== null && !Array.isArray(value);
const readJsonBody = (text) => {
    if (text.trim() === "")
        return null;
    try {
        return JSON.parse(text);
    }
    catch {
        return null;
    }
};
/**
 * The conversation client over the module's own conversation routes (`AgentConversationReadsTrait`,
 * `AgentConversationWritesTrait`, the application's start route of `AgentConversationStartTrait`, optionally
 * `AgentSelectableModelsTrait`): `baseUrl` is where the application mounted its conversations controller, `fetchOptions`
 * what every request carries. Every function rejects with `AgentsClientError`. The client comes with live updates —
 * the polling default over the run-state read, which also reads at once after an accepted send — unless `polling` is
 * `false`.
 *
 * ```ts
 * const client = createAgentsHttpConversationClient({
 *   baseUrl: "/api/agents/conversations",
 *   fetchOptions: () => ({ headers: { Authorization: `Bearer ${readToken()}` } }),
 * });
 * <AgentsProvider client={client}><AgentChat /></AgentsProvider>
 * ```
 */
export const createAgentsHttpConversationClient = (options) => {
    const client = new AgentsHttpConversationClient(options);
    return options.polling === false ? client : withPollingChangeSubscription(client, options.polling);
};
