import { Icon, InteractiveSurface, Text } from "../../provider/components";
import React from "react";
import styles from "./ConversationSuggestionWidget.module.scss";
import { classNames } from "../../provider/class-names";
import { useAgentsEnvironment } from "../../provider/AgentsProvider";
export const ConversationSuggestionWidget = (props) => {
    const { suggestion, onClick, testId } = props;
    const { AssistantIconBadge } = useAgentsEnvironment();
    return (<InteractiveSurface hoverable className={classNames(styles.suggestionCard)} testId={testId}>
      <button type="button" className={styles.suggestionCardButton} onClick={onClick}>
        {AssistantIconBadge ? (<AssistantIconBadge iconName={suggestion.iconClass ?? ""} color="var(--agents-fg-on-accent)" backgroundColor="color-mix(in srgb, var(--agents-neutral-000) 20%, transparent)"/>) : null}
        <div>
          <Text role="help" weight="bold" color="var(--agents-fg-on-accent)">
            {suggestion.name}
          </Text>
          <Text role="caption" color="var(--agents-fg-on-accent)">
            {suggestion.description}
          </Text>
        </div>
        <Icon color="var(--agents-fg-on-accent)" className={styles.arrowIcon} name="arrowRight"/>
      </button>
    </InteractiveSurface>);
};
