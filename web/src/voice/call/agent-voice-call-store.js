import { useSyncExternalStore } from "react";
import { readAgentVoiceErrorCode } from "../agent-voice-api";
import { textKey } from "../../provider/text-key";
import { AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT } from "./agent-voice-call-end-reasons";
/** The call keeps the recent conversation only; the chat beside it keeps everything. */
const MAX_CALL_LINES = 40;
const SESSION_START_TIMEOUT_MS = 15000;
const AUDIO_FLOW_CHECK_INTERVAL_MS = 1000;
const AUDIO_FLOW_CHECK_ATTEMPTS = 10;
const LOG_PREFIX = "[AgentVoiceCall]";
/** How long after a speaker's last transcript delta they still count as speaking (deltas arrive in bursts). */
const AGENT_SPEAKING_HOLD_MS = 900;
const OWNER_SPEAKING_HOLD_MS = 900;
/** Below this `audioLevel` (0..1) for the whole window the input device delivers silence, not speech pauses. */
const SILENT_MICROPHONE_LEVEL = 0.01;
const SILENT_MICROPHONE_WINDOW_SAMPLES = 6;
/** Once the agent has heard the owner in this call, a pause only counts as a silent microphone after this long. */
const SILENT_AFTER_HEARD_MS = 60000;
const ICE_GATHERING_TIMEOUT_MS = 2000;
const ICE_DISCONNECT_GRACE_MS = 5000;
/** The event channel of the voice provider. */
const DATA_CHANNEL_LABEL = "oai-events";
// The end reasons the band words differently live in a module without React, so the band's title rule loads alone.
export { AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT, AGENT_VOICE_CALL_END_REASON_LIMIT_REACHED, } from "./agent-voice-call-end-reasons";
const AGENT_VOICE_CALL_END_REASON_FAILED = "FAILED";
/** The provider closed the session (`session.closed`) and the call did not name a reason yet. */
const PROVIDER_CLOSED_END_REASON = "PROVIDER_CLOSED";
/**
 * After `session.closed`, the backend may still be writing the call's end (the worker hangs up first, then stamps the
 * call): one more look at the call state after this long, before the call is ended with the neutral reason.
 */
const SESSION_CLOSED_RECHECK_MS = 1500;
const CONNECTION_DROPPED_TEXT_KEY = textKey("The connection to %agentName% dropped. Please try again.");
/** The sentence for the owner by the error code `openSession` was refused with. */
const OPEN_ERROR_TEXT_KEYS = {
    feature_not_available: textKey("Voice chats with %agentName% are not available here."),
    no_ai_budget: textKey("Your AI budget is used up, so %agentName% cannot talk right now."),
    daily_limit_reached: textKey("The time for voice chats with %agentName% is used up for today. Please try again tomorrow."),
    call_already_running: textKey("You are already in a voice chat with %agentName%. End it first, then start a new one."),
    conversation_not_found: textKey("This conversation cannot take a voice chat. Start a new chat and talk to %agentName% from there."),
    provider_unavailable: textKey("The voice of %agentName% is not reachable right now. Please try again in a minute."),
    invalid_sdp: textKey("Your browser could not set up the voice chat. Please try again."),
};
/** No voice chat: also what a chat shows of a voice chat that runs in another agent space. */
export const IDLE_AGENT_VOICE_CALL = {
    phase: "idle",
    isMuted: false,
    isMicrophoneSilent: false,
    isAgentSpeaking: false,
    isOwnerSpeaking: false,
    lines: [],
};
let snapshot = IDLE_AGENT_VOICE_CALL;
const listeners = new Set();
let voiceApi;
let peerConnection;
let microphoneStream;
let remoteAudioElement;
let providerEventChannel;
let audioFlowTimer;
let agentSpeakingTimer;
let ownerSpeakingTimer;
/** When the owner's voice last reached the agent (a microphone level above the silence line, or an input transcript). */
let ownerLastHeardAtMs;
let sessionStartTimer;
let iceDisconnectTimer;
let mediaConnectedReported = false;
let pageHideListenerRegistered = false;
/** The call-state read in flight, by call: the triggers (signal, data channel close, ICE) share one request. */
let callStateCheckInFlight;
let sessionClosedRecheckTimer;
const replaceSnapshot = (nextSnapshot) => {
    snapshot = nextSnapshot;
    listeners.forEach((listener) => listener());
};
const updateSnapshot = (changes) => replaceSnapshot({ ...snapshot, ...changes });
const subscribe = (listener) => {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
};
const getSnapshot = () => snapshot;
/**
 * The adapter the store sends its requests through. `AgentVoiceCallProvider` binds the one it was given; the binding
 * stays after the provider is gone, because a running call still has to end on the server.
 */
