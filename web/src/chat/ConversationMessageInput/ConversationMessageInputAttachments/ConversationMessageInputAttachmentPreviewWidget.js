import { Button, ProgressBar, Text } from "../../../provider/components";
import React from "react";
import styles from "./ConversationMessageInputAttachmentPreviewWidget.module.scss";
import { classNames } from "../../../provider/class-names";
import { convertBytesToSizeString } from "./convert-bytes-to-size-string";
import { useAgentsTexts } from "../../../provider/AgentsProvider";
/**
 * One attachment card above the chat input: an image thumbnail (or a generic name/type/size card
 * for future non-image types), an upload progress bar while the media-library upload runs, a
 * failed state, and a bin that is always in view to remove it (only when the host passes `onRemove`).
 *
 * While uploading, a white caption with a black shadow sits over the image bottom:
 * "Uploading (45%)..." while bytes are still going out, "Processing..." once all bytes are sent
 * (progress 100%) but the media-library call hasn't resolved — the bar goes indeterminate for that
 * stretch. Both disappear when the upload settles.
 */
export const ConversationMessageInputAttachmentPreviewWidget = (props) => {
    const { attachment, onRemove, testId = "chat-input-attachment" } = props;
    const texts = useAgentsTexts();
    const isUploading = attachment.uploadStatus === "uploading";
    const hasFailed = attachment.uploadStatus === "failed";
    // All bytes sent, but the media-library call hasn't resolved yet.
    const isProcessingAfterUpload = isUploading && attachment.uploadProgressPercent >= 100;
    const fileTypeLabel = attachment.mimeType?.split("/").pop()?.toUpperCase();
    return (<div className={classNames(styles.card, {
            [styles.cardFailed]: hasFailed,
            [styles.cardGenericFile]: !attachment.previewImageSrc,
        })} data-testid={`${testId}-card`}>
      {attachment.previewImageSrc ? (<img className={styles.thumbnail} src={attachment.previewImageSrc} alt={attachment.fileName ?? ""}/>) : (<div className={styles.fileFacts}>
          <Text role="help" color="var(--agents-fg)" textWrap="nowrap" translate={false}>
            {attachment.fileName ?? ""}
          </Text>
          <Text role="caption" color="var(--agents-fg-light)" textWrap="nowrap" translate={false}>
            {[fileTypeLabel, attachment.fileSizeBytes ? convertBytesToSizeString(attachment.fileSizeBytes) : undefined]
                .filter(Boolean)
                .join(" • ")}
          </Text>
        </div>)}
      {isUploading && (<div className={styles.uploadProgress} data-testid={`${testId}-upload-progress`}>
          <Text className={styles.uploadStatusCaption} role="caption" color="var(--agents-neutral-000)" replacements={{ uploadProgressPercent: `${attachment.uploadProgressPercent}%` }} testId={`${testId}-upload-status`}>
            {isProcessingAfterUpload ? "Processing..." : "Uploading (%uploadProgressPercent%)..."}
          </Text>
          <ProgressBar value={attachment.uploadProgressPercent} indeterminate={!(attachment.uploadProgressPercent > 0 && !isProcessingAfterUpload)} color="var(--agents-primary-500)" height="4px"/>
        </div>)}
      {hasFailed && (<Text className={styles.failedCaption} role="caption" color="var(--agents-danger-500)" testId={`${testId}-failed-caption`}>
          Upload failed
        </Text>)}
      {/* Own holder because the component library's Button puts `className` on the inner <button>, not on its
            in-flow wrapper span — the holder is what actually positions the bin. */}
      {onRemove !== undefined && (<span className={styles.removeButtonHolder}>
          <Button className={styles.removeButton} variant="muted" size="small" icon="trash" ariaLabel={texts.translate("Remove file")} onClick={onRemove} testId={`${testId}-remove-button`} rounded/>
        </span>)}
    </div>);
};
