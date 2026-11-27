import { Icon, Text } from "../../provider/components";
import { classNames } from "../../provider/class-names";
import { translateText } from "../../provider/environment";
import { useFilePictureUrl } from "../_shared/file-preview-source";
import styles from "./AttachedFilesConversationWidget.module.scss";
const AttachedFileThumb = ({ file }) => {
    const picture = useFilePictureUrl(file.isImage ? file.reference : null, "THUMBNAIL");
    return (<span className={styles.thumb} aria-hidden>
      {file.isImage && picture.url !== null ? (<img src={picture.url} alt=""/>) : (<Icon name={file.isImage ? "image" : "document"} pixelSize={18}/>)}
    </span>);
};
/**
 * The attached files as chips: in the owner's message (read-only), and in the host's composer above the text
 * (`AgentChat`'s `inputTopSlot`) with a remove button per file and the uploads still running.
 */
export const AttachedFilesStrip = ({ files, uploads = [], onRemove, onDismissUpload, inComposer = false, testId = "attached-files-widget", }) => {
    if (files.length === 0 && uploads.length === 0)
        return null;
    return (<div className={classNames(styles.strip, inComposer && styles.stripInComposer)} data-testid={testId}>
      {files.map((file) => (<div key={file.reference} className={styles.chip} title={file.reference} data-testid={`${testId}-file`}>
          <AttachedFileThumb file={file}/>
          <Text role="help" translate={false} ellipsis className={styles.name}>
            {file.name}
          </Text>
          {onRemove && (<button type="button" className={styles.remove} aria-label={translateText("Remove %name%", { name: file.name })} onClick={() => onRemove(file.reference)}>
              <Icon name="close" pixelSize={10}/>
            </button>)}
        </div>))}
      {uploads.map((upload) => (<div key={upload.key} className={styles.chip} data-testid={`${testId}-upload`}>
          <span className={styles.thumb} aria-hidden>
            <Icon name={upload.state === "uploading" ? "clock" : "exclamation"} pixelSize={18}/>
          </span>
          <span className={styles.state}>
            <Text role="help" translate={false} ellipsis>
              {upload.name}
            </Text>
            {upload.state === "uploading" ? (<Text role="caption">Uploading…</Text>) : (<Text role="caption" translate={false} ellipsis>
                {upload.state.failed}
              </Text>)}
          </span>
          {upload.state !== "uploading" && onDismissUpload && (<button type="button" className={styles.remove} aria-label={translateText("Remove %name%", { name: upload.name })} onClick={() => onDismissUpload(upload.key)}>
              <Icon name="close" pixelSize={10}/>
            </button>)}
        </div>))}
    </div>);
};
