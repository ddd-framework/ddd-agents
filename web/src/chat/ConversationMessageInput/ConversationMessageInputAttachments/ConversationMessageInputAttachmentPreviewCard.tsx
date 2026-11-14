import { Button, ProgressBar, Text } from "../../../provider/components";
import React from "react";
import styles from "./ConversationMessageInputAttachmentPreviewCard.module.scss";
import { classNames } from "../../../provider/class-names";
import { convertBytesToSizeString } from "./convert-bytes-to-size-string";

export type ConversationMessageInputAttachmentUploadStatus = "uploading" | "uploaded" | "failed";

/**
 * Presentational shape of one attachment above the chat input. The host's attachment state
 * (e.g. `useAgentChatAttachments`' `AgentChatAttachment`) is a superset of this.
 */
export type ConversationMessageInputAttachmentPreview = {
  attachmentId: string;
  /** Data-URI of the image — renders the thumbnail card. Absent → generic file card. */
  previewImageSrc?: string;
  /** Generic-card fields (image cards show the thumbnail instead). */
  fileName?: string;
  fileSizeBytes?: number;
  mimeType?: string;
  uploadStatus: ConversationMessageInputAttachmentUploadStatus;
  /** 0-100; only meaningful while `uploadStatus` is "uploading". */
  uploadProgressPercent: number;
};

export interface ConversationMessageInputAttachmentPreviewCardProps {
  attachment: ConversationMessageInputAttachmentPreview;
  /** The card's ✕ — the host drops the attachment (and discards its in-flight upload result). */
  onRemove: () => void;
  testId?: string;
}

/**
 * One attachment card above the chat input: an image thumbnail (or a generic name/type/size card
 * for future non-image types), an upload progress bar while the media-library upload runs, a
 * failed state, and a ✕ to remove.
 *
 * While uploading, a white caption with a black shadow sits over the image bottom:
 * "Uploading (45%)..." while bytes are still going out, "Processing..." once all bytes are sent
 * (progress 100%) but the media-library call hasn't resolved — the bar goes indeterminate for that
 * stretch. Both disappear when the upload settles.
 */
export const ConversationMessageInputAttachmentPreviewCard = (
  props: ConversationMessageInputAttachmentPreviewCardProps,
) => {
  const { attachment, onRemove, testId = "chat-input-attachment" } = props;

  const isUploading = attachment.uploadStatus === "uploading";
  const hasFailed = attachment.uploadStatus === "failed";
  // All bytes sent, but the media-library call hasn't resolved yet.
  const isProcessingAfterUpload = isUploading && attachment.uploadProgressPercent >= 100;
  const fileTypeLabel = attachment.mimeType?.split("/").pop()?.toUpperCase();

  return (
    <div
      className={classNames(styles.card, {
        [styles.cardFailed]: hasFailed,
        [styles.cardGenericFile]: !attachment.previewImageSrc,
      })}
      data-testid={`${testId}-card`}
    >
      {attachment.previewImageSrc ? (
        <img className={styles.thumbnail} src={attachment.previewImageSrc} alt={attachment.fileName ?? ""} />
      ) : (
        <div className={styles.fileFacts}>
          <Text role="help" color="var(--agents-fg)" textWrap="nowrap" translate={false}>
            {attachment.fileName ?? ""}
          </Text>
          <Text role="caption" color="var(--agents-fg-light)" textWrap="nowrap" translate={false}>
            {[fileTypeLabel, attachment.fileSizeBytes ? convertBytesToSizeString(attachment.fileSizeBytes) : undefined]
              .filter(Boolean)
              .join(" • ")}
          </Text>
        </div>
      )}
      {isUploading && (
        <div className={styles.uploadProgress} data-testid={`${testId}-upload-progress`}>
          <Text
            className={styles.uploadStatusCaption}
            role="caption"
            color="var(--agents-neutral-000)"
            replacements={{ uploadProgressPercent: `${attachment.uploadProgressPercent}%` }}
            testId={`${testId}-upload-status`}
          >
            {isProcessingAfterUpload ? "Processing..." : "Uploading (%uploadProgressPercent%)..."}
          </Text>
          <ProgressBar
            value={attachment.uploadProgressPercent}
            indeterminate={!(attachment.uploadProgressPercent > 0 && !isProcessingAfterUpload)}
            color="var(--agents-primary-500)"
            height="4px"
          />
        </div>
      )}
      {hasFailed && (
        <Text
          className={styles.failedCaption}
          role="caption"
          color="var(--agents-danger-500)"
          testId={`${testId}-failed-caption`}
        >
          Upload failed
        </Text>
      )}
      {/* Own holder because the component library's Button puts `className` on the inner <button>, not on its
          in-flow wrapper span — the holder is what actually positions the ✕. */}
      <span className={styles.removeButtonHolder}>
        <Button
          className={styles.removeButton}
          variant="muted"
          size="small"
          icon="close"
          onClick={onRemove}
          testId={`${testId}-remove-button`}
          rounded
        />
      </span>
    </div>
  );
};
