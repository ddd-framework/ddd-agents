import { Button, Icon, Text } from "../../provider/components";
import React, { useCallback, useMemo, useState } from "react";
import styles from "./ConversationErrorCard.module.scss";
import { ConversationErrorType } from "../store/conversation-error-type";
import { useAgentsBudgetLimit } from "../../provider/AgentsProvider";

/** The first day of next month as `DD.MM.YYYY` — the day a monthly budget resets. */
const formatFirstDayOfNextMonth = (now: Date): string => {
  const firstDayOfNextMonth = new Date(now.getFullYear(), now.getMonth() + 1, 1);
  const day = String(firstDayOfNextMonth.getDate()).padStart(2, "0");
  const month = String(firstDayOfNextMonth.getMonth() + 1).padStart(2, "0");
  return `${day}.${month}.${firstDayOfNextMonth.getFullYear()}`;
};

export interface ConversationErrorCardProps {
  errorType: ConversationErrorType;
  /** Retry-After seconds of the 429 — only meaningful for RATE_LIMIT; picks the "try again in …" wording. */
  rateLimitRetryAfterSeconds?: number | null;
  /**
   * Retry action for a generic run failure whose customer message is persisted but unanswered: the HOST decides eligibility and binds the call to ITS chat slice (the AI-extensions chat and the agent
   * widget mount this card on separate Redux partitions), so the card only renders the action when it is given one.
   * Resolves when the retry was accepted; rejects when there is nothing to retry — the card then keeps showing.
   */
  onRetry?: () => Promise<void>;
}

export const ConversationErrorCard = (props: ConversationErrorCardProps) => {
  const { errorType, rateLimitRetryAfterSeconds, onRetry } = props;
  const [retrying, setRetrying] = useState(false);
  // The app's budget: whether an upgrade lifts the limit, and the fair-use wording (resellers that present the AI
  // budget as a fair-use allowance).
  const budgetLimit = useAgentsBudgetLimit();
  const isUpSellPossible = budgetLimit.canUpgrade;
  const showFairUsageMessage = budgetLimit.showsFairUseWording;

  const canRetry = errorType === ConversationErrorType.AGENT_ERROR && onRetry !== undefined;

  const handleRetry = useCallback(async () => {
    if (!onRetry || retrying) {
      return;
    }
    setRetrying(true);
    try {
      await onRetry();
      // Accepted: the host cleared the card / flipped the thinking indicator; nothing left to do here.
    } catch (error) {
      // Rejected (nothing to retry / conversation gone / network): keep the card, re-enable the action.
      console.warn("Conversation retry rejected", error);
      setRetrying(false);
    }
  }, [onRetry, retrying]);

  const { icon, title, description, replacements } = useMemo(() => {
    let title = "Network error";
    let description = "We’re sorry, something went wrong. Please refresh the page or try again later.";
    let replacements: Record<string, string> | undefined = undefined;
    if (errorType === ConversationErrorType.AI_BUDGET) {
      title = "Limit reached";
      if (showFairUsageMessage) {
        description = isUpSellPossible
          ? "You've reached your fair use limit for this month. This limit will reset on %resetDate%, or you can upgrade now to continue without interruption."
          : "You've reached your fair use limit for this month. This limit will reset on %resetDate%.";
        replacements = { resetDate: formatFirstDayOfNextMonth(new Date()) };
      } else {
        description = isUpSellPossible
          ? "You've reached your maximum monthly usage. This limit resets on %limitResetDate%, or you can upgrade now to continue without interruption."
          : "You've reached your maximum monthly usage. This limit resets on %limitResetDate%.";
        replacements = { limitResetDate: formatFirstDayOfNextMonth(new Date()) };
      }
    }
    if (errorType === ConversationErrorType.RATE_LIMIT) {
      title = "Usage limit reached";
      if (!rateLimitRetryAfterSeconds || rateLimitRetryAfterSeconds <= 90) {
        description = "You've sent a lot of AI requests in a short time. Please wait a minute and try again.";
      } else if (rateLimitRetryAfterSeconds <= 3600) {
        description =
          "You've reached your AI usage limit for this hour. Please try again in about %retryAfterMinutes% minutes.";
        replacements = { retryAfterMinutes: `${Math.ceil(rateLimitRetryAfterSeconds / 60)}` };
      } else {
        description = "You've reached your AI usage limit for today. Please try again later.";
      }
    }
    if (errorType === ConversationErrorType.AGENT_ERROR) {
      title = "Something went wrong";
      if (canRetry) {
        description = "We’re sorry, something went wrong. Your message is saved — retry to have it answered.";
      }
    }
    if (errorType === ConversationErrorType.CONVERSATION_CLOSED) {
      title = "Conversation closed";
      description = "This conversation is completed and closed. Please start a new conversation.";
    }
    return {
      icon: "warning" as const,
      title,
      description,
      replacements,
    };
  }, [errorType, isUpSellPossible, rateLimitRetryAfterSeconds, showFairUsageMessage, canRetry]);

  return (
    <div className={styles.errorMessageContainer}>
      <div>
        <div className={styles.errorMessageTitle}>
          <Icon name={icon} />
          <Text weight="bold">{title}</Text>
        </div>
        <Text replacements={replacements}>{description}</Text>
      </div>
      {errorType === ConversationErrorType.AI_BUDGET && isUpSellPossible ? (
        <Button size="extraLarge" onClick={budgetLimit.openUpgrade} variant="shimmer">
          Upgrade Plan
        </Button>
      ) : null}
      {canRetry ? (
        <Button size="extraLarge" onClick={handleRetry} variant="shimmer" disabled={retrying}>
          Retry
        </Button>
      ) : null}
    </div>
  );
};
