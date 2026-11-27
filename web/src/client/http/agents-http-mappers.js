import { AGENTS_CLIENT_CONTEXT_LIMITS, AgentsClientError, } from "../../contract/conversation";
// The vocabularies of the contract: a wire value outside one maps to null.
const RUN_STATES = [
    "IDLE",
    "QUEUED",
    "RUNNING",
    "AWAITING_DELEGATES",
    "FAILED",
    "COMPLETED",
    "CANCELLED",
];
const MESSAGE_ROLES = ["SYSTEM", "USER", "ASSISTANT"];
const MESSAGE_TYPES = [
    "TEXT",
    "TOOL_CALL",
    "CHECKPOINT",
    "ERROR",
    "SIGNAL",
    "TOOL_MESSAGE",
];
const MESSAGE_STATUSES = [
    "PENDING",
    "RUNNING",
    "CANCEL_REQUESTED",
    "CANCELLED",
    "COMPLETED",
    "FAILED",
];
const AUTHOR_TYPES = ["USER", "ASSISTANT", "AGENT", "TOOL"];
const PROGRESS_STEP_STATUSES = ["done", "active", "upcoming"];
const ACTIVITY_STATES = [
    "queued",
    "running",
    "finished",
    "failed",
    "skipped",
];
const ACTIVITY_KINDS = [
    "tool",
    "delegation",
    "background",
    "long",
    "turnStart",
];
const readVocabularyValue = (vocabulary, value) => typeof value === "string" && vocabulary.includes(value) ? value : null;
const readNumberOrNull = (value) => typeof value === "number" && Number.isFinite(value) ? value : null;
const readStringOrNull = (value) => (typeof value === "string" ? value : null);
/** The elements of a serialized set (`{ elements: [...] }`), a plain array, or the values of a keyed object. */
export const readWireSetElements = (wireSet) => {
    if (!wireSet)
        return [];
    if (Array.isArray(wireSet))
        return wireSet;
    if (typeof wireSet === "object" && "elements" in wireSet) {
        const elements = wireSet.elements;
        return Array.isArray(elements) ? elements : [];
    }
    return Object.values(wireSet);
};
const mapWireProgressStep = (wireStep) => ({
    key: wireStep.key ?? "",
    label: wireStep.customerLabel ?? "",
    status: readVocabularyValue(PROGRESS_STEP_STATUSES, wireStep.status) ?? "upcoming",
});
/** An `AIConversation` as the contract's conversation. */
export const mapWireConversation = (wireConversation) => {
    const conversation = {
        id: wireConversation.id ?? 0,
        agentSpaceKey: readNumberOrNull(wireConversation.agentSpaceId),
        runState: readVocabularyValue(RUN_STATES, wireConversation.runState),
        agentTurnOwedAt: readStringOrNull(wireConversation.agentTurnOwedAt),
        title: readStringOrNull(wireConversation.summary?.title),
        titleManuallyEdited: wireConversation.summary?.manuallyEdited === true,
        summary: readStringOrNull(wireConversation.summary?.text),
        created: readStringOrNull(wireConversation.created ?? wireConversation.changeHistory?.createdTime),
        updated: readStringOrNull(wireConversation.updated ?? wireConversation.changeHistory?.modifiedTime),
        purposeName: readStringOrNull(wireConversation.purposeName),
        initiatorType: readStringOrNull(wireConversation.initiatorType),
        scheduleId: readNumberOrNull(wireConversation.scheduleId),
        entryPointKey: readStringOrNull(wireConversation.entryPointKey),
        progressSteps: readWireSetElements(wireConversation.customerProgressSteps).map(mapWireProgressStep),
    };
    if (wireConversation.lastMessageId !== undefined)
        conversation.lastMessageId = readNumberOrNull(wireConversation.lastMessageId);
    if (wireConversation.aiPromptExtensionId !== undefined) {
        conversation.promptExtensionId = readNumberOrNull(wireConversation.aiPromptExtensionId);
    }
    return conversation;
};
const mapWireSubAgent = (wireSubAgent) => wireSubAgent
    ? {
        currentRunNumber: readNumberOrNull(wireSubAgent.currentRunNumber),
        runs: readWireSetElements(wireSubAgent.runs).map((wireRun) => ({
            runNumber: readNumberOrNull(wireRun.runNumber),
            status: readStringOrNull(wireRun.status),
        })),
    }
    : null;
