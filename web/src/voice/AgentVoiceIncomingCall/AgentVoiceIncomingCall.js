import { getAgentDisplayName } from "../../provider/environment";
import { useEffect, useId } from "react";
import { isAgentVoiceCallActive, useAgentVoiceCallValue } from "../call/agent-voice-call-store";
import { buildAgentVoiceCallerLabel } from "../ring/agent-voice-caller-label";
import { acceptAgentVoiceIncomingRing, declineAgentVoiceIncomingRing, pickAgentVoiceIncomingCard, publishAgentVoiceIncomingRing, useAgentVoiceIncomingRing, } from "../ring/agent-voice-incoming-ring-store";
import { pickRingingAgentVoiceCall } from "../ring/agent-voice-ring";
import { AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS } from "../ring/answer-incoming-agent-voice-call";
import { useAgentVoiceIncomingRings } from "../ring/use-agent-voice-incoming-rings";
import { useAgentVoiceRingTone } from "../ring/use-agent-voice-ring-tone";
import { VoiceBarsIcon } from "../../chat/ConversationMessage/ConversationCallMarkerChip/VoiceBarsIcon/VoiceBarsIcon";
import { AgentVoiceCloseIcon, AgentVoiceMicrophoneIcon } from "../AgentVoiceIcons/AgentVoiceIcons";
import { useAgentVoiceRingSource, useAgentVoiceTranslateText } from "../AgentVoiceCallProvider/agent-voice-call-context";
import { agentVoicePartTestId } from "../agent-voice-part-test-id";
import styles from "./AgentVoiceIncomingCall.module.scss";
/**
 * The agent ringing the owner in the browser window: the one listener per client. While a ring is live it publishes it
 * (the band over an open chat's input offers Decline / Accept there) and plays the ring tone once the page had a user
 * interaction; with no chat band in sight (none open, or a surface covers them) it shows its own card with the same
 * choices, on top of the page. Accept answers the ring and, when this client won, starts the same WebRTC call as the
 * chat's voice button; Decline ends the ring for every open client. The ring goes when its deadline passed, when the
 * ring source reports it cleared, or when this client answered or declined it.
 *
 * A ring reaches every open client of the account, whatever the client shows: the caller line can name the calling
 * agent space (`resolveCallerLabel`), and Accept starts the call on the ring's own conversation.
 *
 * Mounted once, below `AgentVoiceCallProvider` and outside the chat, so it rings whether or not a chat is open. It
 * listens while the provider carries a ring source.
 */
export const AgentVoiceIncomingCall = (props) => {
    const { resolveCallerLabel, renderAgentSpot, zIndex, testId } = props;
    const translateText = useAgentVoiceTranslateText();
    const ringSource = useAgentVoiceRingSource();
    const isCallActive = useAgentVoiceCallValue((call) => isAgentVoiceCallActive(call.phase));
    const { ring, callerLabel, handledCallIds, isAnswering, refusal, bandHostCount, ringCoverCount } = useAgentVoiceIncomingRing();
    const { rings, nowMs } = useAgentVoiceIncomingRings(ringSource);
    const ringingCall = isCallActive ? undefined : pickRingingAgentVoiceCall(rings, nowMs, handledCallIds);
    const ringingCallerLabel = ringingCall === undefined
        ? undefined
        : (resolveCallerLabel?.(ringingCall) ?? buildAgentVoiceCallerLabel(ringingCall, translateText, getAgentDisplayName()));
    const ringingCallerLabelText = ringingCallerLabel?.text;
    const ringingCallerLabelDetail = ringingCallerLabel?.detail;
    useEffect(() => {
        publishAgentVoiceIncomingRing(ringingCall, ringingCallerLabelText === undefined ? undefined : { text: ringingCallerLabelText, detail: ringingCallerLabelDetail });
    }, [ringingCall, ringingCallerLabelText, ringingCallerLabelDetail]);
    // The listener is gone: nothing it published rings on.
    useEffect(() => () => publishAgentVoiceIncomingRing(undefined, undefined), []);
    useAgentVoiceRingTone(ring !== undefined && !isAnswering);
    // A chat in sight shows the ring (and a refused answer) in its band.
    const card = ringSource !== undefined ? pickAgentVoiceIncomingCard({ ring, refusal, bandHostCount, ringCoverCount }) : undefined;
    const callerLabelId = useId();
    const layerStyle = zIndex === undefined ? undefined : { zIndex };
    if (card === undefined)
        return null;
    if (ring === undefined) {
        if (refusal === undefined)
            return null;
        return (<div className={styles.incomingCall} style={layerStyle} role="status" data-testid={agentVoicePartTestId(testId, "refusal", "agent-voice-incoming-call-refusal")}>
        <span className={styles.detail}>{translateText(AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS[refusal])}</span>
      </div>);
    }
    const shownCallerLabel = callerLabel ?? buildAgentVoiceCallerLabel(ring, translateText, getAgentDisplayName());
    return (<div className={styles.incomingCall} role="alertdialog" aria-live="assertive" aria-labelledby={callerLabelId} style={layerStyle} data-testid={testId ?? "agent-voice-incoming-call"}>
      <div className={styles.spot}>
        {renderAgentSpot ? (renderAgentSpot()) : (<span className={styles.mark}>
            <VoiceBarsIcon size={24}/>
          </span>)}
      </div>
      <div className={styles.caller} id={callerLabelId}>
        <span className={styles.label}>{shownCallerLabel.text}</span>
        {shownCallerLabel.detail ? (<span className={styles.detail} data-testid={agentVoicePartTestId(testId, "detail", "agent-voice-incoming-call-detail")}>
            {shownCallerLabel.detail}
          </span>) : null}
      </div>
      <div className={styles.actions}>
        <button type="button" className={styles.action} disabled={isAnswering} onClick={declineAgentVoiceIncomingRing} data-testid={agentVoicePartTestId(testId, "decline", "agent-voice-incoming-call-decline")}>
          <AgentVoiceCloseIcon />
          <span>{translateText("Decline")}</span>
        </button>
        <button type="button" className={styles.action} data-variant="accept" disabled={isAnswering} onClick={() => void acceptAgentVoiceIncomingRing()} data-testid={agentVoicePartTestId(testId, "accept", "agent-voice-incoming-call-accept")}>
          <AgentVoiceMicrophoneIcon />
          <span>{translateText("Accept")}</span>
        </button>
      </div>
    </div>);
};
