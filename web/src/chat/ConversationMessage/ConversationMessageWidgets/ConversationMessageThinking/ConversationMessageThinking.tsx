import { Text } from "../../../../provider/components";
import type { ChatAssistantCategory, ChatAssistantIdentity } from "../../../chat-assistant-identity";
import React, { memo, useState, useEffect } from "react";
import styles from "./ConversationMessageThinking.module.scss";
import { ConversationMessageHeader } from "../ConversationMessageHeader/ConversationMessageHeader";

const loadingStateMessages = [
  "Gathering business data…",
  "Planning what to do…",
  "Working on it…",
  "Clarifying response…",
  "Almost ready…",
  "Something new came up…",
];

export interface ConversationMessageThinkingProps {
  aiPromptExtensionCategory?: ChatAssistantCategory;
  aiPromptExtension?: ChatAssistantIdentity;
}

export const ConversationMessageThinking = memo((props: ConversationMessageThinkingProps) => {
  const { aiPromptExtensionCategory, aiPromptExtension } = props;
  const [currentIndex, setCurrentIndex] = useState(0);

  useEffect(() => {
    const interval = setInterval(() => {
      setCurrentIndex((prevIndex) => (prevIndex + 1) % loadingStateMessages.length);
    }, 2500);

    return () => clearInterval(interval);
  }, []);

  return (
    <div className={styles.responseContainer}>
      <ConversationMessageHeader
        aiPromptExtensionCategory={aiPromptExtensionCategory}
        aiPromptExtension={aiPromptExtension}
      />

      <div className={styles.thinkingMessage}>
        <div className={styles.thinkingDots}>
          <span />
          <span />
          <span />
        </div>
        <Text>{loadingStateMessages[currentIndex]}</Text>
      </div>
    </div>
  );
});
