import { Button, Text, type AgentsTextReplacements } from "../../../provider/components";
import React, { useEffect, useRef } from "react";
import styles from "./ConversationMessageInputAttachmentsErrorBox.module.scss";

export const ERROR_BOX_AUTO_DISMISS_AFTER_MS = 5000;

export interface ConversationMessageInputAttachmentsErrorBoxProps {
  /** Validation errors of ONE invalid selection, straight from `MediaItemFile.validationErrors`. */
  errors: { text: string; replacements?: AgentsTextReplacements }[];
  onDismiss: () => void;
  /** Stories pass a short value so play-tests don't wait 5 real seconds. */
  autoDismissAfterMs?: number;
  testId?: string;
}

/**
 * The single error box above the chat input for an invalid file selection. Auto-dismisses after
 * `autoDismissAfterMs` with a visible countdown bar (CSS animation — the timeout and the bar share
 * the duration and pause/resume together), or immediately via the ✕. Hovering the box pauses the
 * countdown (timer AND bar) so the user can finish reading; leaving resumes with the remaining
 * time. The HOST re-arms it for a new invalid selection by remounting (`key` bump) — mount is the
 * only moment the timer starts.
 */
export const ConversationMessageInputAttachmentsErrorBox = (
  props: ConversationMessageInputAttachmentsErrorBoxProps,
) => {
  const {
    errors,
    onDismiss,
    autoDismissAfterMs = ERROR_BOX_AUTO_DISMISS_AFTER_MS,
    testId = "chat-input-attachments",
  } = props;

  const remainingDismissMsRef = useRef(autoDismissAfterMs);
  const dismissTimeoutIdRef = useRef<number | undefined>(undefined);
  const dismissTimeoutStartedAtMsRef = useRef(0);

  const startDismissTimeout = () => {
    dismissTimeoutStartedAtMsRef.current = Date.now();
    dismissTimeoutIdRef.current = window.setTimeout(onDismiss, remainingDismissMsRef.current);
  };

  const pauseDismissTimeout = () => {
    window.clearTimeout(dismissTimeoutIdRef.current);
    remainingDismissMsRef.current -= Date.now() - dismissTimeoutStartedAtMsRef.current;
  };

  useEffect(() => {
    startDismissTimeout();
    return () => window.clearTimeout(dismissTimeoutIdRef.current);
    // Mount-only by design: the host re-arms via remount.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div
      className={styles.errorBox}
      onMouseEnter={pauseDismissTimeout}
      onMouseLeave={startDismissTimeout}
      data-testid={`${testId}-error-box`}
    >
      <div className={styles.errorLines}>
        {errors.map((error, errorIndex) => (
          <Text key={errorIndex} role="caption" color="var(--agents-danger-500)" replacements={error.replacements}>
            {error.text}
          </Text>
        ))}
      </div>
      <Button
        className={styles.dismissButton}
        variant="muted"
        size="small"
        icon="close"
        onClick={onDismiss}
        testId={`${testId}-error-box-dismiss`}
        rounded
      />
      <div
        className={styles.countdownBar}
        style={{ animationDuration: `${autoDismissAfterMs}ms` }}
        data-testid={`${testId}-error-box-countdown`}
      />
    </div>
  );
};
