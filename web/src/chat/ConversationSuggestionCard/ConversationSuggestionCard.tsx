import { Icon, InteractiveSurface, Text } from "../../provider/components";
import type { ChatAssistantOpening } from "../chat-assistant-identity";
import React from "react";
import styles from "./ConversationSuggestionCard.module.scss";
import { classNames } from "../../provider/class-names";
import { useAgentsEnvironment } from "../../provider/AgentsProvider";

export interface ConversationSuggestionCardProps {
  suggestion: ChatAssistantOpening;
  onClick?: () => void;
  testId?: string;
}

export const ConversationSuggestionCard = (props: ConversationSuggestionCardProps) => {
  const { suggestion, onClick, testId } = props;
  const { AssistantIconBadge } = useAgentsEnvironment();

  return (
    <InteractiveSurface hoverable className={classNames(styles.suggestionCard)} testId={testId}>
      <button type="button" className={styles.suggestionCardButton} onClick={onClick}>
        {AssistantIconBadge ? (
          <AssistantIconBadge
            iconName={suggestion.iconClass ?? ""}
            color="white"
            backgroundColor="rgba(var(--agents-neutral-000-rgb, white), 0.2)"
          />
        ) : null}
        <div>
          <Text role="help" weight="bold" color={"white"}>
            {suggestion.name}
          </Text>
          <Text role="caption" color={"white"}>
            {suggestion.description}
          </Text>
        </div>
        <Icon color="white" className={styles.arrowIcon} name="arrowRight" />
      </button>
    </InteractiveSurface>
  );
};
