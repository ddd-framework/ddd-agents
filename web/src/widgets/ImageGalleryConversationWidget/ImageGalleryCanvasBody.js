import { useMemo } from "react";
import { useAnchorWidgetPayload } from "../../renderer/canvas/use-anchor-widget-payload";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { ImageGallery } from "./ImageGallery";
import { IMAGE_GALLERY_WIDGET_TYPE, parseImageGalleryPayload } from "./shared/image-gallery-payload";
/**
 * The `imageGallery` canvas body: re-reads the `ImageGallery` fence of the anchor message and shows the same gallery
 * with more room. The pick works here as inline; it is inert once a newer owner message exists past the anchor.
 */
export const ImageGalleryCanvasBody = ({ canvas, anchorMessageMarkdown }) => {
    const payload = useAnchorWidgetPayload(canvas, anchorMessageMarkdown, IMAGE_GALLERY_WIDGET_TYPE);
    const gallery = useMemo(() => (payload === undefined ? null : parseImageGalleryPayload(payload)), [payload]);
    const { messages } = useConversationWidgetContext();
    const anchorMessageId = canvas.anchor.kind === "message" ? canvas.anchor.messageId : undefined;
    const isStale = useMemo(() => {
        if (!messages || anchorMessageId === undefined)
            return false;
        const hostIndex = messages.findIndex((message) => String(message.id) === String(anchorMessageId));
        return hostIndex !== -1 && messages.slice(hostIndex + 1).some((message) => message.role === "USER");
    }, [messages, anchorMessageId]);
    if (gallery === null)
        return null;
    return <ImageGallery gallery={gallery} interactive={!isStale} surface="canvas" testId="image-gallery-widget-canvas"/>;
};
