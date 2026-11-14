import { Icon, Text } from "../../../provider/components";
import { getAgentDisplayName } from "../../../provider/environment";
import React, { memo } from "react";
import { VoiceBarsIcon } from "./VoiceBarsIcon/VoiceBarsIcon";
import styles from "./ConversationCallMarkerChip.module.scss";

export interface ConversationCallMarkerChipProps {
  /** PHONE_CALL_STARTED | PHONE_CALL_ENDED — the lifecycle signal's source. */
  signalSource: string;
  /** The signal's model-visible text — carries the duration as `NNNs` and the end reason as `reason <value>`. */
  signalText: string;
}

/** `123` → `2:03 min` for the chip; sub-minute stays as seconds. */
const formatCallDuration = (durationSeconds: number): string => {
  if (durationSeconds < 60) return `${durationSeconds}s`;
  const minutes = Math.floor(durationSeconds / 60);
  const seconds = durationSeconds % 60;
  return `${minutes}:${String(seconds).padStart(2, "0")} min`;
};

type CallMarkerState = "started" | "ended" | "declined" | "missed" | "notConnected";

const MARKER_LABELS: Readonly<Record<CallMarkerState, { inApp: string; phoneLine: string }>> = {
  started: { inApp: "Voice chat started", phoneLine: "Phone call started" },
  ended: { inApp: "Voice chat ended", phoneLine: "Phone call ended" },
  declined: { inApp: "You declined a voice chat with %agentName%", phoneLine: "Phone call declined" },
  missed: { inApp: "You missed a voice chat with %agentName%", phoneLine: "Phone call not answered" },
  notConnected: { inApp: "Voice chat did not connect", phoneLine: "Phone call not answered" },
};

/** The agent's ring the owner turned down (DECLINED) or let run out (EXPIRED, NOT_ANSWERED) is its own mark in the chat. */
const callMarkerStateOf = (isCallEnd: boolean, endReason: string | undefined): CallMarkerState => {
  if (!isCallEnd) return "started";
  if (endReason === "DECLINED") return "declined";
  if (endReason === "EXPIRED" || endReason === "NOT_ANSWERED") return "missed";
  if (endReason === "FAILED") return "notConnected";
  return "ended";
};

/**
 * Centered marker for a call's lifecycle inside the chat timeline: started, ended (with its duration), declined, missed
 * or not connected; an in-app call is a voice chat with the agent (its voice bars), a phone call keeps the phone. Rendered
 * from the otherwise user-invisible PHONE_CALL_* SIGNAL messages — the label derives from the signal source + the small text contract of AgentVoiceCall::buildLifecycleSignalText (`In-app call …` vs
 * `Phone call …`, duration `NNNs`, reason `reason <value>`); an unparsable text degrades to the plain label.
 */
export const ConversationCallMarkerChip = memo(({ signalSource, signalText }: ConversationCallMarkerChipProps) => {
  const isCallEnd = signalSource === "PHONE_CALL_ENDED";
  const isInAppCall = signalText.startsWith("In-app call");
  const endReason = /reason ([A-Za-z_-]+)/.exec(signalText)?.[1];
  const durationSeconds = /(\d+)s\b/.exec(signalText)?.[1];
  const markerState = callMarkerStateOf(isCallEnd, endReason);
  const markerLabel = isInAppCall ? MARKER_LABELS[markerState].inApp : MARKER_LABELS[markerState].phoneLine;

  return (
    <div className={styles.markerRow} data-testid="conversation-call-marker">
      <span className={styles.markerChip} data-state={markerState}>
        <span className={styles.markerIcon}>
          {isInAppCall ? <VoiceBarsIcon size={16} /> : <Icon name="phone" />}
        </span>
        <Text role="caption" replacements={{ agentName: getAgentDisplayName() }}>
          {markerLabel}
        </Text>
        {markerState === "ended" && durationSeconds != null && (
          <span className={styles.markerDuration}>
            <Text role="caption" translate={false}>
              {formatCallDuration(Number(durationSeconds))}
            </Text>
          </span>
        )}
      </span>
    </div>
  );
});
