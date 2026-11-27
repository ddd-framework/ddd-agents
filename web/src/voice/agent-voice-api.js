/** A refused or broken request of the voice routes; `code` is the route's error code (`call_already_running`, …). */
export class AgentVoiceApiError extends Error {
    code;
    httpStatus;
    constructor(code, httpStatus) {
        super(code);
        this.name = "AgentVoiceApiError";
        this.code = code;
        this.httpStatus = httpStatus;
    }
}
const readString = (value) => (typeof value === "string" && value !== "" ? value : undefined);
/**
 * The error code of a rejected adapter call. The voice routes answer the code as the error message, so it is read
 * where the common request layers put it: `error` (the JSON error body itself), `data.error` (the body below a status),
 * `code`, or the message of an `Error`. Undefined when the failure names none (the network is down).
 */
export const readAgentVoiceErrorCode = (failure) => {
    if (typeof failure !== "object" || failure === null)
        return undefined;
    const failureFields = failure;
    const errorBody = typeof failureFields.data === "object" && failureFields.data !== null
        ? failureFields.data
        : undefined;
    return (readString(failureFields.error) ??
        readString(errorBody?.error) ??
        readString(failureFields.code) ??
        readString(failureFields.message));
};
/** Without trailing slashes (`/api/agents/voiceSession/` → `/api/agents/voiceSession`). */
const trimApiPrefix = (apiPrefix) => apiPrefix.replace(/\/+$/, "");
const callPath = (callId) => `/${encodeURIComponent(String(callId))}`;
/**
 * The request of every operation, on `apiPrefix`, with the routes' own field names; `open` on `buildOpenPath` when
 * given. Pure: nothing is sent.
 */
export const buildAgentVoiceRequests = (apiPrefix, buildOpenPath) => {
    const prefix = trimApiPrefix(apiPrefix);
    return {
        openSession: ({ agentSpaceId, sdpOffer, conversationId, callId, }) => ({
            url: buildOpenPath === undefined ? `${prefix}/open` : buildOpenPath(agentSpaceId),
            method: "POST",
            body: { agentSpaceId, sdpOffer, conversationId, callId },
        }),
        hangUp: ({ callId, reason }) => ({
            url: `${prefix}${callPath(callId)}/hangUp`,
            method: "POST",
            body: { reason },
        }),
        reportDeviceStatus: ({ callId, status, iceCandidateType, roundTripTimeMs, detail, occurredAtUnixMs, }) => ({
            url: `${prefix}${callPath(callId)}/deviceStatus`,
            method: "POST",
            body: { status, iceCandidateType, roundTripTimeMs, detail, occurredAtUnixMs },
        }),
        readCallState: ({ callId }) => ({
            url: `${prefix}${callPath(callId)}`,
        }),
        answerRing: ({ callId, answerToken }) => ({
            url: `${prefix}${callPath(callId)}/answer`,
            method: "POST",
            body: { answerToken },
        }),
        declineRing: ({ callId, answerToken }) => ({
            url: `${prefix}${callPath(callId)}/decline`,
            method: "POST",
            body: { answerToken },
        }),
    };
};
/** The code of an `open` answer without a call id or a conversation: nothing the call could go on with. */
export const AGENT_VOICE_ERROR_CODE_INVALID_OPEN_ANSWER = "invalid_open_answer";
/**
 * The adapter for an application whose routes are the voice trait's own paths below `apiPrefix`, with the agent space
 * in the body of `open`:
 * `export const agentVoiceApi = createAgentVoiceApi({ baseApi, apiPrefix: "/api/agents/voiceSession" });`
 * An application whose `open` names the space in its path adds `buildOpenPath`.
 */
export const createAgentVoiceApi = ({ baseApi, apiPrefix, buildOpenPath }) => {
    const requests = buildAgentVoiceRequests(apiPrefix, buildOpenPath);
    return {
        openSession: async (args) => {
            const openAnswer = await baseApi.request(requests.openSession(args));
            const callId = openAnswer?.agentVoiceCall?.id ?? args.callId;
            const conversationId = openAnswer?.conversationId ?? args.conversationId;
            if (callId === undefined || conversationId === undefined)
                throw new AgentVoiceApiError(AGENT_VOICE_ERROR_CODE_INVALID_OPEN_ANSWER);
            // No SDP answer stays an empty one: the store ends the opened call on the server before it shows the failure.
            return { callId, sdpAnswer: openAnswer?.sdpAnswer ?? "", conversationId };
        },
        hangUp: async (args) => {
            await baseApi.request(requests.hangUp(args));
        },
        reportDeviceStatus: async (args) => {
            await baseApi.request(requests.reportDeviceStatus(args));
        },
        readCallState: async (args) => {
            const callState = await baseApi.request(requests.readCallState(args));
            return {
                callId: callState?.callId ?? args.callId,
                endedAt: callState?.endedAt ?? undefined,
                endReason: callState?.endReason ?? undefined,
            };
        },
        answerRing: async (args) => {
            const ringAnswer = await baseApi.request(requests.answerRing(args));
            return { won: ringAnswer?.won === true, conversationId: ringAnswer?.conversationId ?? undefined };
        },
        declineRing: async (args) => {
            await baseApi.request(requests.declineRing(args));
        },
    };
};
const readJsonBody = async (response) => {
    const bodyText = await response.text();
    if (bodyText === "")
        return undefined;
    try {
        return JSON.parse(bodyText);
    }
    catch {
        return undefined;
    }
};
/**
 * A request layer over `fetch`: JSON out, JSON in; a refused request rejects with `AgentVoiceApiError`, its code the
 * `error` of the JSON error body (`http_<status>` without one).
 */
export const createAgentVoiceFetchBaseApi = (options = {}) => ({
    request: async (request) => {
        const fetchFunction = options.fetch ?? globalThis.fetch.bind(globalThis);
        const fetchOptions = typeof options.fetchOptions === "function" ? await options.fetchOptions() : (options.fetchOptions ?? {});
        const headers = new Headers(fetchOptions.headers);
        if (!headers.has("Accept"))
            headers.set("Accept", "application/json");
        if (request.body !== undefined && !headers.has("Content-Type"))
            headers.set("Content-Type", "application/json");
        const response = await fetchFunction(request.url, {
            ...fetchOptions,
            headers,
            method: request.method ?? "GET",
            body: request.body === undefined ? undefined : JSON.stringify(request.body),
        });
        const responseBody = await readJsonBody(response);
        if (!response.ok) {
            throw new AgentVoiceApiError(readAgentVoiceErrorCode(responseBody) ?? `http_${response.status}`, response.status);
        }
        return responseBody;
    },
});
