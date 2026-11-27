import { createPortal } from "react-dom";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Button, Icon, Text } from "../../provider/components";
import { classNames } from "../../provider/class-names";
import { textKey } from "../../provider/text-key";
import { translateText } from "../../provider/environment";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { DonePill } from "../_shared/DonePill";
import { useFilePictureUrl } from "../_shared/file-preview-source";
import { buildImageGalleryDeclineSentence, buildImageGalleryPickSentence, } from "./shared/image-gallery-payload";
import styles from "./ImageGalleryConversationWidget.module.scss";
/** One tile: the letter, the picture (THUMBNAIL), label and caption; a click on the picture enlarges it. */
const ImageGalleryTile = ({ file, isSelectable, isSelected, isDisabled, onToggle, onEnlarge, testId, }) => {
    const picture = useFilePictureUrl(file.reference, "THUMBNAIL");
    const enlargeLabel = translateText("Enlarge picture %letter%", { letter: file.letter });
    return (<div className={classNames(styles.tile, isSelected && styles.tileSelected)} data-testid={`${testId}-tile-${file.letter}`} data-selected={isSelected ? "true" : undefined}>
      <button type="button" className={styles.picture} onClick={onEnlarge} aria-label={enlargeLabel} title={file.reference}>
        {picture.url !== null ? (<img src={picture.url} alt={file.label ?? file.reference} loading="lazy"/>) : (<span className={styles.pictureState}>
            {picture.status === "loading" ? (<span className={styles.pictureLoading}/>) : (<Text role="caption" textAlign="center">
                {picture.status === "notConnected" ? "Pictures are not connected here" : "Picture could not be loaded"}
              </Text>)}
          </span>)}
        <span className={styles.letter} aria-hidden>
          {file.letter}
        </span>
      </button>
      {(file.label || file.caption) && (<div className={styles.texts}>
          {file.label && (<Text role="help" weight="medium" translate={false} ellipsis>
              {file.label}
            </Text>)}
          {file.caption && (<Text role="caption" translate={false} ellipsis>
              {file.caption}
            </Text>)}
        </div>)}
      {isSelectable && (<button type="button" className={classNames(styles.pick, isSelected && styles.pickSelected)} onClick={onToggle} disabled={isDisabled} aria-pressed={isSelected} data-testid={`${testId}-pick-${file.letter}`}>
          {isSelected && <Icon name="check" pixelSize={14}/>}
          <Text role="help" translate={false}>
            {file.letter}
          </Text>
        </button>)}
    </div>);
};
/** The lightbox: one picture large (PREVIEW), previous / next, Escape closes. */
const ImageGalleryLightbox = ({ files, position, onPosition, onClose, testId, }) => {
    const file = files[position];
    const picture = useFilePictureUrl(file.reference, "PREVIEW");
    const dialogRef = useRef(null);
    const closeLabel = translateText("Close");
    const previousLabel = translateText("Previous picture");
    const nextLabel = translateText("Next picture");
    useEffect(() => {
        dialogRef.current?.focus();
    }, []);
    const onKeyDown = (event) => {
        if (event.key === "Escape")
            onClose();
        if (event.key === "ArrowLeft" && position > 0)
            onPosition(position - 1);
        if (event.key === "ArrowRight" && position < files.length - 1)
            onPosition(position + 1);
    };
    return createPortal(<div ref={dialogRef} className={styles.lightbox} role="dialog" aria-modal="true" aria-label={file.label ?? file.reference} tabIndex={-1} onKeyDown={onKeyDown} onClick={onClose} data-testid={`${testId}-lightbox`}>
      <div className={styles.lightboxFrame} onClick={(event) => event.stopPropagation()}>
        {picture.url !== null ? (<img src={picture.url} alt={file.label ?? file.reference}/>) : (<span className={styles.lightboxState}>
            {picture.status === "loading" ? (<span className={styles.pictureLoading}/>) : (<Text role="body" color="#fff">
                Picture could not be loaded
              </Text>)}
          </span>)}
        <div className={styles.lightboxBar}>
          <span className={styles.lightboxLetter}>{file.letter}</span>
          <Text role="help" color="#fff" translate={false} ellipsis>
            {[file.label, file.caption].filter(Boolean).join(" · ") || file.reference}
          </Text>
        </div>
      </div>
      <button type="button" className={classNames(styles.lightboxButton, styles.lightboxClose)} aria-label={closeLabel} onClick={onClose}>
        <Icon name="close" pixelSize={20} color="#fff"/>
      </button>
      {position > 0 && (<button type="button" className={classNames(styles.lightboxButton, styles.lightboxPrevious)} aria-label={previousLabel} onClick={(event) => {
                event.stopPropagation();
                onPosition(position - 1);
            }}>
          <Icon name="arrowLeft" pixelSize={20} color="#fff"/>
        </button>)}
      {position < files.length - 1 && (<button type="button" className={classNames(styles.lightboxButton, styles.lightboxNext)} aria-label={nextLabel} onClick={(event) => {
                event.stopPropagation();
                onPosition(position + 1);
            }}>
          <Icon name="arrowRight" pixelSize={20} color="#fff"/>
        </button>)}
    </div>, document.body);
};
/**
 * The gallery itself, in the message and in the canvas: lettered tiles, the lightbox, and with `selection` `one` or
 * `many` the pick. The pick and the decline post a FRONTEND_WIDGET_CLICK signal (`injectSignal`) whose sentence is the
 * stored contract of `image-gallery-widget-contract.json`; the gallery locks once the host confirms the send and shows
 * what was picked in its place.
 */
