import { createContext } from "react";
export const createWidgetRenderReportCollector = (options) => {
    const { onSettled, settleMs = 1500, maxWaitMs = 20000 } = options;
    const reportsByMessageId = new Map();
    const scheduleSettleCheck = (messageId) => {
        const messageReports = reportsByMessageId.get(messageId);
        if (!messageReports || messageReports.isSent)
            return;
        if (messageReports.settleTimer !== null)
            clearTimeout(messageReports.settleTimer);
        const hasPendingBlock = [...messageReports.blocksByKey.values()].some((block) => block.entry.status === "pending");
        const waitedMs = Date.now() - messageReports.firstReportAt;
        const delayMs = hasPendingBlock ? Math.max(0, Math.min(settleMs * 4, maxWaitMs - waitedMs)) : settleMs;
        messageReports.settleTimer = setTimeout(() => {
            messageReports.settleTimer = null;
            const isStillPending = [...messageReports.blocksByKey.values()].some((block) => block.entry.status === "pending");
            if (isStillPending && Date.now() - messageReports.firstReportAt < maxWaitMs) {
                scheduleSettleCheck(messageId);
                return;
            }
            messageReports.isSent = true;
            onSettled(messageId, [...messageReports.blocksByKey.values()].sort((first, second) => first.sourceOffset - second.sourceOffset));
        }, delayMs);
    };
    const recordBlock = (messageId, widgetName, sourceOffset, entry) => {
        let messageReports = reportsByMessageId.get(messageId);
        if (!messageReports) {
            messageReports = { blocksByKey: new Map(), firstReportAt: Date.now(), settleTimer: null, isSent: false };
            reportsByMessageId.set(messageId, messageReports);
        }
        if (messageReports.isSent)
            return;
        const blockKey = `${widgetName}@${sourceOffset}`;
        const knownBlock = messageReports.blocksByKey.get(blockKey);
        if (entry === null && knownBlock)
            return;
        messageReports.blocksByKey.set(blockKey, { widgetName, sourceOffset, entry: entry ?? { status: "mounted" } });
        scheduleSettleCheck(messageId);
    };
    return {
        registerBlock: (messageId, widgetName, sourceOffset) => recordBlock(String(messageId), widgetName, sourceOffset, null),
        reporterForBlock: (messageId, widgetName, sourceOffset) => ({
            report: (entry) => recordBlock(String(messageId), widgetName, sourceOffset, entry),
        }),
        dispose: () => {
            for (const messageReports of reportsByMessageId.values()) {
                if (messageReports.settleTimer !== null)
                    clearTimeout(messageReports.settleTimer);
            }
            reportsByMessageId.clear();
        },
    };
};
/** The collector of the chat the widget lives in; absent = nobody listens (another host, a story, the e-mail capture). */
export const WidgetRenderReportCollectorContext = createContext(null);
/** The stored prefix of a render-report signal — the inspector and the tests read it; never reworded. */
export const WIDGET_RENDER_REPORT_SIGNAL_PREFIX = "Widget render report for message";
/** The signal source (backend `AIConversationSignalSource::FRONTEND_WIDGET_RENDER_REPORT`). */
export const WIDGET_RENDER_REPORT_SIGNAL_SOURCE = "FRONTEND_WIDGET_RENDER_REPORT";
const MAX_DETAIL_CHARACTERS = 400;
/** The backend's cap on a signal text (`AIConversationMessage::MAX_SIGNAL_TEXT_CHARS`). */
const MAX_SIGNAL_TEXT_CHARS = 2000;
/** Room kept for the "… and N more" line. */
const OMITTED_LINE_RESERVE = 80;
/**
 * The model-visible signal for a settled message — null when every block mounted cleanly (nothing to tell). One line
 * per block that is not `mounted`, or that mounted with a note (a code: `INLINE_VALUE_WITHOUT_SOURCE`): its position
 * among the message's widgets, its name and its occurrence among widgets of that name, its status and code, the
 * detail. The closing sentence asks for the honest answer about a block the owner did not see, and for
 * a note that the next widget fixes it.
 */
export const buildWidgetRenderReportSignal = (messageId, blocks) => {
    const occurrenceByName = new Map();
    const blockLines = [];
    blocks.forEach((block, blockPosition) => {
        const occurrence = (occurrenceByName.get(block.widgetName) ?? 0) + 1;
        occurrenceByName.set(block.widgetName, occurrence);
        if (block.entry.status === "mounted" && !block.entry.code)
            return;
        const status = block.entry.status === "pending" ? "pending (did not finish loading)" : block.entry.status;
        const code = block.entry.code ? ` ${block.entry.code}` : "";
        const detail = block.entry.detail ? ` — ${block.entry.detail.slice(0, MAX_DETAIL_CHARACTERS)}` : "";
        blockLines.push(`- widget ${blockPosition + 1} (${block.widgetName} #${occurrence}): ${status}${code}${detail}`);
    });
    if (blockLines.length === 0)
        return null;
    const header = `${WIDGET_RENDER_REPORT_SIGNAL_PREFIX} ${messageId}:`;
    const hasBlockNotShown = blocks.some((block) => block.entry.status !== "mounted");
    const hasNote = blocks.some((block) => block.entry.status === "mounted" && block.entry.code);
    const closing = [
        hasBlockNotShown
            ? "The owner did not see these widgets as intended. In your next answer say so in one line where it matters and " +
                'give the content in prose; never refer to "the widget above" for them.'
            : "",
        hasNote
            ? "The widgets marked mounted were shown; fix the noted part in every widget you write from now on (a figure you " +
                "researched names its publisher and page in `source`). Do not resend the widget for the note alone."
            : "",
    ]
        .filter((sentence) => sentence !== "")
        .join(" ");
    // The backend keeps the first MAX_SIGNAL_TEXT_CHARS of a signal: whole lines only, the rest counted in one line.
    const keptLines = [];
    let signalLength = header.length + 1 + closing.length + OMITTED_LINE_RESERVE;
    for (const blockLine of blockLines) {
        if (signalLength + blockLine.length + 1 > MAX_SIGNAL_TEXT_CHARS)
            break;
        keptLines.push(blockLine);
        signalLength += blockLine.length + 1;
    }
    const omittedCount = blockLines.length - keptLines.length;
    if (omittedCount > 0)
        keptLines.push(`- … and ${omittedCount} more widget(s) that did not render as intended`);
    return `${header}\n${keptLines.join("\n")}\n${closing}`;
};
const REPORT_LINE = /^- widget (\d+) \(([A-Za-z]+) #(\d+)\): (mounted|empty|failed|pending)(?: \(did not finish loading\))?(?: ([A-Z_]+))?(?: — (.*))?$/;
/** The report a stored signal text carries, or null for another text — the admin inspector's reader. */
export const parseWidgetRenderReportSignal = (signalText) => {
    const lines = signalText.split("\n");
    const header = new RegExp(`^${WIDGET_RENDER_REPORT_SIGNAL_PREFIX} (\\S+):$`).exec(lines[0] ?? "");
    if (!header)
        return null;
    const blocks = [];
    for (const line of lines.slice(1)) {
        const match = REPORT_LINE.exec(line);
        if (!match)
            continue;
        blocks.push({
            position: Number(match[1]),
            widgetName: match[2],
            occurrence: Number(match[3]),
            status: match[4],
            code: match[5] ?? null,
            detail: match[6] ?? null,
        });
    }
    return { messageId: header[1], blocks };
};