export const bindAgentVoiceApi = (api) => {
    voiceApi = api;
};
/** The bound adapter (undefined before a provider was mounted): answering and declining a ring go through it too. */
export const readBoundAgentVoiceApi = () => voiceApi;
/** The call as it is now, outside React (a module that reacts to an event, a test). */
export const readAgentVoiceCallSnapshot = () => snapshot;
/** The call as the store holds it; it changes with the call, not with the clock (see `useAgentVoiceCall`). */
export const useAgentVoiceCallSnapshot = () => useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
/**
 * One field of the call, for a component that must not re-render on every transcript delta (the chat window's shell,
 * the input's voice button). The selector must return a primitive so an unchanged value skips the render.
 */
export const useAgentVoiceCallValue = (selectValue) => useSyncExternalStore(subscribe, () => selectValue(snapshot), () => selectValue(snapshot));
export const isAgentVoiceCallActive = (phase) => phase === "connecting" || phase === "live";
/**
 * Whether a chat of the agent space `agentSpaceId` shows this call: not when the call is known to run in another
 * agent space. A chat or a call that names no agent space is never held apart.
 */
export const isAgentVoiceCallShownInAgentSpace = (call, agentSpaceId) => agentSpaceId === undefined || call.agentSpaceId === undefined || call.agentSpaceId === agentSpaceId;
/**
 * Whether a voice chat is connecting or live; with `agentSpaceId`, whether one is that a chat of that agent space
 * shows. An input shows the start button in its send spot only while none is, and the glow and the captions while one is.
 */
export const useIsAgentVoiceCallActive = (agentSpaceId) => useAgentVoiceCallValue((call) => isAgentVoiceCallActive(call.phase) && isAgentVoiceCallShownInAgentSpace(call, agentSpaceId));
/** Put away the band of an ended or failed call (its Close): the chat is back to no call. */
export const dismissAgentVoiceCallOutcome = () => {
    if (isAgentVoiceCallActive(snapshot.phase))
        return;
    replaceSnapshot(IDLE_AGENT_VOICE_CALL);
};
const clearTimers = () => {
    window.clearTimeout(sessionStartTimer);
    window.clearTimeout(iceDisconnectTimer);
    window.clearInterval(audioFlowTimer);
    window.clearTimeout(agentSpeakingTimer);
    window.clearTimeout(ownerSpeakingTimer);
    window.clearTimeout(sessionClosedRecheckTimer);
    sessionStartTimer = undefined;
    iceDisconnectTimer = undefined;
    audioFlowTimer = undefined;
    sessionClosedRecheckTimer = undefined;
};
const releaseMedia = () => {
    clearTimers();
    microphoneStream?.getTracks().forEach((track) => track.stop());
    providerEventChannel?.close();
    peerConnection?.close();
    if (remoteAudioElement) {
        remoteAudioElement.pause();
        remoteAudioElement.srcObject = null;
    }
    microphoneStream = undefined;
    providerEventChannel = undefined;
    peerConnection = undefined;
    remoteAudioElement = undefined;
};
const reportDeviceStatus = (status, detail) => {
    const callId = snapshot.callId;
    if (callId === undefined || !voiceApi)
        return;
    voiceApi.reportDeviceStatus({ callId, status, detail, occurredAtUnixMs: Date.now() }).catch(() => undefined);
};
const sendHangUp = (callId, reason) => {
    if (callId === undefined || !voiceApi)
        return;
    voiceApi.hangUp({ callId, reason }).catch(() => undefined);
};
/** The provider's close of the WebRTC session is `session.close` on the data channel, sent before the REST hang-up. */
const sendSessionClose = () => {
    if (providerEventChannel?.readyState !== "open")
        return;
    providerEventChannel.send(JSON.stringify({ type: "session.close" }));
};
const failCall = (failureTextKey, detail) => {
    if (!isAgentVoiceCallActive(snapshot.phase))
        return;
    console.warn(LOG_PREFIX, "call failed:", detail);
    reportDeviceStatus("MEDIA_FAILED", detail);
    sendSessionClose();
    sendHangUp(snapshot.callId, "MEDIA_FAILED");
    releaseMedia();
    updateSnapshot({
        phase: "failed",
        failureTextKey,
        endedAtMs: Date.now(),
        isAgentSpeaking: false,
        isOwnerSpeaking: false,
    });
};
/**
 * The SERVER ended the call (the call has `endedAt`; or the provider closed the session): end this browser's side
 * without telling the backend again: no hang-up request for a call the server already ended. Applies only while the
 * call is connecting or live AND the call id is this call's, so a late answer for an earlier call, or a second trigger
 * after the first one ended the call, changes nothing. `session.close` goes out unless the agent ended the call (its
 * worker already closed the session); a `FAILED` end shows the failure, every other end the call's end text.
 */
