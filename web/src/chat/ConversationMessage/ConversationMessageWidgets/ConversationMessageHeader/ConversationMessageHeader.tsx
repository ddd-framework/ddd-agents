import { Icon, Text } from "../../../../provider/components";
import type { ChatAssistantCategory, ChatAssistantIdentity } from "../../../chat-assistant-identity";
import React, { memo } from "react";
import styles from "./ConversationMessageHeader.module.scss";

export interface ConversationMessageHeaderProps {
  aiPromptExtensionCategory?: ChatAssistantCategory;
  aiPromptExtension?: ChatAssistantIdentity;
}

export const ConversationMessageHeader = memo((props: ConversationMessageHeaderProps) => {
  const { aiPromptExtensionCategory, aiPromptExtension } = props;

  // The extension's icon class is an icon of the app's icon set.
  const iconName = aiPromptExtension?.iconClass ?? "ai";

  return (
    <div className={styles.responseHeader}>
      <span className={styles.iconContainer}>
        <Icon color="white" hostIconName={iconName} />
      </span>
      <div>
        <Text role="help" weight="bold">
          {aiPromptExtension?.name ?? "AI Extensions"}
        </Text>
        {aiPromptExtensionCategory !== undefined ? (
          <Text role="caption" replacements={{ productName: aiPromptExtensionCategory?.name }}>
            %productName% Assistant
          </Text>
        ) : null}
        {aiPromptExtensionCategory === undefined ? (
          <Text role="caption">Assistant</Text>
        ) : null}
      </div>
    </div>
  );
});
