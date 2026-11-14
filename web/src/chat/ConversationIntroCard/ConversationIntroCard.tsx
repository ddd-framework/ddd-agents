import { Text } from "../../provider/components";
import type { ChatAssistantIdentity, ChatAssistantOpening } from "../chat-assistant-identity";
import React from "react";
import { useAgentsEnvironment } from "../../provider/AgentsProvider";
import styles from "../Conversation.module.scss";
import { ConversationSuggestionCard } from "../ConversationSuggestionCard/ConversationSuggestionCard";

interface ConversationIntroCardProps {
  aiPromptExtension: ChatAssistantIdentity;
  /** The icon class of the extension, an icon of the app's icon set. */
  aiPromptExtensionIcon: string;
  suggestions?: ChatAssistantOpening[];
  onSuggestionClick: (text: string) => void;
}

export const ConversationIntroCard = ({
  aiPromptExtension,
  aiPromptExtensionIcon,
  suggestions,
  onSuggestionClick,
}: ConversationIntroCardProps) => {
  const { AssistantIconBadge } = useAgentsEnvironment();
  return (
    <div className={styles.extensionIntro}>
      {AssistantIconBadge ? (
        <AssistantIconBadge
          iconName={aiPromptExtensionIcon}
          color="white"
          backgroundColor="var(--agents-assistant-400)"
          isLarge
        />
      ) : null}
      <Text
        role="intro"
        weight="bold"
        textAlign={"center"}
        className={styles.extensionIntroTitle}
      >
        {aiPromptExtension.name}
      </Text>
      <Text
        role="body"
        textAlign={"center"}
        color="var(--agents-fg-light)"
        className={styles.extensionIntroDescription}
      >
        {aiPromptExtension.description}
      </Text>
      <div className={styles.suggestions}>
        {suggestions?.map((suggestion, index) => (
          <ConversationSuggestionCard
            key={"suggestion_prop_" + index}
            suggestion={suggestion}
            onClick={() => onSuggestionClick(suggestion.openingText!)}
          />
        ))}
      </div>
    </div>
  );
};
