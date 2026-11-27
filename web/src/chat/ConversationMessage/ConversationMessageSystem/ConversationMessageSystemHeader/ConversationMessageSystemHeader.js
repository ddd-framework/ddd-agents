import { Button, Icon, Text } from "../../../../provider/components";
import React, { memo } from "react";
import styles from "./ConversationMessageSystemHeader.module.scss";
export const ConversationMessageSystemHeader = memo((props) => {
    const { aiPromptExtensionCategory, aiPromptExtension, isContentExpanded, onExpandContentClick } = props;
    return (<div className={styles.responseHeaderLayout}>
      <div className={styles.responseHeader}>
        <span className={styles.iconContainer}>
          <Icon color="var(--agents-fg-on-accent)" name="wizard"/>
        </span>
        <div>
          <Text role="help" weight="bold">
            {aiPromptExtension?.name ?? "AI Extensions"}
          </Text>
          {aiPromptExtensionCategory !== undefined ? (<Text role="caption">{aiPromptExtensionCategory?.name} System</Text>) : null}
          {aiPromptExtensionCategory === undefined ? (<Text role="caption">System</Text>) : null}
        </div>
      </div>
      <div className={styles.responseHeaderAction}>
        <Button variant="muted" icon={isContentExpanded ? "caretUp" : "caretDown"} onClick={onExpandContentClick}/>
      </div>
    </div>);
});