/** An `AIConversationMessage` as the contract's message. */
export const mapWireConversationMessage = (wireMessage) => {
    const message = {
        id: wireMessage.id ?? 0,
        conversationId: readNumberOrNull(wireMessage.aiConversationId),
        indexInConversation: readNumberOrNull(wireMessage.indexInConversation),
        role: readVocabularyValue(MESSAGE_ROLES, wireMessage.role),
        messageType: readVocabularyValue(MESSAGE_TYPES, wireMessage.messageType),
        status: readVocabularyValue(MESSAGE_STATUSES, wireMessage.status),
        authorType: readVocabularyValue(AUTHOR_TYPES, wireMessage.authorType),
        signalSource: readStringOrNull(wireMessage.signalSource),
        message: wireMessage.message ?? "",
        createdDate: readStringOrNull(wireMessage.createdDate),
        toolCall: wireMessage.toolCall
            ? {
                toolCallId: readStringOrNull(wireMessage.toolCall.toolCallId),
                name: readStringOrNull(wireMessage.toolCall.name),
                arguments: wireMessage.toolCall.arguments ?? null,
                status: readStringOrNull(wireMessage.toolCall.status),
            }
            : null,
        delegatedConversations: readWireSetElements(wireMessage.delegatedConversations).map((delegated) => ({
            id: delegated.id ?? 0,
            runState: readVocabularyValue(RUN_STATES, delegated.runState),
        })),
        subAgent: mapWireSubAgent(wireMessage.delegationResults?.subAgent),
    };
    const hasForensics = wireMessage.inputTokens != null || wireMessage.outputTokens != null || wireMessage.requestSnapshot != null;
    if (hasForensics) {
        message.forensics = {
            inputTokens: readNumberOrNull(wireMessage.inputTokens),
            outputTokens: readNumberOrNull(wireMessage.outputTokens),
            costInUSD: readNumberOrNull(wireMessage.costInUSD),
            requestSnapshot: wireMessage.requestSnapshot ?? null,
        };
    }
    return message;
};
/** The run-state read (`AIConversationRunStateGetResponseDto`). */
export const mapWireConversationRunState = (wireRunState) => ({
    id: wireRunState.id ?? 0,
    runState: readVocabularyValue(RUN_STATES, wireRunState.runState),
    agentTurnOwedAt: readStringOrNull(wireRunState.agentTurnOwedAt),
    lastMessageId: readNumberOrNull(wireRunState.lastMessageId),
    title: readStringOrNull(wireRunState.title),
    titleManuallyEdited: wireRunState.titleManuallyEdited === true,
    summary: readStringOrNull(wireRunState.summary),
    updated: readStringOrNull(wireRunState.updated),
});
const mapWireActivityLine = (wireLine) => ({
    toolCallMessageId: wireLine.toolCallMessageId ?? 0,
    batchId: readStringOrNull(wireLine.batchId),
    state: readVocabularyValue(ACTIVITY_STATES, wireLine.state),
    kind: readVocabularyValue(ACTIVITY_KINDS, wireLine.kind),
    childCount: readNumberOrNull(wireLine.childCount),
    helperIndex: readNumberOrNull(wireLine.helperIndex),
    line: readStringOrNull(wireLine.line),
});
/** The activity of a turn (`AIConversationActivityGetResponseDto`). */
export const mapWireConversationActivity = (wireActivity) => ({
    turnMessageId: readNumberOrNull(wireActivity.turnMessageId),
    turnRunning: wireActivity.turnRunning === true,
    elements: readWireSetElements(wireActivity.elements).map(mapWireActivityLine),
});
/** A `SemanticSearchHit`. */
export const mapWireSearchHit = (wireHit) => ({
    messageId: readNumberOrNull(wireHit.messageId),
    conversationId: readNumberOrNull(wireHit.conversationId),
    indexInConversation: readNumberOrNull(wireHit.indexInConversation),
    role: readStringOrNull(wireHit.role),
    excerpt: wireHit.excerpt ?? "",
    relevance: readNumberOrNull(wireHit.relevance) ?? 0,
    created: readStringOrNull(wireHit.created),
    updated: readStringOrNull(wireHit.updated),
    title: readStringOrNull(wireHit.title),
});
/** An `AIConversationSearchGroup`. */
export const mapWireSearchGroup = (wireGroup) => ({
    conversationId: readNumberOrNull(wireGroup.conversationId),
    title: readStringOrNull(wireGroup.title),
    summary: readStringOrNull(wireGroup.summary),
    created: readStringOrNull(wireGroup.created),
    updated: readStringOrNull(wireGroup.updated),
    relevance: readNumberOrNull(wireGroup.relevance) ?? 0,
    hits: readWireSetElements(wireGroup.hits).map(mapWireSearchHit),
});
/** An `AgentCustomerSelectableModel`. */
export const mapWireSelectableModel = (wireModel) => ({
    name: wireModel.name ?? "",
    displayName: wireModel.displayName ?? wireModel.name ?? "",
    description: wireModel.description ?? "",
    strengthLevel: readNumberOrNull(wireModel.strengthLevel),
    speedLevel: readNumberOrNull(wireModel.speedLevel),
    creditsMultiplier: readNumberOrNull(wireModel.creditsMultiplier),
});
/** The stable codes of the server's refusals the chat tells apart. */
const ERROR_KIND_BY_CODE = {
    AI_BUDGET_REACHED: "budget",
    RATE_LIMIT_EXCEEDED: "rateLimit",
    CONVERSATION_CLOSED: "closed",
};
/**
 * A refused request as the port's error: the stable code first (`errorCode` of the JSON body), then the status — 429
 * `rateLimit` (with `Retry-After`), 404 `notFound`, 403 and 401 `forbidden`, 5xx `network`, everything else `other`.
 */
