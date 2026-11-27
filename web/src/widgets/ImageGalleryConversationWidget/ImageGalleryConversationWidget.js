import { useMemo } from "react";
import { textKey } from "../../provider/text-key";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { CanvasWidgetSwitch } from "../CanvasLauncherWidget/CanvasWidgetSwitch";
import { canvasForMessageWidget } from "../CanvasLauncherWidget/canvas-for-message-widget";
import { ImageGallery } from "./ImageGallery";
import { IMAGE_GALLERY_CANVAS_ID } from "./shared/image-gallery-canvas";
import { parseImageGalleryPayload } from "./shared/image-gallery-payload";
const testId = "image-gallery-widget";
/** More pictures than this open in the canvas unless the block says `display`. */
export const IMAGE_GALLERY_INLINE_LIMIT = 6;
/**
 * `ImageGallery`: pictures of files by reference as lettered tiles, a lightbox, and the owner's pick. Inline in the
 * message for a few pictures, in the canvas (`imageGallery`) for many or when the block says `display: "canvas"`.
 * The pictures come through the host's `FilePreviewSource`; the block never holds a URL.
 *
 * Inert once a newer owner message exists past its host message (the conversation moved on), like the answer pills.
 */
export const ImageGalleryConversationWidget = (props) => {
    const { payload, interactive = false, justDelivered = false, messageId } = props;
    const { messages } = useConversationWidgetContext();
    const gallery = useMemo(() => parseImageGalleryPayload(payload), [payload]);
    const canvas = canvasForMessageWidget(IMAGE_GALLERY_CANVAS_ID, messageId, payload);
    const isStale = useMemo(() => {
        if (!messages || messageId === undefined)
            return false;
        const hostIndex = messages.findIndex((message) => String(message.id) === String(messageId));
        if (hostIndex === -1)
            return false;
        return messages.slice(hostIndex + 1).some((message) => message.role === "USER");
    }, [messages, messageId]);
    useReportWidgetRenderStatus(gallery === null ? "failed" : "mounted", gallery === null ? "INVALID_PROP" : undefined, gallery === null ? "The ImageGallery carries no file reference (<source>/<id>); the widget was dropped." : undefined);
    if (gallery === null)
        return null;
    return (<CanvasWidgetSwitch display={gallery.display} exceedsInlineLimit={gallery.files.length > IMAGE_GALLERY_INLINE_LIMIT} title={gallery.title ?? textKey("Pictures")} subtitle={gallery.files.length === 1 ? textKey("1 picture") : textKey("%count% pictures")} subtitleReplacements={{ count: gallery.files.length }} icon="image" justDelivered={justDelivered} canvas={canvas} testId={testId} renderInline={() => (<ImageGallery gallery={gallery} interactive={interactive && !isStale} surface="inline" testId={testId}/>)}/>);
};
