import { describe, expect, it } from "vitest";
import { AGENT_VOICE_ERROR_CODE_INVALID_OPEN_ANSWER, AgentVoiceApiError, buildAgentVoiceRequests, createAgentVoiceApi, createAgentVoiceFetchBaseApi, readAgentVoiceErrorCode, } from "./agent-voice-api";
/** The reason a promise was rejected with; a promise that resolves fails the spec. */
const rejectionOf = async (promise) => {
    try {
        await promise;
    }
    catch (failure) {
        return failure;
    }
    throw new Error("expected the promise to be rejected, it resolved");
};
describe("the requests of the six voice routes", () => {
    const requests = buildAgentVoiceRequests("/api/agents/voiceSession/");
    it("opens a session with a POST to open and the offer, the conversation and the answered call in the body", () => {
        expect(requests.openSession({ agentSpaceId: 3, sdpOffer: "v=0 offer", conversationId: 41, callId: 9 })).toStrictEqual({
            url: "/api/agents/voiceSession/open",
            method: "POST",
            body: { agentSpaceId: 3, sdpOffer: "v=0 offer", conversationId: 41, callId: 9 },
        });
    });
    it("leaves the optional open fields out of the sent JSON when not given", () => {
        const request = requests.openSession({ sdpOffer: "v=0 offer" });
        expect(request.url).toBe("/api/agents/voiceSession/open");
        expect(JSON.stringify(request.body)).toBe('{"sdpOffer":"v=0 offer"}');
    });
    it("hangs up with a POST to {callId}/hangUp and the reason", () => {
        expect(requests.hangUp({ callId: 9, reason: "USER_ENDED" })).toStrictEqual({
            url: "/api/agents/voiceSession/9/hangUp",
            method: "POST",
            body: { reason: "USER_ENDED" },
        });
        expect(JSON.stringify(requests.hangUp({ callId: 9 }).body)).toBe("{}");
    });
    it("reports a device status with a POST to {callId}/deviceStatus and the five fields of the route", () => {
        expect(requests.reportDeviceStatus({
            callId: 9,
            status: "MEDIA_CONNECTED",
            iceCandidateType: "relay",
            roundTripTimeMs: 80,
            detail: "ok",
            occurredAtUnixMs: 1767259200000,
        })).toStrictEqual({
            url: "/api/agents/voiceSession/9/deviceStatus",
            method: "POST",
            body: { status: "MEDIA_CONNECTED", iceCandidateType: "relay", roundTripTimeMs: 80, detail: "ok", occurredAtUnixMs: 1767259200000 },
        });
    });
    it("reads the call state with a GET of {callId} and no body", () => {
        expect(requests.readCallState({ callId: 9 })).toStrictEqual({ url: "/api/agents/voiceSession/9" });
    });
    it("answers and declines a ring with a POST to {callId}/answer and {callId}/decline and the answer token", () => {
        expect(requests.answerRing({ callId: 9, answerToken: "1767259200.signature" })).toStrictEqual({
            url: "/api/agents/voiceSession/9/answer",
            method: "POST",
            body: { answerToken: "1767259200.signature" },
        });
        expect(requests.declineRing({ callId: 9, answerToken: "1767259200.signature" })).toStrictEqual({
            url: "/api/agents/voiceSession/9/decline",
            method: "POST",
            body: { answerToken: "1767259200.signature" },
        });
    });
    it("takes the prefix without trailing slashes, with one or several, and an empty prefix", () => {
        expect(buildAgentVoiceRequests("/voice").hangUp({ callId: 1 }).url).toBe("/voice/1/hangUp");
        expect(buildAgentVoiceRequests("/voice///").hangUp({ callId: 1 }).url).toBe("/voice/1/hangUp");
        expect(buildAgentVoiceRequests("").readCallState({ callId: 1 }).url).toBe("/1");
    });
});
/** A request layer that records each request and answers from a queue (a value, or a failure to reject with). */
class QueuedBaseApi {
    requests = [];
    answers = [];
    request = async (request) => {
        this.requests.push(request);
        const answer = this.answers.shift() ?? { value: undefined };
        if (answer.failure !== undefined)
            throw answer.failure;
        return answer.value;
    };
}
describe("createAgentVoiceApi", () => {
    it("sends each operation as its request on the prefix", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push({ value: { agentVoiceCall: { id: 9 }, sdpAnswer: "v=0 answer", conversationId: 41 } }, { value: { acknowledged: true } }, { value: { acknowledged: true } }, { value: { callId: 9 } }, { value: { won: true, conversationId: 41 } }, { value: { acknowledged: true } });
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice" });
        await api.openSession({ sdpOffer: "v=0 offer" });
        await api.hangUp({ callId: 9, reason: "USER_ENDED" });
        await api.reportDeviceStatus({ callId: 9, status: "MEDIA_CONNECTED" });
        await api.readCallState({ callId: 9 });
        await api.answerRing({ callId: 9, answerToken: "token" });
        await api.declineRing({ callId: 9, answerToken: "token" });
        expect(baseApi.requests.map(request => `${request.method ?? "GET"} ${request.url}`)).toStrictEqual(["POST /voice/open", "POST /voice/9/hangUp", "POST /voice/9/deviceStatus", "GET /voice/9", "POST /voice/9/answer", "POST /voice/9/decline"]);
    });
    it("turns the open answer into the call id, the SDP answer and the conversation", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push({ value: { agentVoiceCall: { id: 9 }, sdpAnswer: "v=0 answer", conversationId: 41 } });
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice" });
        expect(await api.openSession({ sdpOffer: "v=0 offer" })).toStrictEqual({ callId: 9, sdpAnswer: "v=0 answer", conversationId: 41 });
    });
    it("keeps the answered call and the given conversation when the open answer does not name them", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push({ value: { sdpAnswer: "v=0 answer" } });
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice" });
        expect(await api.openSession({ sdpOffer: "v=0 offer", callId: 7, conversationId: 40 })).toStrictEqual({
            callId: 7,
            sdpAnswer: "v=0 answer",
            conversationId: 40,
        });
    });
    it("answers an empty SDP answer when the open answer carries none", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push({ value: { agentVoiceCall: { id: 9 }, sdpAnswer: null, conversationId: 41 } });
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice" });
        expect(await api.openSession({ sdpOffer: "v=0 offer" })).toStrictEqual({ callId: 9, sdpAnswer: "", conversationId: 41 });
    });
    it("rejects an open answer without a call id or without a conversation with invalid_open_answer", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push({ value: { sdpAnswer: "v=0 answer", conversationId: 41 } }, { value: { agentVoiceCall: { id: 9 }, sdpAnswer: "v=0" } }, { value: null });
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice" });
        for (let attempt = 0; attempt < 3; attempt += 1) {
            const failure = (await rejectionOf(api.openSession({ sdpOffer: "v=0 offer" })));
            expect(failure).toBeInstanceOf(AgentVoiceApiError);
            expect(failure.code).toBe(AGENT_VOICE_ERROR_CODE_INVALID_OPEN_ANSWER);
            expect(failure.message).toBe("invalid_open_answer");
            expect(failure.httpStatus).toBe(undefined);
        }
    });
    it("reads the call state with its end, and no end as undefined fields", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push({ value: { callId: 9, endedAt: "2026-11-25T10:00:00+00:00", endReason: "ENDED_BY_AGENT" } }, { value: { callId: 9, endedAt: null, endReason: null } }, { value: null });
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice" });
        expect(await api.readCallState({ callId: 9 })).toStrictEqual({ callId: 9, endedAt: "2026-11-25T10:00:00+00:00", endReason: "ENDED_BY_AGENT" });
        expect(await api.readCallState({ callId: 9 })).toStrictEqual({ callId: 9, endedAt: undefined, endReason: undefined });
        expect(await api.readCallState({ callId: 8 })).toStrictEqual({ callId: 8, endedAt: undefined, endReason: undefined });
    });
    it("counts a ring as won only when the answer says won: true", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push({ value: { won: true, conversationId: 41 } }, { value: { won: false } }, { value: { won: "true" } }, { value: null });
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice" });
        expect(await api.answerRing({ callId: 9, answerToken: "token" })).toStrictEqual({ won: true, conversationId: 41 });
        expect(await api.answerRing({ callId: 9, answerToken: "token" })).toStrictEqual({ won: false, conversationId: undefined });
        expect(await api.answerRing({ callId: 9, answerToken: "token" })).toStrictEqual({ won: false, conversationId: undefined });
        expect(await api.answerRing({ callId: 9, answerToken: "token" })).toStrictEqual({ won: false, conversationId: undefined });
    });
    it("passes a refused request on to the caller unchanged, so its code is still readable", async () => {
        const refusal = new AgentVoiceApiError("call_already_running", 409);
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push({ failure: refusal }, { failure: refusal });
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice" });
        await expect(api.openSession({ sdpOffer: "v=0 offer" })).rejects.toBe(refusal);
        expect(readAgentVoiceErrorCode(await rejectionOf(api.hangUp({ callId: 9 })))).toBe("call_already_running");
    });
});
describe("readAgentVoiceErrorCode", () => {
    it("reads the code from the error body, the body below a status, a code field and an error message, in that order", () => {
        expect(readAgentVoiceErrorCode({ error: "no_ai_budget", data: { error: "other" }, code: "x", message: "y" })).toBe("no_ai_budget");
        expect(readAgentVoiceErrorCode({ status: 403, data: { error: "daily_limit_reached" }, code: "x" })).toBe("daily_limit_reached");
        expect(readAgentVoiceErrorCode({ code: "ring_expired", message: "y" })).toBe("ring_expired");
        expect(readAgentVoiceErrorCode(new Error("already_answered"))).toBe("already_answered");
        expect(readAgentVoiceErrorCode(new AgentVoiceApiError("call_not_found", 404))).toBe("call_not_found");
    });
    it("skips empty and non-text fields and names no code for a failure that carries none", () => {
        expect(readAgentVoiceErrorCode({ error: "", data: { error: 42 }, code: 20, message: "invalid_sdp" })).toBe("invalid_sdp");
        expect(readAgentVoiceErrorCode({ data: null })).toBe(undefined);
        expect(readAgentVoiceErrorCode(undefined)).toBe(undefined);
        expect(readAgentVoiceErrorCode(null)).toBe(undefined);
        expect(readAgentVoiceErrorCode("no_ai_budget")).toBe(undefined);
    });
});
const fakeFetch = (recordedFetches, response) => (async (url, init) => {
    recordedFetches.push({ url: String(url), init: init ?? {} });
    return response();
});
describe("createAgentVoiceFetchBaseApi", () => {
    it("sends JSON out with Accept and Content-Type and resolves with the parsed answer", async () => {
        const recordedFetches = [];
        const baseApi = createAgentVoiceFetchBaseApi({
            fetch: fakeFetch(recordedFetches, () => new Response('{"acknowledged":true}', { status: 200 })),
            fetchOptions: { credentials: "include", headers: { Authorization: "Bearer token" } },
        });
        const answer = await baseApi.request({ url: "/voice/9/hangUp", method: "POST", body: { reason: "USER_ENDED" } });
        expect(answer).toStrictEqual({ acknowledged: true });
        expect(recordedFetches.length).toBe(1);
        const { url, init } = recordedFetches[0];
        expect(url).toBe("/voice/9/hangUp");
        expect(init.method).toBe("POST");
        expect(init.body).toBe('{"reason":"USER_ENDED"}');
        expect(init.credentials).toBe("include");
        const headers = init.headers;
        expect(headers.get("Accept")).toBe("application/json");
        expect(headers.get("Content-Type")).toBe("application/json");
        expect(headers.get("Authorization")).toBe("Bearer token");
    });
    it("sends a GET without a body or a Content-Type, and keeps headers the application set", async () => {
        const recordedFetches = [];
        const baseApi = createAgentVoiceFetchBaseApi({
            fetch: fakeFetch(recordedFetches, () => new Response('{"callId":9}', { status: 200 })),
            fetchOptions: { headers: { Accept: "application/vnd.app+json" } },
        });
        await baseApi.request({ url: "/voice/9" });
        const { init } = recordedFetches[0];
        expect(init.method).toBe("GET");
        expect(init.body).toBe(undefined);
        const headers = init.headers;
        expect(headers.get("Accept")).toBe("application/vnd.app+json");
        expect(headers.has("Content-Type")).toBe(false);
    });
    it("asks a fetchOptions function once per request (a fresh token each time)", async () => {
        const recordedFetches = [];
        let tokenNumber = 0;
        const baseApi = createAgentVoiceFetchBaseApi({
            fetch: fakeFetch(recordedFetches, () => new Response("", { status: 200 })),
            fetchOptions: async () => {
                tokenNumber += 1;
                return { headers: { Authorization: `Bearer token-${tokenNumber}` } };
            },
        });
        await baseApi.request({ url: "/voice/9" });
        await baseApi.request({ url: "/voice/9" });
        expect(recordedFetches.map(recordedFetch => recordedFetch.init.headers.get("Authorization"))).toStrictEqual(["Bearer token-1", "Bearer token-2"]);
    });
    it("resolves an empty or non-JSON success with undefined", async () => {
        const recordedFetches = [];
        const bodies = ["", "<html>ok</html>"];
        const baseApi = createAgentVoiceFetchBaseApi({
            fetch: fakeFetch(recordedFetches, () => new Response(bodies.shift(), { status: 200 })),
        });
        expect(await baseApi.request({ url: "/voice/9" })).toBe(undefined);
        expect(await baseApi.request({ url: "/voice/9" })).toBe(undefined);
    });
    it("rejects a refused request with the error code of the JSON error body and the HTTP status", async () => {
        const refusals = [
            [400, "invalid_sdp"],
            [400, "call_not_answered"],
            [403, "feature_not_available"],
            [403, "no_ai_budget"],
            [403, "daily_limit_reached"],
            [404, "call_not_found"],
            [404, "conversation_not_found"],
            [409, "call_already_running"],
            [409, "already_answered"],
            [410, "ring_expired"],
            [502, "provider_unavailable"],
        ];
        for (const [httpStatus, code] of refusals) {
            const baseApi = createAgentVoiceFetchBaseApi({
                // The framework's exception body: the code is the exception message in `error`.
                fetch: fakeFetch([], () => new Response(JSON.stringify({ error: code, exceptionDetails: null }), { status: httpStatus })),
            });
            const failure = (await rejectionOf(baseApi.request({ url: "/voice/open", method: "POST", body: {} })));
            expect(failure).toBeInstanceOf(AgentVoiceApiError);
            expect(failure.code).toBe(code);
            expect(failure.message).toBe(code);
            expect(failure.httpStatus).toBe(httpStatus);
            expect(readAgentVoiceErrorCode(failure)).toBe(code);
        }
    });
    it("names a refusal without an error body http_<status>", async () => {
        const bodies = ["", "<html>Bad Gateway</html>", '{"detail":"nothing"}'];
        const statuses = [500, 502, 403];
        const baseApi = createAgentVoiceFetchBaseApi({
            fetch: fakeFetch([], () => new Response(bodies.shift(), { status: statuses.shift() })),
        });
        for (const expectedCode of ["http_500", "http_502", "http_403"]) {
            const failure = (await rejectionOf(baseApi.request({ url: "/voice/9" })));
            expect(failure).toBeInstanceOf(AgentVoiceApiError);
            expect(failure.code).toBe(expectedCode);
        }
    });
    it("uses the global fetch when none is given", async () => {
        const originalFetch = globalThis.fetch;
        const recordedFetches = [];
        globalThis.fetch = fakeFetch(recordedFetches, () => new Response('{"callId":9}', { status: 200 }));
        try {
            expect(await createAgentVoiceFetchBaseApi().request({ url: "/voice/9" })).toStrictEqual({ callId: 9 });
            expect(recordedFetches.length).toBe(1);
        }
        finally {
            globalThis.fetch = originalFetch;
        }
    });
});
describe("the open path option of createAgentVoiceApi", () => {
    const OPEN_ANSWER = { value: { agentVoiceCall: { id: 9 }, sdpAnswer: "v=0 answer", conversationId: 41 } };
    const spacePath = (agentSpaceId) => agentSpaceId === undefined ? "/api/voiceSession/open" : `/api/spaces/${agentSpaceId}/voiceSession/open`;
    it("sends open to the path the option builds for the start's agent space, the body unchanged", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push(OPEN_ANSWER);
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice", buildOpenPath: spacePath });
        await api.openSession({ agentSpaceId: 3, sdpOffer: "v=0 offer", conversationId: 41 });
        expect(baseApi.requests).toStrictEqual([
            {
                url: "/api/spaces/3/voiceSession/open",
                method: "POST",
                body: { agentSpaceId: 3, sdpOffer: "v=0 offer", conversationId: 41, callId: undefined },
            },
        ]);
    });
    it("hands the option an absent agent space as undefined", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push(OPEN_ANSWER);
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice", buildOpenPath: spacePath });
        await api.openSession({ sdpOffer: "v=0 offer" });
        expect(baseApi.requests[0]?.url).toBe("/api/voiceSession/open");
    });
    it("leaves the other five routes below the prefix", async () => {
        const baseApi = new QueuedBaseApi();
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice/", buildOpenPath: spacePath });
        await api.hangUp({ callId: 9 });
        await api.reportDeviceStatus({ callId: 9, status: "MEDIA_CONNECTED" });
        await api.readCallState({ callId: 9 });
        await api.answerRing({ callId: 9, answerToken: "token" });
        await api.declineRing({ callId: 9, answerToken: "token" });
        expect(baseApi.requests.map(request => request.url)).toStrictEqual([
            "/voice/9/hangUp",
            "/voice/9/deviceStatus",
            "/voice/9",
            "/voice/9/answer",
            "/voice/9/decline",
        ]);
    });
    it("without the option open stays ${apiPrefix}/open, with the agent space in the body", async () => {
        const baseApi = new QueuedBaseApi();
        baseApi.answers.push(OPEN_ANSWER);
        const api = createAgentVoiceApi({ baseApi, apiPrefix: "/voice/" });
        await api.openSession({ agentSpaceId: 3, sdpOffer: "v=0 offer" });
        expect(baseApi.requests[0]).toMatchObject({ url: "/voice/open", method: "POST", body: { agentSpaceId: 3 } });
    });
    it("is the second argument of buildAgentVoiceRequests too", () => {
        expect(buildAgentVoiceRequests("/voice", spacePath).openSession({ agentSpaceId: 7, sdpOffer: "v=0" }).url).toBe("/api/spaces/7/voiceSession/open");
        expect(buildAgentVoiceRequests("/voice").openSession({ agentSpaceId: 7, sdpOffer: "v=0" }).url).toBe("/voice/open");
    });
});
