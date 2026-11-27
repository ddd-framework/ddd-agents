export const IMAGE_GALLERY_WIDGET_TYPE = "ImageGallery";
/** The most pictures of one gallery: the letters A to L. */
export const IMAGE_GALLERY_MAX_FILES = 12;
export const IMAGE_GALLERY_LETTERS = "ABCDEFGHIJKL";
/** `<source>/<id>` as Core's `FileReference` accepts it. */
const FILE_REFERENCE_PATTERN = /^[a-z][a-z0-9-]{0,31}\/[A-Za-z0-9_-]{1,128}$/;
/** A gallery name as the reader matches it after the wire name. */
const GALLERY_NAME_PATTERN = /^[A-Za-z0-9_.:-]{1,80}$/;
const readText = (value) => typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;
/**
 * Reads the payload; a file without a valid reference is left out, a thirteenth one too. Null when no picture is
 * left (the widget then renders nothing and reports the drop).
 */
export const parseImageGalleryPayload = (raw) => {
    let parsed;
    try {
        parsed = JSON.parse(raw);
    }
    catch {
        return null;
    }
    if (!parsed || typeof parsed !== "object")
        return null;
    const fields = parsed;
    const rawFiles = Array.isArray(fields.files) ? fields.files : [];
    const files = [];
    const seenReferences = new Set();
    for (const rawFile of rawFiles) {
        if (files.length >= IMAGE_GALLERY_MAX_FILES)
            break;
        const fileFields = rawFile && typeof rawFile === "object" ? rawFile : null;
        const reference = typeof rawFile === "string" ? rawFile.trim() : readText(fileFields?.reference);
        if (reference === undefined || !FILE_REFERENCE_PATTERN.test(reference) || seenReferences.has(reference))
            continue;
        seenReferences.add(reference);
        files.push({
            reference,
            letter: IMAGE_GALLERY_LETTERS[files.length],
            label: readText(fileFields?.label),
            caption: readText(fileFields?.caption),
        });
    }
    if (files.length === 0)
        return null;
    const selection = fields.selection === "one" || fields.selection === "many" ? fields.selection : "none";
    const display = fields.display === "inline" || fields.display === "canvas" ? fields.display : undefined;
    const name = readText(fields.name);
    return {
        title: readText(fields.title),
        name: name !== undefined && GALLERY_NAME_PATTERN.test(name) ? name : undefined,
        selection,
        display,
        files,
    };
};
/** The sentence start that names the gallery: the wire name and its name when it has one. */
export const buildImageGallerySentencePrefix = (name) => name ? `${IMAGE_GALLERY_WIDGET_TYPE} ${name}` : IMAGE_GALLERY_WIDGET_TYPE;
/**
 * The pick the widget posts (FRONTEND_WIDGET_CLICK): `ImageGallery: the operator picked A (media/901)`, several
 * `ImageGallery: the operator picked 2 pictures: A (media/901), C (media/903)`, in letter order. A stored contract
 * (`image-gallery-widget-contract.json`), read by `ImageGalleryConversationWidget::readAnswer()`.
 */
export const buildImageGalleryPickSentence = (name, pickedFiles) => {
    const parts = [...pickedFiles]
        .sort((first, second) => first.letter.localeCompare(second.letter))
        .map((file) => `${file.letter} (${file.reference})`);
    const picked = parts.length === 1 ? parts[0] : `${parts.length} pictures: ${parts.join(", ")}`;
    return `${buildImageGallerySentencePrefix(name)}: the operator picked ${picked}`;
};
/** The decline the widget posts: `ImageGallery: the operator declined all pictures`. */
export const buildImageGalleryDeclineSentence = (name) => `${buildImageGallerySentencePrefix(name)}: the operator declined all pictures`;