export const endAgentVoiceCallFromServer = (callId, endReason) => {
    if (!isAgentVoiceCallActive(snapshot.phase) || snapshot.callId !== callId)
        return;
    console.info(LOG_PREFIX, "call ended by the server:", endReason ?? "unknown reason");
    reportDeviceStatus("SERVER_END_SEEN", endReason);
    if (endReason !== AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT)
        sendSessionClose();
    releaseMedia();
    if (endReason === AGENT_VOICE_CALL_END_REASON_FAILED) {
        updateSnapshot({
            phase: "failed",
            failureTextKey: CONNECTION_DROPPED_TEXT_KEY,
            endedAtMs: Date.now(),
            isAgentSpeaking: false,
            isOwnerSpeaking: false,
        });
        return;
    }
    updateSnapshot({
        phase: "ended",
        endReason,
        endedAtMs: Date.now(),
        isAgentSpeaking: false,
        isOwnerSpeaking: false,
    });
};
const readCallStateAndEndWhenEndedOnServer = async (callId) => {
    if (!voiceApi)
        return false;
    try {
        const callState = await voiceApi.readCallState({ callId });
        if (!callState?.endedAt)
            return false;
        endAgentVoiceCallFromServer(callId, callState.endReason ?? undefined);
        return true;
    }
    catch {
        // The read failed (network, 404): nothing is known, the caller keeps its own path.
        return false;
    }
};
/**
 * Read the call's state (`readCallState`) and end the call here when the server ended it. Resolves to whether it did.
 * One read at a time per call: the conversation's change signal, the data channel closing and the ICE watcher may all
 * ask at once.
 */
export const checkAgentVoiceCallEndedOnServer = () => {
    const callId = snapshot.callId;
    if (callId === undefined || !isAgentVoiceCallActive(snapshot.phase))
        return Promise.resolve(false);
    if (callStateCheckInFlight?.callId === callId)
        return callStateCheckInFlight.endedOnServer;
    const endedOnServer = readCallStateAndEndWhenEndedOnServer(callId).finally(() => {
        if (callStateCheckInFlight?.callId === callId)
            callStateCheckInFlight = undefined;
    });
    callStateCheckInFlight = { callId, endedOnServer };
    return endedOnServer;
};
/**
 * The provider closed the session. The call says why once the backend wrote the end: look now and once more shortly
 * after; without an answer the call ends with the neutral reason. Never the hang-up request.
 */
const endCallAfterProviderClosedSession = async () => {
    const callId = snapshot.callId;
    if (callId === undefined) {
        hangUpAgentVoiceCall(PROVIDER_CLOSED_END_REASON);
        return;
    }
    if (await checkAgentVoiceCallEndedOnServer())
        return;
    window.clearTimeout(sessionClosedRecheckTimer);
    sessionClosedRecheckTimer = window.setTimeout(async () => {
        if (await checkAgentVoiceCallEndedOnServer())
            return;
        endAgentVoiceCallFromServer(callId, PROVIDER_CLOSED_END_REASON);
    }, SESSION_CLOSED_RECHECK_MS);
};
/**
 * The media path dropped (ICE): first ask the server whether it ended the call, then this is the call's end, not a
 * failure; otherwise the failure path as before.
 */
