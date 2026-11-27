import * as adminUiPure from "@mgamadeus/ddd-admin-ui/logic/pure";
import { describe, expect, it } from "vitest";
import * as adminApi from "./agent-voice-admin-api";
/** A decoded query string, so a test reads the filter expression as the route reads it. */
const queryOf = (url) => Object.fromEntries(new URL(url, "https://admin.test").searchParams.entries());
const pathOf = (url) => new URL(url, "https://admin.test").pathname;
/** The part of an RTK Query api `injectEndpointsOnce` uses: records the tags and the endpoints, answers hooks by name. */
class FakeBaseApi {
    addedTagTypes = [];
    endpoints = {};
    injectCount = 0;
    enhanceEndpoints({ addTagTypes }) {
        this.addedTagTypes.push(...addTagTypes);
        return this;
    }
    injectEndpoints({ endpoints }) {
        this.injectCount += 1;
        this.endpoints = endpoints({ query: definition => definition });
        const hooks = {};
        for (const endpointName of Object.keys(this.endpoints)) {
            hooks[`use${endpointName.charAt(0).toUpperCase()}${endpointName.slice(1)}Query`] = (arg) => ({ hookOf: endpointName, arg });
        }
        return hooks;
    }
}
const emptyCallsResponse = { agentVoiceCalls: { elements: [] }, agentVoiceCallDerivedValues: { elements: [] } };
describe("the filters of the call list", () => {
    it("narrow nothing without a filter", () => {
        expect(adminApi.buildAgentVoiceAdminCallsFilters({})).toBe(undefined);
        expect(adminApi.buildAgentVoiceAdminCallsFilters({ createdFrom: "", createdTo: "", endReason: "", limit: 26, offset: 0 })).toBe(undefined);
    });
    it("build every filter on the columns of the route, joined with and", () => {
        expect(adminApi.buildAgentVoiceAdminCallsFilters({
            accountId: 12,
            conversationId: 4711,
            createdFrom: "2026-11-01 00:00:00",
            createdTo: "2026-11-30 23:59:59",
            bookedSecondsFrom: 60,
            bookedSecondsTo: 3600,
            endReason: "LIMIT_REACHED",
        })).toBe("accountId eq '12' and aiConversationId eq '4711' and created ge '2026-11-01 00:00:00' and created le '2026-11-30 23:59:59'" +
            " and bookedSeconds ge '60' and bookedSeconds le '3600' and endReason eq 'LIMIT_REACHED'");
    });
    it("keep a filter of 0 seconds and of account 0", () => {
        expect(adminApi.buildAgentVoiceAdminCallsFilters({ bookedSecondsFrom: 0 })).toBe("bookedSeconds ge '0'");
        expect(adminApi.buildAgentVoiceAdminCallsFilters({ accountId: 0 })).toBe("accountId eq '0'");
    });
    it("quote a free end reason so it cannot break out of the expression", () => {
        const filters = adminApi.buildAgentVoiceAdminCallsFilters({ endReason: "x' or accountId ne '0" });
        expect(filters?.startsWith("endReason eq ")).toBe(true);
        expect(filters).not.toBe("endReason eq 'x' or accountId ne '0'");
    });
});
describe("the requests of the admin call routes", () => {
    const requests = () => adminApi.buildAgentVoiceAdminRequests("/api/admin/ai/voiceCalls/");
    it("read the list on calls with the filters, the page size as top and the page start as skip", () => {
        const request = requests().calls({ accountId: 12, bookedSecondsFrom: 60, limit: 26, offset: 25 });
        expect(pathOf(request.url)).toBe("/api/admin/ai/voiceCalls/calls");
        expect(queryOf(request.url)).toStrictEqual({ filters: "accountId eq '12' and bookedSeconds ge '60'", top: "26", skip: "25" });
    });
    it("read the list without a query string when nothing narrows and no page is asked, and send no order (newest first on the server)", () => {
        expect(requests().calls().url).toBe("/api/admin/ai/voiceCalls/calls");
        expect(requests().calls({}).url).toBe("/api/admin/ai/voiceCalls/calls");
        expect(queryOf(requests().calls({ limit: 26, offset: 0 }).url).orderBy).toBe(undefined);
    });
    it("read one call on calls/{callId}", () => {
        expect(requests().call({ callId: 8815 })).toStrictEqual({ url: "/api/admin/ai/voiceCalls/calls/8815" });
    });
});
describe("createAgentVoiceAdminApi", () => {
    it("injects the two queries once per api and prefix, with their tags, and hands out their hooks", () => {
        const baseApi = new FakeBaseApi();
        const voiceAdminApi = adminApi.createAgentVoiceAdminApi({ baseApi: baseApi, apiPrefix: "/api/admin/ai/voiceCalls" });
        expect(baseApi.addedTagTypes).toStrictEqual(["AdminUiAgentVoiceCalls"]);
        expect(Object.keys(baseApi.endpoints).sort()).toStrictEqual(["adminUiAgentVoiceCall", "adminUiAgentVoiceCalls"]);
        expect(voiceAdminApi.useAgentVoiceCallsQuery({})).toStrictEqual({ hookOf: "adminUiAgentVoiceCalls", arg: {} });
        expect(voiceAdminApi.useAgentVoiceCallQuery({ callId: 7 })).toStrictEqual({ hookOf: "adminUiAgentVoiceCall", arg: { callId: 7 } });
        expect(baseApi.endpoints.adminUiAgentVoiceCalls.providesTags).toStrictEqual([{ type: "AdminUiAgentVoiceCalls", id: "LIST" }]);
        expect(baseApi.endpoints.adminUiAgentVoiceCall.providesTags).toStrictEqual([{ type: "AdminUiAgentVoiceCalls", id: "DETAIL" }]);
        expect(adminApi.createAgentVoiceAdminApi({ baseApi: baseApi, apiPrefix: "/api/admin/ai/voiceCalls/" })).toBe(voiceAdminApi);
        expect(baseApi.injectCount).toBe(1);
    });
    it("builds the requests of the injected queries on the prefix", () => {
        const baseApi = new FakeBaseApi();
        adminApi.createAgentVoiceAdminApi({ baseApi: baseApi, apiPrefix: "/voice" });
        expect(baseApi.endpoints.adminUiAgentVoiceCall.query({ callId: 7 }).url).toBe("/voice/calls/7");
        expect(queryOf(baseApi.endpoints.adminUiAgentVoiceCalls.query({ endReason: "EXPIRED" }).url).filters).toBe("endReason eq 'EXPIRED'");
    });
});
describe("createStaticAgentVoiceAdminApi", () => {
    it("answers the fixed list and detail of the arguments", () => {
        const detail = { agentVoiceCall: { id: 8815, direction: "OUTBOUND", bookedSeconds: 180 }, bookedSeconds: 180, callState: "ENDED" };
        const seenListArgs = [];
        const staticApi = adminApi.createStaticAgentVoiceAdminApi({
            calls: arg => {
                seenListArgs.push(arg);
                return emptyCallsResponse;
            },
            call: arg => (arg.callId === 8815 ? detail : undefined),
        });
        const list = staticApi.useAgentVoiceCallsQuery(undefined);
        expect(list.data).toBe(emptyCallsResponse);
        expect(list.isSuccess).toBe(true);
        expect(seenListArgs).toStrictEqual([{}]);
        expect(staticApi.useAgentVoiceCallQuery({ callId: 8815 }).data).toBe(detail);
        const loading = staticApi.useAgentVoiceCallQuery({ callId: 1 });
        expect(loading.isLoading).toBe(true);
        expect(loading.data).toBe(undefined);
    });
    it("stays loading without an answer, and reports a fixed failure as the error", () => {
        const switchedOff = { status: 403, data: { error: "feature_not_available" } };
        const staticApi = adminApi.createStaticAgentVoiceAdminApi({ calls: () => adminUiPure.staticQueryFailure(switchedOff) });
        const failed = staticApi.useAgentVoiceCallsQuery({});
        expect(failed.isError).toBe(true);
        expect(failed.error).toBe(switchedOff);
        expect(adminApi.createStaticAgentVoiceAdminApi({}).useAgentVoiceCallQuery({ callId: 1 }).isLoading).toBe(true);
    });
});
