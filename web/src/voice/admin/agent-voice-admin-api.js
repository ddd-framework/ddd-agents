import { injectEndpointsOnce, staticQueryHook, toQueryString, trimApiPrefix, } from "@mgamadeus/ddd-admin-ui/logic/pure";
import { buildFieldFilter, combineFilters } from "@mgamadeus/ddd-web/query";
/** The cache tag type of the call operations (ids `LIST`, `DETAIL`). */
export const AGENT_VOICE_CALLS_TAG = "AdminUiAgentVoiceCalls";
/**
 * The filter expression of the list's arguments, in the query options grammar of the route (`accountId eq '12' and
 * bookedSeconds ge '60'`); undefined when nothing narrows. The date range filters the moment a call was created, so a
 * call nobody answered is found too. Which accounts' calls are read at all is decided on the server.
 */
export const buildAgentVoiceAdminCallsFilters = (arg) => combineFilters(arg.accountId !== undefined && buildFieldFilter("accountId", arg.accountId, "eq"), arg.conversationId !== undefined && buildFieldFilter("aiConversationId", arg.conversationId, "eq"), arg.createdFrom !== undefined && arg.createdFrom !== "" && buildFieldFilter("created", arg.createdFrom, "ge"), arg.createdTo !== undefined && arg.createdTo !== "" && buildFieldFilter("created", arg.createdTo, "le"), arg.bookedSecondsFrom !== undefined && buildFieldFilter("bookedSeconds", arg.bookedSecondsFrom, "ge"), arg.bookedSecondsTo !== undefined && buildFieldFilter("bookedSeconds", arg.bookedSecondsTo, "le"), arg.endReason !== undefined && arg.endReason !== "" && buildFieldFilter("endReason", arg.endReason, "eq"));
/** The request of every operation, on `apiPrefix`. Pure: no RTK runtime. */
export const buildAgentVoiceAdminRequests = (apiPrefix) => {
    const prefix = trimApiPrefix(apiPrefix);
    return {
        calls: (arg = {}) => ({
            url: `${prefix}/calls${toQueryString({ filters: buildAgentVoiceAdminCallsFilters(arg), top: arg.limit, skip: arg.offset })}`,
        }),
        call: ({ callId }) => ({
            url: `${prefix}/calls/${encodeURIComponent(String(callId))}`,
        }),
    };
};
const LIST = { type: AGENT_VOICE_CALLS_TAG, id: "LIST" };
const DETAIL = { type: AGENT_VOICE_CALLS_TAG, id: "DETAIL" };
/** The operations as injected. */
export const agentVoiceAdminEndpointDefinitions = (apiPrefix) => {
    const requests = buildAgentVoiceAdminRequests(apiPrefix);
    return {
        adminUiAgentVoiceCalls: { kind: "query", request: requests.calls, providesTags: [LIST] },
        adminUiAgentVoiceCall: { kind: "query", request: requests.call, providesTags: [DETAIL] },
    };
};
/**
 * Injects the call operations into the host's RTK Query api and returns their hooks. Call it once at module scope:
 * `export const agentVoiceAdminApi = createAgentVoiceAdminApi({ baseApi: adminApi, apiPrefix: "/api/admin/ai/voiceCalls" });`
 */
export const createAgentVoiceAdminApi = ({ baseApi, apiPrefix }) => injectEndpointsOnce(baseApi, `agentVoiceCalls:${trimApiPrefix(apiPrefix)}`, [AGENT_VOICE_CALLS_TAG], agentVoiceAdminEndpointDefinitions(apiPrefix), (hookOf) => ({
    useAgentVoiceCallsQuery: hookOf("adminUiAgentVoiceCalls"),
    useAgentVoiceCallQuery: hookOf("adminUiAgentVoiceCall"),
}));
/** An `AgentVoiceAdminApi` answering fixed payloads, without a store or a request (showcases, tests, reviews). */
export const createStaticAgentVoiceAdminApi = (data) => ({
    useAgentVoiceCallsQuery: staticQueryHook((arg) => data.calls?.(arg ?? {})),
    useAgentVoiceCallQuery: staticQueryHook((arg) => data.call?.(arg)),
});
