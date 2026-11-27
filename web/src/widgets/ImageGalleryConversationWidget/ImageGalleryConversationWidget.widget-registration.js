import { ImageGalleryConversationWidget } from "./ImageGalleryConversationWidget";
import { IMAGE_GALLERY_CANVAS_ID } from "./shared/image-gallery-canvas";
/**
 * `ImageGallery`: pictures of files by reference, lettered, with a lightbox and the owner's pick; many pictures open
 * the package canvas `imageGallery`. Ungated: each picture is read under the viewer's rights through the host's
 * `FilePreviewSource`.
 */
export const conversationWidgetRegistration = {
    name: "ImageGallery",
    component: ImageGalleryConversationWidget,
    opensCanvases: [IMAGE_GALLERY_CANVAS_ID],
};
