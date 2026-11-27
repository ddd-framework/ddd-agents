import { useMemo, useState } from "react";
import { useAgentsOwnerSettings } from "../../provider/AgentsProvider";
import { Button, StatusChip, Text, Tooltip } from "../../provider/components";
import { escapePlainTextForText, getWidgetSelfHydrationKeyFieldName, translateText } from "../../provider/environment";
import { useAgentsLinkNavigation } from "../../provider/link-navigation";
import { readWidgetAgentSpaceKeyAttribute } from "../../renderer/_agent-space/widget-agent-space-key-attribute";
import { sameCanvasIdentity } from "../../renderer/canvas/canvas-state";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { useWidgetHydration } from "../../renderer/widget-hydration/use-widget-hydration";
import { WidgetListRow, WidgetSurface, WidgetSurfaceSkeleton } from "../_shared/modern";
import { driveFileCanvasTargetOf } from "./shared/drive-file-canvas-openers";
import { DRIVE_FILE_CARDS_SOURCE_MISSING, readDriveFileCardsSource, } from "./shared/drive-file-cards-source";
import { DRIVE_FILES_WIDGET_TYPE, driveFileCardToOpen, driveFileFamilyOf, driveFileTypeLabelOf, formatDriveFileSize, isDriveFileCardExpired, parseDriveDateTimeMs, parseDriveFilesPayload, safeDriveFileOpenLink, } from "./shared/drive-files";
import styles from "./DriveFilesConversationWidget.module.scss";
const TEST_ID = "drive-files-widget";
/** The block's headline: the agent's title, else a count of the files it names. */
const DriveFilesHeadline = ({ request }) => request.title !== null ? (<Text role="intro" weight="bold" translate={false} testId={`${TEST_ID}-title`}>
      {escapePlainTextForText(request.title)}
    </Text>) : (<Text role="intro" weight="bold" replacements={{ count: request.references.length }} testId={`${TEST_ID}-title`}>
      {request.references.length === 1 ? "1 file from the drive" : "%count% files from the drive"}
    </Text>);
/** The two-letter colour class of a family's tile. */
const FAMILY_TILE_CLASS = {
    PDF: styles.tilePdf,
    SPREADSHEET: styles.tileSpreadsheet,
    DOCUMENT: styles.tileDocument,
    PRESENTATION: styles.tilePresentation,
    TEXT: styles.tileText,
    IMAGE: styles.tileImage,
    VECTOR: styles.tileImage,
    VIDEO: styles.tileVideo,
    ARCHIVE: styles.tileArchive,
};
const FolderGlyph = () => (<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
    <path fill="currentColor" d="M3 6.5A2.5 2.5 0 0 1 5.5 4h3.6c.6 0 1.2.24 1.65.66L12.2 6H18.5A2.5 2.5 0 0 1 21 8.5v9A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5v-11Z"/>
  </svg>);