const failCallUnlessEndedOnServer = async (connection, detail) => {
    if (await checkAgentVoiceCallEndedOnServer())
        return;
    if (connection !== peerConnection)
        return;
    failCall(CONNECTION_DROPPED_TEXT_KEY, detail);
};
/** End the call from this browser. Safe to call in any phase. */
export const hangUpAgentVoiceCall = (reason = "USER_ENDED") => {
    if (!isAgentVoiceCallActive(snapshot.phase))
        return;
    if (reason === "USER_ENDED")
        reportDeviceStatus("ENDED_BY_USER");
    if (reason !== "PROVIDER_CLOSED")
        sendSessionClose();
    sendHangUp(snapshot.callId, reason);
    releaseMedia();
    updateSnapshot({
        phase: "ended",
        endedAtMs: Date.now(),
        isAgentSpeaking: false,
        isOwnerSpeaking: false,
    });
};
export const toggleAgentVoiceCallMute = () => {
    const isMuted = !snapshot.isMuted;
    // The muted stretch is not silence: the one-minute clock starts again on unmute.
    if (!isMuted && ownerLastHeardAtMs !== undefined)
        ownerLastHeardAtMs = Date.now();
    microphoneStream?.getAudioTracks().forEach((track) => {
        track.enabled = !isMuted;
    });
    updateSnapshot({ isMuted });
};
const appendTranscriptDelta = (speaker, delta) => {
    if (typeof delta !== "string" || delta === "")
        return;
    // A new turn of this speaker starts a new line; a continuing turn grows the last one.
    const lastLine = snapshot.lines[snapshot.lines.length - 1];
    const lines = lastLine !== undefined && lastLine.speaker === speaker
        ? [...snapshot.lines.slice(0, -1), { ...lastLine, text: `${lastLine.text}${delta}` }]
        : [...snapshot.lines, { id: (lastLine?.id ?? 0) + 1, speaker, text: delta.trimStart() }].slice(-MAX_CALL_LINES);
    if (speaker === "owner") {
        ownerLastHeardAtMs = Date.now();
        updateSnapshot({ lines, lastSpeaker: speaker, isOwnerSpeaking: true });
        window.clearTimeout(ownerSpeakingTimer);
        ownerSpeakingTimer = window.setTimeout(() => updateSnapshot({ isOwnerSpeaking: false }), OWNER_SPEAKING_HOLD_MS);
        return;
    }
    updateSnapshot({ lines, lastSpeaker: speaker, isAgentSpeaking: true });
    window.clearTimeout(agentSpeakingTimer);
    agentSpeakingTimer = window.setTimeout(() => updateSnapshot({ isAgentSpeaking: false }), AGENT_SPEAKING_HOLD_MS);
};
const readProviderEvent = (messageEvent) => {
    let providerEvent;
    try {
        providerEvent = JSON.parse(String(messageEvent.data));
    }
    catch {
        return;
    }
    if (!providerEvent.type?.endsWith(".delta"))
        console.info(LOG_PREFIX, "provider event", providerEvent.type);
    switch (providerEvent.type) {
        case "session.started":
            if (snapshot.phase !== "connecting")
                return;
            window.clearTimeout(sessionStartTimer);
            updateSnapshot({ phase: "live", connectedAtMs: Date.now() });
            reportDeviceStatus("SESSION_STARTED_SEEN");
            watchAudioFlow();
            return;
        case "session.output_transcript.delta":
            appendTranscriptDelta("agent", providerEvent.delta);
            return;
        case "session.input_transcript.delta":
            appendTranscriptDelta("owner", providerEvent.delta);
            return;
        case "session.closed":
            // The provider ended the session (the agent's hang-up, silence, the time cap, the budget): the server's end path.
            void endCallAfterProviderClosedSession();
            return;
        default:
            return;
    }
};
/**
 * The provider's session clock runs on input audio: report when the microphone's first bytes leave and the agent's
 * first bytes arrive (the start-up timeline of the call), and keep reading the microphone level for the whole call:
 * RTP packets flow even from a silent input device, so only the level tells that the agent hears nothing.
 */
