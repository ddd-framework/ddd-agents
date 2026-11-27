import { Icon, Text } from "../../../provider/components";
import { getAgentDisplayName } from "../../../provider/environment";
import React, { memo } from "react";
import { VoiceBarsIcon } from "./VoiceBarsIcon/VoiceBarsIcon";
import styles from "./ConversationCallMarkerChip.module.scss";
import { textKey } from "../../../provider/text-key";
/** `123` → `2:03 min` for the chip; sub-minute stays as seconds. */
const formatCallDuration = (durationSeconds) => {
    if (durationSeconds < 60)
        return `${durationSeconds}s`;
    const minutes = Math.floor(durationSeconds / 60);
    const seconds = durationSeconds % 60;
    return `${minutes}:${String(seconds).padStart(2, "0")} min`;
};
const MARKER_LABELS = {
    started: { inApp: textKey("Voice chat started"), phoneLine: textKey("Phone call started") },
    ended: { inApp: textKey("Voice chat ended"), phoneLine: textKey("Phone call ended") },
    declined: { inApp: textKey("You declined a voice chat with %agentName%"), phoneLine: textKey("Phone call declined") },
    missed: { inApp: textKey("You missed a voice chat with %agentName%"), phoneLine: textKey("Phone call not answered") },
    notConnected: { inApp: textKey("Voice chat did not connect"), phoneLine: textKey("Phone call not answered") },
};
/** The agent's ring the owner turned down (DECLINED) or let run out (EXPIRED, NOT_ANSWERED) is its own mark in the chat. */
const callMarkerStateOf = (isCallEnd, endReason) => {
    if (!isCallEnd)
        return "started";
    if (endReason === "DECLINED")
        return "declined";
    if (endReason === "EXPIRED" || endReason === "NOT_ANSWERED")
        return "missed";
    if (endReason === "FAILED")
        return "notConnected";
    return "ended";
};
/**
 * Centered marker for a call's lifecycle inside the chat timeline: started, ended (with its duration), declined, missed
 * or not connected; an in-app call is a voice chat with the agent (its voice bars), a phone call keeps the phone. Rendered
 * from the otherwise user-invisible PHONE_CALL_* SIGNAL messages — the label derives from the signal source + the small text contract of AgentVoiceCall::buildLifecycleSignalText (`In-app call …` vs
 * `Phone call …`, duration `NNNs`, reason `reason <value>`); an unparsable text degrades to the plain label.
 */
export const ConversationCallMarkerChip = memo(({ signalSource, signalText }) => {
    const isCallEnd = signalSource === "PHONE_CALL_ENDED";
    const isInAppCall = signalText.startsWith("In-app call");
    const endReason = /reason ([A-Za-z_-]+)/.exec(signalText)?.[1];
    const durationSeconds = /(\d+)s\b/.exec(signalText)?.[1];
    const markerState = callMarkerStateOf(isCallEnd, endReason);
    const markerLabel = isInAppCall ? MARKER_LABELS[markerState].inApp : MARKER_LABELS[markerState].phoneLine;
    return (<div className={styles.markerRow} data-testid="conversation-call-marker">
      <span className={styles.markerChip} data-state={markerState}>
        <span className={styles.markerIcon}>
          {isInAppCall ? <VoiceBarsIcon size={16}/> : <Icon name="phone"/>}
        </span>
        <Text role="caption" replacements={{ agentName: getAgentDisplayName() }}>
          {markerLabel}
        </Text>
        {markerState === "ended" && durationSeconds != null && (<span className={styles.markerDuration}>
            <Text role="caption" translate={false}>
              {formatCallDuration(Number(durationSeconds))}
            </Text>
          </span>)}
      </span>
    </div>);
});
