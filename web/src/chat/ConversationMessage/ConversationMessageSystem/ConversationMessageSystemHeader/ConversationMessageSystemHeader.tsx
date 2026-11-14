import { Button, Icon, Text } from "../../../../provider/components";
import type { ChatAssistantCategory, ChatAssistantIdentity } from "../../../chat-assistant-identity";
import React, { memo, useMemo } from "react";
import styles from "./ConversationMessageSystemHeader.module.scss";

export interface ConversationMessageSystemHeaderProps {
  aiPromptExtensionCategory?: ChatAssistantCategory;
  aiPromptExtension?: ChatAssistantIdentity;
  onExpandContentClick?: () => void;
  isContentExpanded?: boolean;
}

export const ConversationMessageSystemHeader = memo((props: ConversationMessageSystemHeaderProps) => {
  const { aiPromptExtensionCategory, aiPromptExtension, isContentExpanded, onExpandContentClick } = props;

  return (
    <div className={styles.responseHeaderLayout}>
      <div className={styles.responseHeader}>
        <span className={styles.iconContainer}>
          <Icon color="white" name="wizard" />
        </span>
        <div>
          <Text role="help" weight="bold">
            {aiPromptExtension?.name ?? "AI Extensions"}
          </Text>
          {aiPromptExtensionCategory !== undefined ? (
            <Text role="caption">{aiPromptExtensionCategory?.name} System</Text>
          ) : null}
          {aiPromptExtensionCategory === undefined ? (
            <Text role="caption">System</Text>
          ) : null}
        </div>
      </div>
      <div className={styles.responseHeaderAction}>
        <Button
          variant="muted"
          icon={isContentExpanded ? "caretUp" : "caretDown"}
          onClick={onExpandContentClick}
        />
      </div>
    </div>
  );
});
