import { Skeleton } from "../provider/components";
import React from "react";
import styles from "./Conversation.module.scss";
import loadingStyles from "./ConversationLoadingStates.module.scss";

const SKEL_RADIUS = "var(--agents-skeleton-radius)";

export const NoConversationLoading = () => {
  return (
    <div className={styles.extensionIntro}>
      <Skeleton className={loadingStyles.avatar} borderRadius={SKEL_RADIUS} />
      <Skeleton className={loadingStyles.title} />
      <Skeleton className={loadingStyles.subtitle} />
      <div className={styles.suggestions}>
        {Array.from({ length: 3 }, (_, index) => (
          <Skeleton key={index} className={loadingStyles.suggestionCard} borderRadius={SKEL_RADIUS} />
        ))}
      </div>
    </div>
  );
};

export const ConversationLoading = () => {
  return (
    <div className={styles.chatMessages}>
      <Skeleton className={loadingStyles.userBubble} borderRadius={SKEL_RADIUS} />
      <div className={loadingStyles.messageHeader}>
        <Skeleton className={loadingStyles.messageAvatar} borderRadius={SKEL_RADIUS} />
        <Skeleton className={loadingStyles.messageAuthor} />
      </div>
      <Skeleton className={loadingStyles.messageBody} borderRadius={SKEL_RADIUS} />
    </div>
  );
};
