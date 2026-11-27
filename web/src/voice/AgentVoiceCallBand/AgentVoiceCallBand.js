import { getAgentDisplayName } from "../../provider/environment";
import { dismissAgentVoiceCallOutcome, hangUpAgentVoiceCall, IDLE_AGENT_VOICE_CALL, isAgentVoiceCallActive, isAgentVoiceCallShownInAgentSpace, startAgentVoiceCall, toggleAgentVoiceCallMute, useAgentVoiceCallSnapshot, } from "../call/agent-voice-call-store";
import { turnOfAgentVoiceCall } from "../call/agent-voice-call-turn";
import { formatAgentVoiceCallDuration } from "../call/format-agent-voice-call-duration";
import { useAgentVoiceCallElapsedSeconds } from "../call/use-agent-voice-call-elapsed-seconds";
import { buildAgentVoiceCallerLabel } from "../ring/agent-voice-caller-label";
import { acceptAgentVoiceIncomingRing, declineAgentVoiceIncomingRing, useAgentVoiceIncomingRing, useAgentVoiceRingBandHost, } from "../ring/agent-voice-incoming-ring-store";
import { AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS } from "../ring/answer-incoming-agent-voice-call";
import { textKey } from "../../provider/text-key";
import { VoiceBarsIcon } from "../../chat/ConversationMessage/ConversationCallMarkerChip/VoiceBarsIcon/VoiceBarsIcon";
import { AgentVoiceCloseIcon, AgentVoiceHangUpIcon, AgentVoiceMicrophoneIcon } from "../AgentVoiceIcons/AgentVoiceIcons";
import { useAgentVoiceTranslateText } from "../AgentVoiceCallProvider/agent-voice-call-context";
import { agentVoicePartTestId } from "../agent-voice-part-test-id";
import { agentVoiceCallOutcomeTitleTextKey } from "./agent-voice-call-outcome-title-text-key";
import styles from "./AgentVoiceCallBand.module.scss";
const TURN_STATUS_TEXT_KEYS = {
    connecting: textKey("Connecting…"),
    muted: textKey("You’re muted"),
    silentMicrophone: textKey("I can’t hear you — is your microphone on?"),
    agent: textKey("%agentName% is speaking"),
    owner: textKey("You’re speaking"),
    thinking: textKey("%agentName% is thinking"),
    listening: textKey("%agentName% is listening"),
};
/** The band's root test id while the application sets none. */
const DEFAULT_TEST_ID = "agent-voice-call-band";
/** Whether the application gave the band controls of its own: a node that renders something. */
const hasApplicationActions = (actions) => actions !== undefined && actions !== null && actions !== false;
/** The application's own controls (`actions`), first in the band's row of buttons. */
const AgentVoiceBandApplicationActions = (props) => {
    const { actions, testId } = props;
    if (!hasApplicationActions(actions))
        return null;
    return (<div className={styles.applicationActions} data-testid={testId}>
      {actions}
    </div>);
};
/** A pill on the dark band. */
const AgentVoiceBandAction = (props) => {
    const { label, icon, onClick, testId, iconOnly = false, pressed, variant, disabled = false } = props;
    return (<button type="button" className={styles.action} onClick={onClick} aria-pressed={pressed} disabled={disabled} data-variant={variant} data-icon-only={iconOnly || undefined} data-testid={testId}>
      {icon}
      <span className={styles.actionLabel}>{label}</span>
    </button>);
};
/**
 * The voice chat in the band over the chat input: before it, the agent asking to talk (Decline, Accept); during it,
 * who is on the line, for how long and whose turn it is, with Mute and End; after it, the outcome with the way to talk
 * again. Driven only by its props: the band feeds it the live call, a test or a showcase feeds it fixtures.
 */
