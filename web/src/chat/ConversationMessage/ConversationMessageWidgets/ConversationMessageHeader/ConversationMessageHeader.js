import { Icon, Text } from "../../../../provider/components";
import React, { memo } from "react";
import styles from "./ConversationMessageHeader.module.scss";
export const ConversationMessageHeader = memo((props) => {
    const { aiPromptExtensionCategory, aiPromptExtension } = props;
    // The extension's icon class is an icon of the app's icon set.
    const iconName = aiPromptExtension?.iconClass ?? "ai";
    return (<div className={styles.responseHeader}>
      <span className={styles.iconContainer}>
        <Icon color="var(--agents-fg-on-accent)" hostIconName={iconName}/>
      </span>
      <div>
        <Text role="help" weight="bold">
          {aiPromptExtension?.name ?? "AI Extensions"}
        </Text>
        {aiPromptExtensionCategory !== undefined ? (<Text role="caption" replacements={{ productName: aiPromptExtensionCategory?.name }}>
            %productName% Assistant
          </Text>) : null}
        {aiPromptExtensionCategory === undefined ? (<Text role="caption">Assistant</Text>) : null}
      </div>
    </div>);
});
