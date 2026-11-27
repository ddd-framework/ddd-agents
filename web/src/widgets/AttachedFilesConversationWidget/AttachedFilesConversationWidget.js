import { useMemo } from "react";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { parseAttachedFilesPayload } from "./attached-files";
import { AttachedFilesStrip } from "./AttachedFilesStrip";
/**
 * `AttachedFiles`: the files the owner attached to a message, as chips above the bubble (a picture with its
 * thumbnail through the host's `FilePreviewSource`). Written by the host's composer, never by the agent.
 */
export const AttachedFilesConversationWidget = ({ payload }) => {
    const files = useMemo(() => parseAttachedFilesPayload(payload), [payload]);
    useReportWidgetRenderStatus(files.length === 0 ? "empty" : "mounted", files.length === 0 ? "NO_DATA" : undefined, files.length === 0 ? "The AttachedFiles widget names no file reference." : undefined);
    return <AttachedFilesStrip files={files}/>;
};
