import React, { memo, useState } from "react";
import type { ChatAssistantCategory } from "../../chat-assistant-identity";
import styles from "./ConversationMessageSystem.module.scss";
import { Markdown } from "../../../provider/components";
import { ConversationMessageSystemHeader } from "./ConversationMessageSystemHeader/ConversationMessageSystemHeader";

export interface ConversationMessageSystemProps {
  aiPromptExtensionCategory?: ChatAssistantCategory;
  aiPromptExtension?: ChatAssistantCategory;
  message: string;
}

export const ConversationMessageSystem = memo((props: ConversationMessageSystemProps) => {
  const { aiPromptExtensionCategory, aiPromptExtension, message } = props;

  const [isContentExpanded, setIsContentExpanded] = useState(false);

  return (
    <div className={styles.responseContainer}>
      <ConversationMessageSystemHeader
        aiPromptExtensionCategory={aiPromptExtensionCategory}
        aiPromptExtension={aiPromptExtension}
        onExpandContentClick={() => setIsContentExpanded((v) => !v)}
        isContentExpanded={isContentExpanded}
      />

      <div style={{ display: isContentExpanded ? undefined : "none" }}>
        <div className={styles.responseMessage}>
          <Markdown markdown={message} />
        </div>
      </div>
    </div>
  );
});
