import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import * as format from "./agent-voice-admin-format";
const manifest = JSON.parse(readFileSync(join(__dirname, "..", "..", "i18n-keys.json"), "utf8"));
/** The list answer of the admin call route: 8818 rings, 8817 runs, 8816 was left open, 8815 ended. */
const callsResponse = JSON.parse(`{
  "agentVoiceCalls": { "elements": [
    { "accountId": 13, "ringDeadlineAt": "2026-11-18 13:54:20", "direction": "OUTBOUND", "aiConversationId": 4714, "bookedSeconds": 0, "objectType": "AgentVoiceCall", "id": 8818 },
    { "voiceProvider": "openAiLive", "accountId": 13, "direction": "INBOUND", "startedAt": "2026-11-18 13:48:20", "aiConversationId": 4713, "bookedSeconds": 270, "objectType": "AgentVoiceCall", "id": 8817 },
    { "voiceProvider": "openAiLive", "accountId": 12, "direction": "INBOUND", "startedAt": "2026-11-18 10:53:20", "aiConversationId": 4712, "bookedSeconds": 3540, "objectType": "AgentVoiceCall", "id": 8816 },
    { "voiceProvider": "openAiLive", "accountId": 12, "direction": "OUTBOUND", "startedAt": "2026-11-18 11:06:40", "endedAt": "2026-11-18 11:09:45", "endReason": "COMPLETED", "aiConversationId": 4711, "bookedSeconds": 180, "objectType": "AgentVoiceCall", "id": 8815 }
  ], "objectType": "AgentVoiceCalls" },
  "agentVoiceCallDerivedValues": { "elements": [
    { "callId": 8818, "callState": "RINGING", "objectType": "AdminAgentVoiceCallDerivedValues" },
    { "callId": 8817, "callState": "IN_PROGRESS", "bookedCosts": { "currency": { "isoCode": "USD", "displaySymbolBeforeAmount": false, "objectType": "Currency" }, "amount": 0.16199999999999998, "objectType": "MoneyAmount" }, "objectType": "AdminAgentVoiceCallDerivedValues" },
    { "callId": 8816, "callState": "ENDED", "bookedCosts": { "currency": { "isoCode": "USD", "displaySymbolBeforeAmount": false, "objectType": "Currency" }, "amount": 2.1239999999999997, "objectType": "MoneyAmount" }, "objectType": "AdminAgentVoiceCallDerivedValues" },
    { "callId": 8815, "callState": "ENDED", "bookedCosts": { "currency": { "isoCode": "USD", "displaySymbolBeforeAmount": false, "objectType": "Currency" }, "amount": 0.10799999999999998, "objectType": "MoneyAmount" }, "objectType": "AdminAgentVoiceCallDerivedValues" }
  ], "objectType": "AdminAgentVoiceCallsDerivedValues" }
}`);
/** The detail answer of the backend for call 8815. */
const callResponse = JSON.parse(`{
  "agentVoiceCall": { "voiceProvider": "openAiLive", "accountId": 12, "direction": "OUTBOUND", "startedAt": "2026-11-18 11:06:40", "endedAt": "2026-11-18 11:09:45", "endReason": "COMPLETED", "aiConversationId": 4711, "bookedSeconds": 180, "objectType": "AgentVoiceCall", "id": 8815 },
  "durationInSeconds": 185, "bookedSeconds": 180,
  "bookedCosts": { "currency": { "isoCode": "USD", "displaySymbolBeforeAmount": false, "objectType": "Currency" }, "amount": 0.10799999999999998, "objectType": "MoneyAmount" },
  "callState": "ENDED", "endReason": "COMPLETED", "conversationId": 4711
}`);
const callOf = (callId) => {
    const call = callsResponse.agentVoiceCalls.elements?.find(element => element.id === callId);
    if (call === undefined)
        throw new Error(`no call ${callId} in the fixture`);
    return call;
};
describe("the status maps of the admin call screens", () => {
    it("cover every state, end reason and direction of the types, and the filter offers every end reason once", () => {
        expect(Object.keys(format.AGENT_VOICE_CALL_STATE).sort()).toStrictEqual(["ENDED", "IN_PROGRESS", "RINGING"]);
        expect(Object.keys(format.AGENT_VOICE_CALL_END_REASON).sort()).toStrictEqual([
            "CANCELED",
            "COMPLETED",
            "DECLINED",
            "ENDED_BY_AGENT",
            "EXPIRED",
            "FAILED",
            "LIMIT_REACHED",
            "NOT_ANSWERED",
        ]);
        expect(Object.keys(format.AGENT_VOICE_CALL_DIRECTION).sort()).toStrictEqual(["INBOUND", "OUTBOUND"]);
        expect([...format.AGENT_VOICE_CALL_END_REASONS].sort()).toStrictEqual(Object.keys(format.AGENT_VOICE_CALL_END_REASON).sort());
    });
    it("have a status for every state and direction the backend answers in its examples", () => {
        const answeredStates = [...(callsResponse.agentVoiceCallDerivedValues.elements ?? []).map(derivedValues => derivedValues.callState), callResponse.callState];
        for (const callState of answeredStates)
            expect(callState in format.AGENT_VOICE_CALL_STATE, callState).toBe(true);
        for (const call of callsResponse.agentVoiceCalls.elements ?? [])
            expect(call.direction in format.AGENT_VOICE_CALL_DIRECTION, call.direction).toBe(true);
        expect(String(callResponse.endReason) in format.AGENT_VOICE_CALL_END_REASON).toBe(true);
    });
    it("translate every label and description with a context the manifest lists for that text", () => {
        const contextsByKey = new Map(manifest.keys.map(entry => [entry.key, entry.contexts]));
        const statusMaps = { AGENT_VOICE_CALL_STATE: format.AGENT_VOICE_CALL_STATE, AGENT_VOICE_CALL_END_REASON: format.AGENT_VOICE_CALL_END_REASON, AGENT_VOICE_CALL_DIRECTION: format.AGENT_VOICE_CALL_DIRECTION };
        const missingTranslations = [];
        for (const [mapName, statusMap] of Object.entries(statusMaps)) {
            for (const [value, definition] of Object.entries(statusMap)) {
                for (const text of [definition.label, definition.description]) {
                    if (text === undefined)
                        continue;
                    if (definition.context === undefined || !(contextsByKey.get(text) ?? []).includes(definition.context)) {
                        missingTranslations.push(`${mapName}.${value}: "${text}" with context ${definition.context}`);
                    }
                }
            }
        }
        expect(missingTranslations).toStrictEqual([]);
    });
});
describe("the helpers of the admin call screens", () => {
    it("join the derived values to the calls by call id and leave a call without an entry out", () => {
        const derivedValuesByCallId = format.agentVoiceAdminCallDerivedValuesByCallIdOf(callsResponse.agentVoiceCallDerivedValues);
        expect(derivedValuesByCallId.get(8818)?.callState).toBe("RINGING");
        expect(derivedValuesByCallId.get(8818)?.bookedCosts).toBe(undefined);
        expect(derivedValuesByCallId.get(8816)?.callState).toBe("ENDED");
        expect(derivedValuesByCallId.get(8815)?.bookedCosts?.amount).toBe(0.10799999999999998);
        expect(derivedValuesByCallId.get(9999)).toBe(undefined);
        const withoutOneEntry = format.agentVoiceAdminCallDerivedValuesByCallIdOf({ elements: callsResponse.agentVoiceCallDerivedValues.elements?.slice(1) });
        expect(withoutOneEntry.get(8818)).toBe(undefined);
        expect(withoutOneEntry.size).toBe(3);
        expect(format.agentVoiceAdminCallDerivedValuesByCallIdOf(undefined).size).toBe(0);
        expect(format.agentVoiceAdminCallDerivedValuesByCallIdOf(null).size).toBe(0);
    });
    it("join nothing for the set of an answer without a call, a set without elements and the former list shape", () => {
        const answerWithoutCall = JSON.parse(`{ "agentVoiceCalls": { "elements": [], "objectType": "AgentVoiceCalls" }, "agentVoiceCallDerivedValues": { "elements": [], "objectType": "AdminAgentVoiceCallsDerivedValues" } }`);
        expect(format.agentVoiceAdminCallDerivedValuesByCallIdOf(answerWithoutCall.agentVoiceCallDerivedValues).size).toBe(0);
        expect(format.agentVoiceAdminCallDerivedValuesByCallIdOf({}).size).toBe(0);
        const formerListShape = JSON.parse(`[{ "callId": 8818, "callState": "RINGING", "objectType": "AdminAgentVoiceCallDerivedValues" }]`);
        expect(format.agentVoiceAdminCallDerivedValuesByCallIdOf(formerListShape).size).toBe(0);
    });
    it("take the duration from the two times, the same the backend answers, and none without both", () => {
        expect(format.agentVoiceAdminCallDurationInSecondsOf(callOf(8815))).toBe(callResponse.durationInSeconds);
        expect(format.agentVoiceAdminCallDurationInSecondsOf(callOf(8815))).toBe(185);
        expect(format.agentVoiceAdminCallDurationInSecondsOf(callOf(8816))).toBe(null);
        expect(format.agentVoiceAdminCallDurationInSecondsOf(callOf(8818))).toBe(null);
        expect(format.agentVoiceAdminCallDurationInSecondsOf({ startedAt: "not a time", endedAt: "2026-11-18 11:09:45" })).toBe(null);
        expect(format.agentVoiceAdminCallDurationInSecondsOf({ startedAt: "2026-11-18 11:09:45", endedAt: "2026-11-18 11:06:40" })).toBe(0);
    });
    it("start a call at its creation where the answer carries it, else at its answer, else nowhere", () => {
        expect(format.agentVoiceAdminCallStartTimeOf({ startedAt: "2026-11-18 11:06:40", changeHistory: { createdTime: "2026-11-18 11:06:31" } })).toBe("2026-11-18 11:06:31");
        expect(format.agentVoiceAdminCallStartTimeOf(callOf(8815))).toBe("2026-11-18 11:06:40");
        expect(format.agentVoiceAdminCallStartTimeOf(callOf(8818))).toBe(null);
    });
    it("round an unrounded amount to at most four digits and show at least two", () => {
        const costsOf8815 = format.formatAgentVoiceAdminCallCosts({ amount: 0.10799999999999998, currency: { isoCode: "USD" } });
        expect(costsOf8815).toMatch(/0\.108(?!\d)/);
        expect(costsOf8815).not.toMatch(/0\.1079/);
        expect(format.formatAgentVoiceAdminCallCosts({ amount: 2.1239999999999997, currency: { isoCode: "USD" } })).toMatch(/2\.124(?!\d)/);
        expect(format.formatAgentVoiceAdminCallCosts({ amount: 0.1, currency: { isoCode: "USD" } })).toMatch(/0\.10(?!\d)/);
        expect(format.formatAgentVoiceAdminCallCosts({ amount: 0.123456, currency: { isoCode: "USD" } })).toMatch(/0\.1235(?!\d)/);
    });
    it("show the rounded number beside a currency code the number format refuses", () => {
        expect(format.formatAgentVoiceAdminCallCosts({ amount: 0.10799999999999998, currency: { isoCode: "US" } })).toBe("0.108 US");
    });
    it("turn a calendar day into its first and last second in UTC, in the viewer’s zone", () => {
        const zoneBefore = process.env.TZ;
        process.env.TZ = "Europe/Berlin";
        try {
            expect(format.toAgentVoiceAdminDayBound("2026-11-25", false)).toBe("2026-11-24 23:00:00");
            expect(format.toAgentVoiceAdminDayBound("2026-11-25", true)).toBe("2026-11-25 22:59:59");
            expect(format.toAgentVoiceAdminDayBound(" 2026-07-01 ", false)).toBe("2026-06-30 22:00:00");
            process.env.TZ = "UTC";
            expect(format.toAgentVoiceAdminDayBound("2026-11-25", false)).toBe("2026-11-25 00:00:00");
            expect(format.toAgentVoiceAdminDayBound("2026-11-25", true)).toBe("2026-11-25 23:59:59");
        }
        finally {
            if (zoneBefore === undefined)
                delete process.env.TZ;
            else
                process.env.TZ = zoneBefore;
        }
    });
    it("answer no day bound for an empty value, a malformed day or a day the calendar lacks", () => {
        for (const day of ["", "   ", "25.11.2026", "2026-11-5", "2026-02-30", "2026-13-01", "2026-00-10", "2026-11-25 10:00"]) {
            expect(format.toAgentVoiceAdminDayBound(day, false), day).toBe(undefined);
            expect(format.toAgentVoiceAdminDayBound(day, true), day).toBe(undefined);
        }
    });
    it("read a whole, non-negative number of at most 15 digits, trimmed", () => {
        expect(format.readAgentVoiceAdminWholeNumber("12")).toBe(12);
        expect(format.readAgentVoiceAdminWholeNumber(" 0060 ")).toBe(60);
        expect(format.readAgentVoiceAdminWholeNumber("999999999999999")).toBe(999999999999999);
        for (const text of ["", "-1", "1.5", "1e3", "12a", "0x10", "1 2", "1234567890123456"]) {
            expect(format.readAgentVoiceAdminWholeNumber(text), text).toBe(undefined);
        }
    });
    it("recognise the two codes of the routes in a failed request, also behind a translated message", () => {
        const { AGENT_VOICE_ADMIN_ERROR_FEATURE_NOT_AVAILABLE, AGENT_VOICE_ADMIN_ERROR_CALL_NOT_FOUND, isAgentVoiceAdminError } = format;
        expect(AGENT_VOICE_ADMIN_ERROR_FEATURE_NOT_AVAILABLE).toBe("feature_not_available");
        expect(AGENT_VOICE_ADMIN_ERROR_CALL_NOT_FOUND).toBe("call_not_found");
        const switchedOff = { status: 403, data: { error: "feature_not_available", exceptionDetails: null } };
        const notFound = { status: 404, data: { error: "call_not_found" } };
        const translated = { status: 404, data: { error: "Dieser Anruf wurde nicht gefunden.", errorEnglish: "call_not_found" } };
        expect(isAgentVoiceAdminError(switchedOff, "feature_not_available")).toBe(true);
        expect(isAgentVoiceAdminError(switchedOff, "call_not_found")).toBe(false);
        expect(isAgentVoiceAdminError(notFound, "call_not_found")).toBe(true);
        expect(isAgentVoiceAdminError(translated, "call_not_found")).toBe(true);
        expect(isAgentVoiceAdminError(new Error("call_not_found"), "call_not_found")).toBe(true);
        expect(isAgentVoiceAdminError({ status: 400, data: { error: "unknown filter property callState" } }, "call_not_found")).toBe(false);
        expect(isAgentVoiceAdminError(undefined, "call_not_found")).toBe(false);
        expect(isAgentVoiceAdminError(null, "call_not_found")).toBe(false);
    });
});
