import { useAgentsComponents } from "../../provider/AgentsProvider";
import { DEFAULT_AGENTS_COMPONENTS } from "../../provider/components/default-components";
import { getAgentDisplayName } from "../../provider/environment";
import { textKey } from "../../provider/text-key";
import { startAgentVoiceCall, useIsAgentVoiceCallActive } from "../call/agent-voice-call-store";
import { VoiceBarsIcon } from "../../chat/ConversationMessage/ConversationCallMarkerChip/VoiceBarsIcon/VoiceBarsIcon";
import { useAgentVoiceTranslateText } from "../AgentVoiceCallProvider/agent-voice-call-context";
import styles from "./AgentVoiceChatStartButton.module.scss";
/** The button's label: its accessible name and its tooltip. */
const LABEL_TEXT_KEY = textKey("Talk to %agentName%");
/**
 * In a chat input's send spot while nothing is typed and no voice chat runs: starts a voice chat with the agent. An
 * input outside the chat uses it too; the application shows the call's conversation when the provider reports it
 * (`onConversationOfCallChanged`). Disabled while a voice chat is connecting or live.
 *
 * Its label shows through the environment's tooltip when the application replaces the package's `Tooltip`
 * (`AgentsEnvironment.components.Tooltip`); otherwise it is the button's `title`, as the browser shows it.
 */
export const AgentVoiceChatStartButton = (props) => {
    const { agentSpaceId, conversationId, disabled = false, onStart, testId = "agent-voice-chat-start" } = props;
    const translateText = useAgentVoiceTranslateText();
    const isCallActive = useIsAgentVoiceCallActive();
    const { Tooltip } = useAgentsComponents();
    const hasEnvironmentTooltip = Tooltip !== DEFAULT_AGENTS_COMPONENTS.Tooltip;
    const label = translateText(LABEL_TEXT_KEY);
    const startButton = (<button type="button" className={styles.startButton} onClick={() => {
            onStart?.();
            void startAgentVoiceCall({ agentSpaceId, conversationId });
        }} disabled={disabled || isCallActive} aria-label={label} title={hasEnvironmentTooltip ? undefined : label} data-testid={testId}>
      <VoiceBarsIcon size={18}/>
    </button>);
    if (!hasEnvironmentTooltip)
        return startButton;
    // Wrapped: a disabled button receives no pointer events, the wrapper does.
    return (<Tooltip text={LABEL_TEXT_KEY} replacements={{ agentName: getAgentDisplayName() }} placement="top" wrapsChild>
      {startButton}
    </Tooltip>);
};
