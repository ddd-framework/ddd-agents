import { cleanup, renderHook } from "@testing-library/react";
import { afterEach, describe, expect, test, vi } from "vitest";
import * as voiceEntry from "../../entries/voice";
import { installFakeMedia, resetAgentVoiceCall, startLiveAgentVoiceCall } from "../agent-voice-components.spec-helper";
import { RecordingAgentVoiceApi } from "./agent-voice-call-store.spec-helper";
import { useFollowAgentVoiceCallConversation } from "./use-follow-agent-voice-call-conversation";
/**
 * `useFollowAgentVoiceCallConversation`, exported from `./voice` for an application that follows the call's
 * conversation in a component of its own: once per call and conversation it hands over the conversation and the agent
 * space.
 */
describe("useFollowAgentVoiceCallConversation", () => {
    afterEach(() => {
        resetAgentVoiceCall();
        cleanup();
        vi.unstubAllGlobals();
    });
    test("is exported from ./voice", () => {
        expect(voiceEntry.useFollowAgentVoiceCallConversation).toBe(useFollowAgentVoiceCallConversation);
    });
    test("reports the call's conversation and agent space once, in a component outside the provider", async () => {
        const onConversationOfCallChanged = vi.fn();
        const { rerender } = renderHook(() => voiceEntry.useFollowAgentVoiceCallConversation(onConversationOfCallChanged));
        await startLiveAgentVoiceCall(new RecordingAgentVoiceApi(), installFakeMedia(), { agentSpaceId: 3 });
        rerender();
        expect(onConversationOfCallChanged).toHaveBeenCalledTimes(1);
        expect(onConversationOfCallChanged).toHaveBeenCalledWith(41, 3);
    });
    test("reports nothing without a handler and nothing while no call runs", () => {
        const onConversationOfCallChanged = vi.fn();
        renderHook(() => voiceEntry.useFollowAgentVoiceCallConversation(onConversationOfCallChanged));
        renderHook(() => voiceEntry.useFollowAgentVoiceCallConversation(undefined));
        expect(onConversationOfCallChanged).not.toHaveBeenCalled();
    });
});