export const ImageGallery = ({ gallery, interactive, surface, testId }) => {
    const { injectSignal } = useConversationWidgetContext();
    const [selectedLetters, setSelectedLetters] = useState([]);
    const [lightboxPosition, setLightboxPosition] = useState(null);
    const [sentAnswer, setSentAnswer] = useState(null);
    const [pending, setPending] = useState(false);
    const pendingRef = useRef(false);
    const isSelectable = gallery.selection !== "none";
    const isLocked = sentAnswer !== null;
    const isDisabled = !interactive || isLocked || pending;
    const selectedFiles = useMemo(() => gallery.files.filter((file) => selectedLetters.includes(file.letter)), [gallery.files, selectedLetters]);
    const toggle = useCallback((letter) => {
        setSelectedLetters((current) => {
            if (gallery.selection === "one")
                return current.includes(letter) ? [] : [letter];
            return current.includes(letter) ? current.filter((selected) => selected !== letter) : [...current, letter];
        });
    }, [gallery.selection]);
    const send = async (sentence, answer) => {
        if (isDisabled || pendingRef.current)
            return;
        pendingRef.current = true;
        setPending(true);
        try {
            const result = await injectSignal(sentence);
            if (result !== false)
                setSentAnswer(answer);
        }
        catch {
            // not accepted: the gallery stays usable
        }
        finally {
            pendingRef.current = false;
            setPending(false);
        }
    };
    const columnsClass = surface === "canvas" ? styles.gridCanvas : gallery.files.length <= 2 ? styles.gridTwo : styles.gridInline;
    return (<div className={classNames(styles.gallery, surface === "canvas" && styles.galleryCanvas)} data-testid={testId}>
      {gallery.title && (<Text role={surface === "canvas" ? "title" : "body"} weight="medium" translate={false} testId={`${testId}-title`}>
          {gallery.title}
        </Text>)}
      <div className={classNames(styles.grid, columnsClass)} role="list">
        {gallery.files.map((file, position) => (<div role="listitem" key={file.reference}>
            <ImageGalleryTile file={file} isSelectable={isSelectable && !isLocked} isSelected={(sentAnswer?.letters ?? selectedLetters).includes(file.letter)} isDisabled={isDisabled} onToggle={() => toggle(file.letter)} onEnlarge={() => setLightboxPosition(position)} testId={testId}/>
          </div>))}
      </div>
      <div aria-live="polite">
        {sentAnswer !== null ? (<DonePill status={sentAnswer.kind === "picked" ? "success" : "neutral"} translate={false} testId={`${testId}-done`}>
            {sentAnswer.kind === "picked" ? sentAnswer.letters.join(", ") : "—"}
          </DonePill>) : (isSelectable && (<div className={styles.actions}>
              <Button variant="muted" size="small" disabled={isDisabled} onClick={() => send(buildImageGalleryDeclineSentence(gallery.name), { kind: "declined", letters: [] })} testId={`${testId}-decline`}>
                None of these
              </Button>
              <Button size="small" disabled={isDisabled || selectedFiles.length === 0} isLoading={pending} onClick={() => send(buildImageGalleryPickSentence(gallery.name, selectedFiles), {
                kind: "picked",
                letters: selectedFiles.map((file) => file.letter),
            })} replacements={{ count: selectedFiles.length, letter: selectedFiles[0]?.letter ?? "" }} testId={`${testId}-confirm`}>
                {gallery.selection === "many" && selectedFiles.length > 1
                ? textKey("Use %count% pictures")
                : selectedFiles.length === 1
                    ? textKey("Use picture %letter%")
                    : textKey("Pick a picture")}
              </Button>
            </div>))}
      </div>
      {lightboxPosition !== null && (<ImageGalleryLightbox files={gallery.files} position={lightboxPosition} onPosition={setLightboxPosition} onClose={() => setLightboxPosition(null)} testId={testId}/>)}
    </div>);
};