/** The thumbnail of a file (short-lived URL), or a tile with its type; a failed thumbnail falls back to the tile. */
const DriveFileTile = ({ card }) => {
    const [failedSource, setFailedSource] = useState(null);
    const thumbnailUrl = card.thumbnailUrl ?? null;
    const source = thumbnailUrl && failedSource !== thumbnailUrl ? thumbnailUrl : null;
    if (card.isFolder) {
        return (<span className={`${styles.tile} ${styles.tileFolder}`} data-testid={`${TEST_ID}-folder-tile`}>
        <FolderGlyph />
      </span>);
    }
    if (source) {
        return (<span className={`${styles.tile} ${styles.tileThumbnail}`}>
        <img src={source} alt="" loading="lazy" draggable={false} onError={() => setFailedSource(source)}/>
      </span>);
    }
    const typeLabel = driveFileTypeLabelOf(card) ?? "";
    return (<span className={`${styles.tile} ${FAMILY_TILE_CLASS[driveFileFamilyOf(card)]}`} aria-hidden="true">
      {typeLabel.slice(0, 4)}
    </span>);
};
/** Saves a URL as a download (the store answers with `Content-Disposition: attachment`). */
const startDownload = (url, fileName) => {
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = fileName;
    anchor.rel = "noopener";
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
};
const readOrigin = () => (typeof window === "undefined" ? null : window.location.origin);
/** The facts line of a card: type · size (or a folder's entries) · date. */
const useCardFacts = (card) => {
    const { dateLocale, locale } = useAgentsOwnerSettings();
    const facts = [];
    const typeLabel = card.isFolder ? translateText("Folder") : driveFileTypeLabelOf(card);
    if (typeLabel)
        facts.push(typeLabel);
    if (card.isFolder) {
        if (typeof card.childCount === "number") {
            facts.push(card.childCount === 1
                ? translateText("1 entry")
                : translateText("%count% entries", { count: card.childCount }));
        }
    }
    else if (typeof card.sizeBytes === "number") {
        facts.push(formatDriveFileSize(card.sizeBytes, locale ?? dateLocale));
    }
    const modifiedAtMs = parseDriveDateTimeMs(card.modifiedAt);
    if (modifiedAtMs !== null) {
        facts.push(new Intl.DateTimeFormat(dateLocale ?? locale ?? undefined, { dateStyle: "medium" }).format(modifiedAtMs));
    }
    return facts.join(" · ");
};
/** One file or folder: tile, name, type · size · date (or the folder's entries), the store's state, the actions. */
const DriveFileCardRow = ({ card, openCard, source, store, messageId, onCardsExpired }) => {
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState(null);
    const { openLink } = useAgentsLinkNavigation();
    const { openCanvas, canvas: openCanvasState, hostHasCanvasSurface } = useConversationWidgetContext();
    // The canvas of the store for this entry (a file viewer, a folder browser); without one the row opens a tab as before.
    const canvasTarget = hostHasCanvasSurface ? driveFileCanvasTargetOf(store, card, messageId) : null;
    const isCanvasOpen = canvasTarget !== null && openCanvasState !== null && sameCanvasIdentity(openCanvasState, canvasTarget);
    const facts = useCardFacts(card);
    const name = card.name ?? "";
    const openName = openCard?.name ?? name;
    const driveHref = safeDriveFileOpenLink(card.openLink, readOrigin()) ?? source?.buildDriveScreenHref?.(card) ?? null;
    /** The content URL; expired card URLs make the block read its cards again afterwards. */
    const readContent = async (disposition, target) => {
        if (source === null)
            throw new Error(DRIVE_FILE_CARDS_SOURCE_MISSING);
        const expired = isDriveFileCardExpired(target, Date.now());
        const content = await source.readFileContent(target, disposition);
        if (expired)
            onCardsExpired();
        return content;
    };
    const openFile = async () => {
        if (openCard === null)
            return;
        // The tab opens within the click, so no popup blocker stops it; it gets the URL once the store signed it.
        const tab = window.open("", "_blank");
        setBusy(true);
        setFailure(null);
        try {
            const content = await readContent("inline", openCard);
            if (tab) {
                tab.opener = null;
                tab.location.href = content.url;
            }
        }
        catch {
            tab?.close();
            setFailure(translateText("%name% could not be opened.", { name: openName }));
        }
        finally {
            setBusy(false);
        }
    };
    const openInCanvas = () => {
        if (canvasTarget === null)
            return;
        setFailure(null);
        openCanvas({ ...canvasTarget, origin: "widgetClick", justDelivered: false });
    };
    const downloadFile = async () => {
        setBusy(true);
        setFailure(null);
        try {
            const content = await readContent("attachment", card);
            startDownload(content.url, content.fileName || name);
        }
        catch {
            setFailure(translateText("%name% could not be downloaded.", { name }));
        }
        finally {
            setBusy(false);
        }
    };
    const meta = (<span className={styles.meta}>
      {facts !== "" ? <span className={styles.facts}>{facts}</span> : null}
      {card.state ? (<StatusChip tone="warning" compact translate={false}>
          {escapePlainTextForText(card.state)}
        </StatusChip>) : null}
      {failure !== null ? (<span role="alert" className={styles.failure}>
          {failure}
        </span>) : null}
    </span>);
    const actions = (<span className={styles.actions}>
      {canvasTarget !== null ? (<Tooltip text="Open %name%" replacements={{ name }}>
          <Button variant={isCanvasOpen ? "default" : "muted"} size="small" icon="visibility" ariaLabel={translateText("Open %name%", { name })} onClick={openInCanvas} testId={`${TEST_ID}-open-canvas`}/>
        </Tooltip>) : !card.isFolder && openCard !== null ? (<Tooltip text="Open %name%" replacements={{ name: openName }}>
          <Button variant="muted" size="small" icon="newTab" ariaLabel={translateText("Open %name%", { name: openName })} disabled={busy} onClick={() => void openFile()} testId={`${TEST_ID}-open`}/>
        </Tooltip>) : null}
      {!card.isFolder ? (<Tooltip text="Download %name%" replacements={{ name }}>
          <Button variant="muted" size="small" icon="download" ariaLabel={translateText("Download %name%", { name })} disabled={busy} onClick={() => void downloadFile()} testId={`${TEST_ID}-download`}/>
        </Tooltip>) : null}
      {driveHref !== null ? (<Tooltip text="Show %name% in the drive" replacements={{ name }}>
          <Button variant="muted" size="small" icon="details" ariaLabel={translateText("Show %name% in the drive", { name })} onClick={(event) => openLink(driveHref, event)} testId={`${TEST_ID}-show-in-drive`}/>
        </Tooltip>) : null}
    </span>);
    return (<div role="listitem" data-testid={`${TEST_ID}-card`} title={card.path ?? undefined}>
      <WidgetListRow leading={<DriveFileTile card={card}/>} title={escapePlainTextForText(name)} meta={meta} trailing={actions}/>
    </div>);
};
/** The cards once the store answered: loading, failed (with a retry), not connected, none readable, or the list. */
const HydratedDriveFiles = ({ request, justDelivered, messageId }) => {
    const hydration = useWidgetHydration(DRIVE_FILES_WIDGET_TYPE, request, {
        forceRefresh: justDelivered,
    });
    const cards = hydration.data ?? [];
    const hasData = hydration.status === "success";
    const notConnected = hydration.isError && hydration.error === DRIVE_FILE_CARDS_SOURCE_MISSING;
    const source = readDriveFileCardsSource();
    useReportWidgetRenderStatus(hydration.isError ? "failed" : !hasData ? "pending" : cards.length === 0 ? "empty" : "mounted", hydration.isError ? "HYDRATION_FAILED" : hasData && cards.length === 0 ? "NO_DATA" : undefined, notConnected
        ? "No drive is connected to this frontend; the DriveFiles card cannot read its files."
        : hydration.isError
            ? "The DriveFiles card could not read its files."
            : hasData && cards.length === 0
                ? "The DriveFiles card shows no file: none of its references is readable for the viewer."
                : undefined);
    if (notConnected) {
        return (<WidgetSurface testId={TEST_ID}>
        <div className={styles.stack}>
          <DriveFilesHeadline request={request}/>
          <Text role="body" weight="bold">
            The drive is not connected yet
          </Text>
          <Text role="help">These files show here once the drive is part of this app.</Text>
        </div>
      </WidgetSurface>);
    }
    if (hydration.isError) {
        return (<WidgetSurface testId={TEST_ID}>
        <div className={styles.stack}>
          <DriveFilesHeadline request={request}/>
          <Text role="body">The files could not be loaded.</Text>
          <span>
            <Button variant="default" size="small" onClick={() => hydration.refetch()} testId={`${TEST_ID}-retry`}>
              Try again
            </Button>
          </span>
        </div>
      </WidgetSurface>);
    }
    if (!hasData) {
        return (<WidgetSurfaceSkeleton title={<DriveFilesHeadline request={request}/>} rowCount={Math.min(request.references.length, 3)} testId={TEST_ID}/>);
    }
    return (<WidgetSurface testId={TEST_ID}>
      <div className={styles.stack}>
        <DriveFilesHeadline request={request}/>
        {cards.length === 0 ? (<>
            <Text role="body" weight="bold">
              No file to show
            </Text>
            <Text role="help">These files were removed, or you may not open them.</Text>
          </>) : (<div role="list" className={styles.list}>
            {cards.map((card) => (<DriveFileCardRow key={card.reference || card.path || card.name} card={card} openCard={driveFileCardToOpen(card, cards)} source={source} store={request.store} messageId={messageId} onCardsExpired={() => hydration.refetch()}/>))}
          </div>)}
      </div>
    </WidgetSurface>);
};
/** A block that names no store or no reference: nothing to draw; the model hears why. */
const InvalidDriveFilesBlock = () => {
    useReportWidgetRenderStatus("failed", "INVALID_PROP", "The DriveFiles block names no store or no file reference.");
    return null;
};
/**
 * The file card widget `DriveFiles`: the files a block names, read under the viewer's rights through the host's
 * `DriveFileCardsSource`, each with type, size, date, a thumbnail, open (in the canvas of the store when it registered
 * an opener — `registerDriveFileCanvasOpener` — else in a new tab, where an office file opens its PDF twin or only
 * downloads), download and a link to its place in the host's drive screen. A block without a store or references
 * renders nothing and reports it.
 */
export const DriveFilesConversationWidget = (props) => {
    const { payload, justDelivered = false, messageId } = props;
    const selfHydrationKeyAttribute = readWidgetAgentSpaceKeyAttribute(props);
    const request = useMemo(() => parseDriveFilesPayload(payload, getWidgetSelfHydrationKeyFieldName(), selfHydrationKeyAttribute), [payload, selfHydrationKeyAttribute]);
    if (request === null)
        return <InvalidDriveFilesBlock />;
    return <HydratedDriveFiles request={request} justDelivered={justDelivered} messageId={messageId}/>;
};
