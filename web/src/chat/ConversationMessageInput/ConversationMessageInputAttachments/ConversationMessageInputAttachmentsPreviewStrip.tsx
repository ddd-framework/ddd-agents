import React from "react";
import styles from "./ConversationMessageInputAttachmentsPreviewStrip.module.scss";
import { classNames } from "../../../provider/class-names";
import {
  ConversationMessageInputAttachmentPreview,
  ConversationMessageInputAttachmentPreviewCard,
} from "./ConversationMessageInputAttachmentPreviewCard";

export interface ConversationMessageInputAttachmentsPreviewStripProps {
  attachments: ConversationMessageInputAttachmentPreview[];
  onRemoveAttachment: (attachmentId: string) => void;
  testId?: string;
}

/**
 * Horizontal strip of attachment cards above the chat input's text area. Grows in / shrinks out
 * with the grid-rows 0fr→1fr reveal (same trick as the selection chip) instead of popping; scrolls
 * horizontally when the cards outgrow the input's width.
 */
export const ConversationMessageInputAttachmentsPreviewStrip = (
  props: ConversationMessageInputAttachmentsPreviewStripProps,
) => {
  const { attachments, onRemoveAttachment, testId = "chat-input-attachments" } = props;

  return (
    <div
      className={classNames(styles.stripReveal, { [styles.stripRevealOpen]: attachments.length > 0 })}
      data-testid={`${testId}-strip`}
    >
      <div className={styles.stripRevealInner}>
        <div className={styles.strip}>
          {attachments.map((attachment) => (
            <ConversationMessageInputAttachmentPreviewCard
              key={attachment.attachmentId}
              attachment={attachment}
              onRemove={() => onRemoveAttachment(attachment.attachmentId)}
              testId={`${testId}-${attachment.attachmentId}`}
            />
          ))}
        </div>
      </div>
    </div>
  );
};