const watchAudioFlow = () => {
    const connection = peerConnection;
    if (!connection)
        return;
    const microphoneTrack = microphoneStream?.getAudioTracks()[0];
    console.info(LOG_PREFIX, `microphone "${microphoneTrack?.label}" enabled=${microphoneTrack?.enabled} muted=${microphoneTrack?.muted} state=${microphoneTrack?.readyState}`);
    let firstAudioSentReported = false;
    let firstAudioPlayedReported = false;
    let silentMicrophoneReported = false;
    let attempts = 0;
    const recentMicrophoneLevels = [];
    window.clearInterval(audioFlowTimer);
    audioFlowTimer = window.setInterval(async () => {
        if (connection !== peerConnection)
            return;
        attempts += 1;
        const stats = await connection.getStats();
        stats.forEach((report) => {
            if (report.type === "outbound-rtp" && report.kind === "audio" && report.bytesSent > 0 && !firstAudioSentReported) {
                firstAudioSentReported = true;
                reportDeviceStatus("FIRST_AUDIO_SENT");
            }
            if (report.type === "inbound-rtp" && report.kind === "audio" && report.bytesReceived > 0 && !firstAudioPlayedReported) {
                firstAudioPlayedReported = true;
                reportDeviceStatus("FIRST_AUDIO_PLAYED");
            }
            if (report.type === "media-source" && report.kind === "audio" && typeof report.audioLevel === "number") {
                recentMicrophoneLevels.push(report.audioLevel);
                if (report.audioLevel >= SILENT_MICROPHONE_LEVEL)
                    ownerLastHeardAtMs = Date.now();
                if (recentMicrophoneLevels.length > SILENT_MICROPHONE_WINDOW_SAMPLES)
                    recentMicrophoneLevels.shift();
            }
        });
        if (attempts === AUDIO_FLOW_CHECK_ATTEMPTS) {
            console.info(LOG_PREFIX, `audio flow sent=${firstAudioSentReported} played=${firstAudioPlayedReported}`);
            if (!firstAudioSentReported)
                reportDeviceStatus("MEDIA_FAILED", "no outbound audio bytes 10s after session.started");
        }
        const loudestRecentLevel = Math.max(0, ...recentMicrophoneLevels);
        // Before the agent heard the owner once, a few silent seconds mean a wrong or muted device; after, only a long pause.
        const isSilentSinceStart = ownerLastHeardAtMs === undefined &&
            recentMicrophoneLevels.length === SILENT_MICROPHONE_WINDOW_SAMPLES &&
            loudestRecentLevel < SILENT_MICROPHONE_LEVEL;
        const isSilentSinceLastHeard = ownerLastHeardAtMs !== undefined && Date.now() - ownerLastHeardAtMs >= SILENT_AFTER_HEARD_MS;
        const isMicrophoneSilent = !snapshot.isMuted && (isSilentSinceStart || isSilentSinceLastHeard);
        if (isMicrophoneSilent !== snapshot.isMicrophoneSilent) {
            console.info(LOG_PREFIX, `microphone silent=${isMicrophoneSilent} loudest level=${loudestRecentLevel}`);
            updateSnapshot({ isMicrophoneSilent });
        }
        if (isMicrophoneSilent && !silentMicrophoneReported) {
            silentMicrophoneReported = true;
            reportDeviceStatus("INTERRUPTED", `microphone "${microphoneTrack?.label}" silent, loudest level ${loudestRecentLevel}`);
        }
    }, AUDIO_FLOW_CHECK_INTERVAL_MS);
};
const watchIceConnection = (connection) => {
    connection.oniceconnectionstatechange = () => {
        if (connection !== peerConnection)
            return;
        const iceConnectionState = connection.iceConnectionState;
        if (iceConnectionState === "connected" || iceConnectionState === "completed") {
            window.clearTimeout(iceDisconnectTimer);
            if (!mediaConnectedReported) {
                mediaConnectedReported = true;
                reportDeviceStatus("MEDIA_CONNECTED");
            }
        }
        else if (iceConnectionState === "disconnected") {
            // A server-side end drops the media too: ask at once, and again before the grace runs out into a failure.
            void checkAgentVoiceCallEndedOnServer();
            window.clearTimeout(iceDisconnectTimer);
            iceDisconnectTimer = window.setTimeout(() => void failCallUnlessEndedOnServer(connection, "ice disconnected for 5s"), ICE_DISCONNECT_GRACE_MS);
        }
        else if (iceConnectionState === "failed") {
            void failCallUnlessEndedOnServer(connection, "ice failed");
        }
    };
};
/** No trickle ICE on the provider side: the offer must carry the gathered candidates, capped at 2s. */
const waitForIceGathering = (connection) => new Promise((resolve) => {
    if (connection.iceGatheringState === "complete") {
        resolve();
        return;
    }
    const timer = window.setTimeout(resolve, ICE_GATHERING_TIMEOUT_MS);
    connection.addEventListener("icegatheringstatechange", () => {
        if (connection.iceGatheringState === "complete") {
            window.clearTimeout(timer);
            resolve();
        }
    });
});
const registerPageHideHangUp = () => {
    if (pageHideListenerRegistered)
        return;
    pageHideListenerRegistered = true;
    // Best-effort: the request may not leave before the tab is gone; the backend's reaper is the fallback.
    window.addEventListener("pagehide", () => hangUpAgentVoiceCall("APP_BACKGROUNDED"));
};
/**
 * Start a voice chat (or attach to the answered call `callId`). Call it straight from the click: the microphone prompt
 * and the audio playback need the user's gesture. Does nothing while a call is connecting or live, and nothing before
 * an `AgentVoiceCallProvider` bound the adapter.
 */
