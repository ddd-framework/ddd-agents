// `@mgamadeus/ddd-agents/voice/admin` — the admin screens of the voice calls on the blocks of
// `@mgamadeus/ddd-admin-ui`: the api of the admin call routes (`createAgentVoiceAdminApi`, `AdminAgentVoiceCallsTrait`),
// the list of calls and one call, and the ▶ of the voice samples of a voice picker (`AdminAgentVoiceSamplesTrait`). The one entry that loads the optional peers `@mgamadeus/ddd-admin-ui`,
// `@mgamadeus/ddd-web` and `lucide-react` (`boundary.json` names them, `scripts/check-package.mjs` holds every other
// entry free of them): an application's chat installs none of the three.
export { AGENT_VOICE_CALLS_TAG, agentVoiceAdminEndpointDefinitions, buildAgentVoiceAdminCallsFilters, buildAgentVoiceAdminRequests, createAgentVoiceAdminApi, createStaticAgentVoiceAdminApi, } from "../voice/admin/agent-voice-admin-api";
export { AGENT_VOICE_ADMIN_ERROR_CALL_NOT_FOUND, AGENT_VOICE_ADMIN_ERROR_FEATURE_NOT_AVAILABLE, AGENT_VOICE_CALL_DIRECTION, AGENT_VOICE_CALL_END_REASON, AGENT_VOICE_CALL_END_REASONS, AGENT_VOICE_CALL_STATE, agentVoiceAdminCallDerivedValuesByCallIdOf, agentVoiceAdminCallDurationInSecondsOf, agentVoiceAdminCallStartTimeOf, formatAgentVoiceAdminCallCosts, isAgentVoiceAdminError, readAgentVoiceAdminWholeNumber, toAgentVoiceAdminDayBound, } from "../voice/admin/agent-voice-admin-format";
export { AgentVoiceCallConversationLink, } from "../voice/admin/AgentVoiceCallConversationLink/AgentVoiceCallConversationLink";
export { AgentVoiceCallStateBadges, } from "../voice/admin/AgentVoiceCallStateBadges/AgentVoiceCallStateBadges";
export { AGENT_VOICE_CALLS_MAX_PAGE_SIZE, AgentVoiceCallsScreen, } from "../voice/admin/AgentVoiceCallsScreen/AgentVoiceCallsScreen";
export { AgentVoiceCallDetailScreen, } from "../voice/admin/AgentVoiceCallDetailScreen/AgentVoiceCallDetailScreen";
export { AGENT_VOICE_SAMPLE_ERROR_TEXTS, AgentVoiceSampleButton, useAgentVoiceSamplePlayer, } from "../voice/samples/AgentVoiceSampleButton/AgentVoiceSampleButton";
export { agentVoiceSampleKeyOf, isAgentVoiceSampleNotFound, readAgentVoiceSamplePlayerState, resetAgentVoiceSamplePlayer, stopAgentVoiceSample, subscribeAgentVoiceSamplePlayer, toggleAgentVoiceSample, } from "../voice/samples/agent-voice-sample-player";