export const mapHttpFailureToAgentsClientError = (httpStatus, wireErrorBody, retryAfterHeader) => {
    const code = wireErrorBody?.errorCode ?? wireErrorBody?.code ?? null;
    const message = wireErrorBody?.error ?? wireErrorBody?.message ?? undefined;
    const retryAfterOfHeader = retryAfterHeader !== null && /^\d+$/.test(retryAfterHeader.trim()) ? Number(retryAfterHeader) : null;
    const retryAfterSeconds = readNumberOrNull(wireErrorBody?.retryAfterSeconds) ?? retryAfterOfHeader;
    const kindOfCode = code !== null ? ERROR_KIND_BY_CODE[code] : undefined;
    const kind = kindOfCode ??
        (httpStatus === 429
            ? "rateLimit"
            : httpStatus === 404
                ? "notFound"
                : httpStatus === 403 || httpStatus === 401
                    ? "forbidden"
                    : httpStatus >= 500
                        ? "network"
                        : "other");
    return new AgentsClientError(kind, { message: message ?? undefined, code, retryAfterSeconds, httpStatus });
};
// eslint-disable-next-line no-control-regex
const CONTROL_CHARACTERS = /[\u0000-\u001f\u007f]/;
/** A text field of the client context as it goes out: trimmed, or left out when empty, over its cap or not one line. */
const readClientContextText = (value, maxChars) => {
    if (value === null || value === undefined)
        return undefined;
    const text = String(value).trim();
    if (text === "" || text.length > maxChars || CONTROL_CHARACTERS.test(text))
        return undefined;
    return text;
};
/**
 * The client context as the body field `clientContext` carries it (`AgentClientContext`): the route parameters as a
 * list of `{name, value}`. A field over its cap ({@link AGENTS_CLIENT_CONTEXT_LIMITS}) or with a control character is
 * left out — never cut — so a send never fails on the context; the route parameters go together or not at all.
 * Undefined when nothing is left.
 */
export const mapClientContextToWire = (clientContext) => {
    if (!clientContext)
        return undefined;
    const wireClientContext = {};
    const routePath = readClientContextText(clientContext.routePath, AGENTS_CLIENT_CONTEXT_LIMITS.routePathChars);
    if (routePath !== undefined)
        wireClientContext.routePath = routePath;
    const routeParameters = Object.entries(clientContext.routeParameters ?? {})
        .filter(([name, value]) => name.trim() !== "" && value !== null && value !== undefined)
        .map(([name, value]) => ({ name, value: String(value) }));
    const routeParametersText = routeParameters.map((routeParameter) => `${routeParameter.name}=${routeParameter.value}`).join("&");
    if (routeParameters.length > 0 &&
        routeParameters.length <= AGENTS_CLIENT_CONTEXT_LIMITS.routeParameterCount &&
        routeParametersText.length <= AGENTS_CLIENT_CONTEXT_LIMITS.routeParametersChars &&
        !CONTROL_CHARACTERS.test(routeParametersText)) {
        wireClientContext.routeParameters = routeParameters;
    }
    const recordKind = readClientContextText(clientContext.recordKind, AGENTS_CLIENT_CONTEXT_LIMITS.recordKindChars);
    if (recordKind !== undefined)
        wireClientContext.recordKind = recordKind;
    const recordId = readClientContextText(clientContext.recordId, AGENTS_CLIENT_CONTEXT_LIMITS.recordIdChars);
    if (recordId !== undefined)
        wireClientContext.recordId = recordId;
    const tab = readClientContextText(clientContext.tab, AGENTS_CLIENT_CONTEXT_LIMITS.tabChars);
    if (tab !== undefined)
        wireClientContext.tab = tab;
    return Object.keys(wireClientContext).length > 0 ? wireClientContext : undefined;
};
/** The record of an answer (`AIConversationBoundSubject`), or null when it names none. */
export const mapWireBoundConversationSubject = (wireSubject) => {
    const entityId = Number(wireSubject?.subjectEntityId ?? 0);
    if (!wireSubject || !wireSubject.subjectEntityClass || !Number.isInteger(entityId) || entityId < 1)
        return null;
    return {
        conversationId: wireSubject.aiConversationId ?? null,
        entityClass: wireSubject.subjectEntityClass,
        entityId,
        name: wireSubject.name ?? null,
        link: wireSubject.link ?? null,
        purposeId: wireSubject.purposeName ?? null,
    };
};
