import { StatusBadge } from "@mgamadeus/ddd-admin-ui/list";
import { AGENT_VOICE_CALL_END_REASON, AGENT_VOICE_CALL_STATE } from "../agent-voice-admin-format";
/**
 * Where a call stands (ringing, running, ended), as the server read it, and, once it has one, why it ended. An end
 * reason outside the known ones (a diagnostic value of a teardown path) shows as the text it came as.
 */
export const AgentVoiceCallStateBadges = ({ callState, endReason }) => (<span className="flex flex-wrap items-center gap-1.5">
    <StatusBadge value={callState} map={AGENT_VOICE_CALL_STATE}/>
    {endReason ? <StatusBadge value={endReason} map={AGENT_VOICE_CALL_END_REASON}/> : null}
  </span>);