export const AgentVoiceCallBandView = (props) => {
    const { call, elapsedSeconds, onStartVoiceChat, incoming, agentSpot, actions, testId } = props;
    const translateText = useAgentVoiceTranslateText();
    const duration = formatAgentVoiceCallDuration(elapsedSeconds);
    const agentName = getAgentDisplayName();
    const bandTestId = testId ?? DEFAULT_TEST_ID;
    const applicationActions = (<AgentVoiceBandApplicationActions actions={actions} testId={agentVoicePartTestId(testId, "actions", "agent-voice-call-band-actions")}/>);
    const spot = agentSpot === undefined || agentSpot === null ? null : <div className={styles.spot}>{agentSpot}</div>;
    if (isAgentVoiceCallActive(call.phase)) {
        return (<div className={styles.band} data-testid={bandTestId} data-phase={call.phase}>
        {/* Keyed by the phase: the words and buttons come in again as the call connects. */}
        <div key={call.phase} className={styles.content}>
          {spot}
          <div className={styles.who} aria-live="polite">
            <span className={styles.title}>{agentName}</span>
            {call.phase === "live" && <span className={styles.time}>{duration}</span>}
            <span className={styles.status} data-testid={agentVoicePartTestId(testId, "status", "agent-voice-call-status")}>
              {translateText(TURN_STATUS_TEXT_KEYS[turnOfAgentVoiceCall(call)])}
            </span>
          </div>
          <div className={styles.actions}>
            {applicationActions}
            <AgentVoiceBandAction label={call.isMuted ? translateText("Unmute") : translateText("Mute")} icon={<AgentVoiceMicrophoneIcon />} onClick={toggleAgentVoiceCallMute} pressed={call.isMuted} disabled={call.phase !== "live"} testId={agentVoicePartTestId(testId, "mute", "agent-voice-call-mute")}/>
            <AgentVoiceBandAction label={translateText("End")} icon={<AgentVoiceHangUpIcon />} onClick={() => hangUpAgentVoiceCall()} variant="end" testId={agentVoicePartTestId(testId, "end", "agent-voice-call-end")}/>
          </div>
        </div>
      </div>);
    }
    if (incoming?.ring) {
        const callerLabel = incoming.callerLabel ?? buildAgentVoiceCallerLabel(incoming.ring, translateText, agentName);
        return (<div className={styles.band} data-testid={bandTestId} data-phase="incoming">
        <div key="incoming" className={styles.content}>
          {spot}
          <div className={styles.who} role="alert">
            <span className={styles.title}>{callerLabel.text}</span>
            {callerLabel.detail ? <span className={styles.status}>{callerLabel.detail}</span> : null}
          </div>
          <div className={styles.actions}>
            {applicationActions}
            <AgentVoiceBandAction label={translateText("Decline")} icon={<AgentVoiceCloseIcon />} onClick={incoming.onDecline} variant="end" disabled={incoming.isAnswering} testId={agentVoicePartTestId(testId, "incoming-decline", "agent-voice-call-incoming-decline")}/>
            <AgentVoiceBandAction label={translateText("Accept")} icon={<VoiceBarsIcon size={16}/>} onClick={incoming.onAccept} variant="accept" disabled={incoming.isAnswering} testId={agentVoicePartTestId(testId, "incoming-accept", "agent-voice-call-incoming-accept")}/>
          </div>
        </div>
      </div>);
    }
    if (incoming?.refusal && call.phase === "idle") {
        return (<div className={styles.band} data-testid={bandTestId} data-phase="refused">
        <div key="refused" className={styles.content}>
          <div className={styles.who} role="status">
            <span className={styles.status} data-testid={agentVoicePartTestId(testId, "incoming-refusal", "agent-voice-call-incoming-refusal")}>
              {translateText(AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS[incoming.refusal])}
            </span>
          </div>
          {hasApplicationActions(actions) ? <div className={styles.actions}>{applicationActions}</div> : null}
        </div>
      </div>);
    }
    if (call.phase !== "ended" && call.phase !== "failed")
        return null;
    // Hung up before `session.started`: nothing was said.
    const wasConnected = call.connectedAtMs !== undefined;
    const titleTextKey = agentVoiceCallOutcomeTitleTextKey(call);
    return (<div className={styles.band} data-testid={bandTestId} data-phase={call.phase} data-end-reason={call.endReason}>
      <div key={call.phase} className={styles.content}>
        <div className={styles.who} aria-live="polite">
          <span className={styles.title} data-testid={call.phase === "ended" && wasConnected
            ? agentVoicePartTestId(testId, "ended-status", "agent-voice-call-ended-status")
            : undefined}>
            {translateText(titleTextKey)}
          </span>
          {call.phase === "ended" && wasConnected && <span className={styles.time}>{duration}</span>}
          {call.phase === "failed" && call.failureTextKey && (<span className={styles.status} data-testid={agentVoicePartTestId(testId, "failure", "agent-voice-call-failure")}>
              {translateText(call.failureTextKey)}
            </span>)}
        </div>
        <div className={styles.actions}>
          {applicationActions}
          <AgentVoiceBandAction label={call.phase === "failed" ? translateText("Try again") : translateText("Talk with %agentName% again")} icon={<VoiceBarsIcon size={16}/>} onClick={onStartVoiceChat} variant="accept" testId={agentVoicePartTestId(testId, "start-again", "agent-voice-call-start-again")}/>
          <AgentVoiceBandAction label={translateText("Close")} icon={<AgentVoiceCloseIcon />} onClick={dismissAgentVoiceCallOutcome} iconOnly testId={agentVoicePartTestId(testId, "close", "agent-voice-call-band-close")}/>
        </div>
      </div>
    </div>);
};
/**
 * The band of the agent ringing this client and of the running (or just finished) voice chat; renders nothing while
 * there is none. While presented it is where the ring is answered, so the page-level incoming call stays away. "Talk
 * again" starts a voice chat in the ended call's agent space and conversation.
 */
export const AgentVoiceCallBand = (props) => {
    const { renderAgentSpot, agentSpaceId, isPresented = true, actions, testId } = props;
    const liveCall = useAgentVoiceCallSnapshot();
    const call = isAgentVoiceCallShownInAgentSpace(liveCall, agentSpaceId) ? liveCall : IDLE_AGENT_VOICE_CALL;
    const elapsedSeconds = useAgentVoiceCallElapsedSeconds(call);
    const { ring, callerLabel, isAnswering, refusal } = useAgentVoiceIncomingRing();
    useAgentVoiceRingBandHost(isPresented);
    return (<AgentVoiceCallBandView call={call} elapsedSeconds={elapsedSeconds} agentSpot={renderAgentSpot?.(call)} actions={actions} testId={testId} onStartVoiceChat={() => void startAgentVoiceCall({ agentSpaceId: call.agentSpaceId ?? agentSpaceId, conversationId: call.conversationId })} incoming={{
            ring,
            callerLabel,
            isAnswering,
            refusal,
            onAccept: () => void acceptAgentVoiceIncomingRing(),
            onDecline: declineAgentVoiceIncomingRing,
        }}/>);
};