export const startAgentVoiceCall = async (params = {}) => {
    const { agentSpaceId, conversationId, callId: answeredCallId } = params;
    if (isAgentVoiceCallActive(snapshot.phase))
        return;
    const api = voiceApi;
    if (!api) {
        console.warn(LOG_PREFIX, "no adapter bound: mount AgentVoiceCallProvider before starting a voice chat");
        return;
    }
    mediaConnectedReported = false;
    ownerLastHeardAtMs = undefined;
    registerPageHideHangUp();
    replaceSnapshot({ ...IDLE_AGENT_VOICE_CALL, phase: "connecting", agentSpaceId, conversationId, callId: answeredCallId });
    try {
        microphoneStream = await navigator.mediaDevices.getUserMedia({
            audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        });
    }
    catch {
        releaseMedia();
        // An answered call is already claimed on the backend: end it there too.
        sendHangUp(answeredCallId, "MEDIA_FAILED");
        updateSnapshot({
            phase: "failed",
            failureTextKey: textKey("%agentName% needs your microphone. Allow microphone access in your browser, then try again."),
        });
        return;
    }
    const connection = new RTCPeerConnection();
    peerConnection = connection;
    remoteAudioElement = new Audio();
    remoteAudioElement.autoplay = true;
    connection.ontrack = (trackEvent) => {
        if (remoteAudioElement)
            remoteAudioElement.srcObject = trackEvent.streams[0] ?? null;
    };
    microphoneStream.getTracks().forEach((track) => connection.addTrack(track, microphoneStream));
    const eventChannel = connection.createDataChannel(DATA_CHANNEL_LABEL);
    providerEventChannel = eventChannel;
    eventChannel.onmessage = readProviderEvent;
    // The provider closing the channel is a hint the server ended the call; this browser's own close
    // (releaseMedia) clears `providerEventChannel` first, so it is no hint.
    eventChannel.onclose = () => {
        if (eventChannel !== providerEventChannel)
            return;
        void checkAgentVoiceCallEndedOnServer();
    };
    watchIceConnection(connection);
    try {
        await connection.setLocalDescription(await connection.createOffer());
        await waitForIceGathering(connection);
        const sdpOffer = connection.localDescription?.sdp ?? "";
        const openAnswer = await api.openSession({ agentSpaceId, sdpOffer, conversationId, callId: answeredCallId });
        const callId = openAnswer.callId ?? undefined;
        // Hung up while `openSession` was in flight: the call exists on the server now, so end it there too.
        if (connection !== peerConnection) {
            sendHangUp(callId, "USER_ENDED");
            return;
        }
        updateSnapshot({ callId, conversationId: openAnswer.conversationId ?? conversationId });
        if (!openAnswer.sdpAnswer) {
            failCall(textKey("The voice of %agentName% is not reachable right now. Please try again in a minute."), "open returned no SDP answer");
            return;
        }
        await connection.setRemoteDescription({ type: "answer", sdp: openAnswer.sdpAnswer });
        reportDeviceStatus("ANSWER_APPLIED");
        sessionStartTimer = window.setTimeout(() => failCall(textKey("%agentName% could not join the voice chat. Please try again."), "no session.started within 15s"), SESSION_START_TIMEOUT_MS);
    }
    catch (openError) {
        if (connection !== peerConnection)
            return;
        const errorCode = readAgentVoiceErrorCode(openError);
        // An own entry only: the code may be any text the request layer rejected with.
        const isKnownErrorCode = errorCode !== undefined && Object.prototype.hasOwnProperty.call(OPEN_ERROR_TEXT_KEYS, errorCode);
        releaseMedia();
        sendHangUp(answeredCallId, "MEDIA_FAILED");
        updateSnapshot({
            phase: "failed",
            failureTextKey: isKnownErrorCode
                ? OPEN_ERROR_TEXT_KEYS[errorCode]
                : textKey("The voice chat could not be started. Please try again."),
            endedAtMs: Date.now(),
        });
    }
};
