/**
 * What a picture of the screen cannot show. The same sentences as `AgentUIMCPPackage::PICTURE_LIMITATIONS` of the
 * server (a contract test compares them): the model reads them beside every picture.
 */
export const PICTURE_LIMITATIONS = [
    'content of other origins (embedded frames, some images and fonts) can be blank or replaced',
    'video, canvas and WebGL content can be blank',
    'password fields show dots; fields the application marks secret or skipped are left out',
    'only what is in the browser window is shown; parts scrolled out of view are left out (the text read has them)',
];
/** The three size presets; `large` stays below the server's caps (4,000,000 pixels, 614,400 bytes). */
export const SCREEN_PICTURE_PRESETS = {
    small: { maxLongEdge: 1024, maxPixels: 786432, maxBytes: 150000 },
    medium: { maxLongEdge: 1600, maxPixels: 1920000, maxBytes: 350000 },
    large: { maxLongEdge: 2048, maxPixels: 3750000, maxBytes: 600000 },
};
/** The machine mode: 64 grey levels, 75 % of the size, half the byte budget (a picture for the model, not a person). */
export const MACHINE_MODE_GREY_LEVELS = 64;
export const MACHINE_MODE_SIZE_FACTOR = 0.75;
export const MACHINE_MODE_BYTE_FACTOR = 0.5;
/** The most encodings of one capture; each retry scales down from the source. */
export const MAX_PICTURE_ENCODE_ATTEMPTS = 4;
/** The most elements a capture copies (a larger page keeps its first elements). */
export const MAX_PICTURE_ELEMENTS = 8000;
/** The attribute that leaves an element out of a picture. */
export const AGENT_SKIP_ATTRIBUTE = 'data-agent-skip';
/** The attribute that blanks an element's content in a picture (its box stays). */
export const AGENT_SECRET_ATTRIBUTE = 'data-agent-secret';
/** The byte budget of a preset in a render mode. */
export const computePictureByteBudget = (pictureSize, renderMode) => {
    const preset = SCREEN_PICTURE_PRESETS[pictureSize];
    return Math.floor(renderMode === 'machine' ? preset.maxBytes * MACHINE_MODE_BYTE_FACTOR : preset.maxBytes);
};
/**
 * The size of the picture of a source: within the long edge and the pixels of the preset (never larger than the
 * source), 75 % of it in the machine mode, times `retryScale` (a retry's factor, always taken from the source size).
 */
export const computePictureDimensions = (sourceWidth, sourceHeight, request, retryScale = 1) => {
    const preset = SCREEN_PICTURE_PRESETS[request.pictureSize];
    const safeWidth = Math.max(1, sourceWidth);
    const safeHeight = Math.max(1, sourceHeight);
    let scale = Math.min(1, preset.maxLongEdge / Math.max(safeWidth, safeHeight), Math.sqrt(preset.maxPixels / (safeWidth * safeHeight)));
    if (request.renderMode === 'machine')
        scale *= MACHINE_MODE_SIZE_FACTOR;
    scale *= retryScale;
    return {
        width: Math.max(1, Math.floor(safeWidth * scale)),
        height: Math.max(1, Math.floor(safeHeight * scale)),
        scale,
    };
};
/**
 * The factor of the next attempt after an encoding of `encodedBytes` missed the budget: the bytes shrink about with the
 * pixels, so the side shrinks with the square root, with a margin. Applied to the source size, never to the last
 * picture (no blur piling up over retries).
 */
export const computeRetryScale = (previousRetryScale, encodedBytes, byteBudget) => {
    if (encodedBytes <= 0)
        return previousRetryScale;
    return previousRetryScale * Math.min(0.9, Math.sqrt(byteBudget / encodedBytes) * 0.9);
};
/** Turns RGBA pixels grey in place; with `greyLevels` the grey is quantized to that many levels. */
export const convertPixelsToGrey = (pixels, greyLevels = null) => {
    const levelStep = greyLevels !== null && greyLevels > 1 ? 255 / (greyLevels - 1) : null;
    for (let index = 0; index < pixels.length; index += 4) {
        let grey = 0.2126 * pixels[index] + 0.7152 * pixels[index + 1] + 0.0722 * pixels[index + 2];
        if (levelStep !== null)
            grey = Math.round(grey / levelStep) * levelStep;
        pixels[index] = grey;
        pixels[index + 1] = grey;
        pixels[index + 2] = grey;
    }
};
/** Whether an element scrolls its own content vertically (its scrolled-away part is shown expanded). */
const isScrollContainer = (element, style) => element.scrollHeight > element.clientHeight + 1 && (style.overflowY === 'auto' || style.overflowY === 'scroll');
/** The defaults by tag, kept for the page's life: a browser's defaults do not change. */
const defaultStylesByTag = new Map();
/**
 * The browser defaults read from an empty frame (no style sheet of the page reaches it), cached per tag. Without a
 * frame (no document, a sandbox refusing it) every value is unknown and every declaration is copied, as before.
 */
export const createFrameDefaultStyleSource = (documentOfRoot) => {
    let frame = null;
    const frameDocument = () => {
        if (frame === null) {
            frame = documentOfRoot.createElement('iframe');
            frame.setAttribute('aria-hidden', 'true');
            frame.setAttribute(AGENT_SKIP_ATTRIBUTE, '');
            frame.style.cssText = 'position:fixed;left:-100000px;top:0;width:10px;height:10px;visibility:hidden;border:0;';
            documentOfRoot.body.appendChild(frame);
        }
        return frame.contentDocument;
    };
    return {
        valueOf: (tagName, namespaceUri, property) => {
            const cacheKey = `${namespaceUri ?? ''} ${tagName}`;
            let defaults = defaultStylesByTag.get(cacheKey);
            if (defaults === undefined) {
                defaults = new Map();
                const emptyDocument = frameDocument();
                const view = emptyDocument?.defaultView;
                if (emptyDocument?.body && view) {
                    const element = namespaceUri ? emptyDocument.createElementNS(namespaceUri, tagName) : emptyDocument.createElement(tagName);
                    emptyDocument.body.appendChild(element);
                    const style = view.getComputedStyle(element);
                    for (let index = 0; index < style.length; index += 1) {
                        const name = style.item(index);
                        defaults.set(name, style.getPropertyValue(name));
                    }
                    element.remove();
                    defaultStylesByTag.set(cacheKey, defaults);
                }
            }
            return defaults.get(property) ?? '';
        },
        dispose: () => {
            frame?.remove();
            frame = null;
        },
    };
};
/**
 * The display types whose size the table layout computes from their rows and cells. A copy of a list holds fewer rows
 * and columns than the page (out of view, the element cap): with the page's height (17,769 px for 200 rows) the layout
 * stretched the copied rows over it and the picture showed the header and at most one row; with the page's width
 * (2,637 px for 13 columns, 3 of them right of the window) it spread the width over the 10 copied columns, each grew by
 * about 17 %, and a column partly in view (the status) left the picture. Their size is never copied; the cells keep
 * theirs.
 */
const TABLE_LAYOUT_DISPLAYS = new Set(['table', 'inline-table', 'table-row-group', 'table-header-group', 'table-footer-group', 'table-row']);
/**
 * Properties copied whatever their value: their computed value depends on another property (a border width computes
 * to 0 under `border-style: none`), so a page's `solid` border of 0 px matches the default 0 and was dropped, and the
 * clone drew the initial `medium` width (3 px) around every element.
 */
const ALWAYS_COPIED_PROPERTY = /^(border-.*-width|outline-width|column-rule-width)$/;
/** Properties never copied onto a table part (see {@link TABLE_LAYOUT_DISPLAYS}). */
const TABLE_LAYOUT_SIZE_PROPERTIES = new Set(['height', 'block-size', 'width', 'inline-size']);
/**
 * Copies the computed style of an element onto its clone as inline style: a declaration only when it differs from the
 * tag's default or from the parent's value (the clone's parent carries that value, so an inherited one matches it).
 * Custom properties (`--*`) are never copied: every property is copied with its resolved value, and a page's design
 * tokens (273 inherited variables of a Tailwind page, 7.6 KB on every element) would be copied onto each element.
 */
const copyComputedStyle = (style, clone, original, parentStyle, defaults) => {
    const declarations = [];
    const isTablePart = TABLE_LAYOUT_DISPLAYS.has(style.display);
    const namespaceUri = original.namespaceURI === 'http://www.w3.org/1999/xhtml' ? null : original.namespaceURI;
    for (let index = 0; index < style.length; index += 1) {
        const property = style.item(index);
        if (property.startsWith('--') || (isTablePart && TABLE_LAYOUT_SIZE_PROPERTIES.has(property)))
            continue;
        const value = style.getPropertyValue(property);
        const isDefault = defaults !== null && value === defaults.valueOf(original.localName, namespaceUri, property);
        if (isDefault && !ALWAYS_COPIED_PROPERTY.test(property) && (parentStyle === null || parentStyle.getPropertyValue(property) === value))
            continue;
        declarations.push(`${property}:${value}`);
    }
    clone.setAttribute('style', declarations.join(';'));
};
/** The part of `box` inside `clip`; null when they do not meet. */
const intersectBoxes = (box, clip) => {
    const top = Math.max(box.top, clip.top);
    const left = Math.max(box.left, clip.left);
    const bottom = Math.min(box.bottom, clip.bottom);
    const right = Math.min(box.right, clip.right);
    return bottom > top && right > left ? { top, left, bottom, right } : null;
};
/** Whether an element lies wholly outside the visible box (an element without a size is never out: its children may show). */
const isOutOfView = (element, visibleBox) => {
    const rect = element.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0)
        return false;
    return intersectBoxes({ top: rect.top, left: rect.left, bottom: rect.bottom, right: rect.right }, visibleBox) === null;
};
/** The visible box for the children of an element: clipped by it when it hides its overflow. */
const clipBoxOf = (element, style, visibleBox) => {
    if (style === null)
        return visibleBox;
    if (style.position === 'fixed')
        return windowBox();
    if (style.overflowX === 'visible' && style.overflowY === 'visible')
        return visibleBox;
    const rect = element.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0)
        return visibleBox;
    return intersectBoxes({ top: rect.top, left: rect.left, bottom: rect.bottom, right: rect.right }, visibleBox) ?? { top: 0, left: 0, bottom: 0, right: 0 };
};
/** The browser window in window coordinates. */
const windowBox = () => ({ top: 0, left: 0, bottom: window.innerHeight, right: window.innerWidth });
/** The value of a form field written into its clone, a password as dots. */
const copyFieldValue = (original, clone) => {
    if (original instanceof HTMLInputElement && clone instanceof HTMLInputElement) {
        if (original.type === 'password') {
            clone.setAttribute('value', '•'.repeat(Math.min(original.value.length, 24)));
            clone.type = 'text';
        }
        else if (original.type === 'checkbox' || original.type === 'radio') {
            if (original.checked)
                clone.setAttribute('checked', '');
            else
                clone.removeAttribute('checked');
        }
        else {
            clone.setAttribute('value', original.value);
        }
    }
    else if (original instanceof HTMLTextAreaElement) {
        clone.textContent = original.value;
    }
    else if (original instanceof HTMLSelectElement && clone instanceof HTMLSelectElement) {
        Array.from(clone.options).forEach((option, optionIndex) => {
            if (optionIndex === original.selectedIndex)
                option.setAttribute('selected', '');
            else
                option.removeAttribute('selected');
        });
    }
};
/** Elements never copied into a picture. */
const LEFT_OUT_TAGS = new Set(['SCRIPT', 'NOSCRIPT', 'TEMPLATE', 'LINK', 'META']);
/**
 * A copy of `root` ready to be drawn: the elements in the browser window only (`visibleBox`), computed styles inlined
 * where they differ from the tag's default, form values written (passwords as dots), elements with `data-agent-skip`
 * left out, the content of elements with `data-agent-secret` blanked (the box stays). With `visibleBox: null` the whole
 * page is copied and scroll containers are expanded. `false` instead of options skips the styles (tests).
 */
export const prepareScreenCloneForPicture = (root, cloneOptions = true) => {
    const options = typeof cloneOptions === 'boolean' ? { copyStyles: cloneOptions } : cloneOptions;
    const copyStyles = options.copyStyles ?? true;
    const visibleBox = options.visibleBox === undefined ? (typeof window === 'undefined' ? null : windowBox()) : options.visibleBox;
    const ownsDefaults = copyStyles && options.defaultStyles === undefined;
    const defaults = ownsDefaults ? createFrameDefaultStyleSource(root.ownerDocument) : (options.defaultStyles ?? null);
    try {
        return copyScreenClone(root, copyStyles, visibleBox, defaults);
    }
    finally {
        if (ownsDefaults)
            defaults?.dispose();
    }
};
const copyScreenClone = (root, copyStyles, rootVisibleBox, defaults) => {
    const clone = root.cloneNode(false);
    let copiedElements = 0;
    const copyInto = (original, target, parentStyle, visibleBox) => {
        const view = original.ownerDocument.defaultView;
        // the style is read for the copy and for the clip of the window part
        const style = view && (copyStyles || visibleBox !== null) ? view.getComputedStyle(original) : null;
        if (copyStyles && style && (target instanceof HTMLElement || target instanceof SVGElement)) {
            copyComputedStyle(style, target, original, parentStyle, defaults);
            if (visibleBox === null && target instanceof HTMLElement && isScrollContainer(original, style)) {
                target.style.overflowY = 'visible';
                target.style.height = 'auto';
                target.style.maxHeight = 'none';
            }
        }
        else if (visibleBox === null && !copyStyles && target instanceof HTMLElement && original.scrollHeight > original.clientHeight + 1) {
            target.style.overflowY = 'visible';
            target.style.height = 'auto';
            target.style.maxHeight = 'none';
        }
        copyFieldValue(original, target);
        if (original.hasAttribute(AGENT_SECRET_ATTRIBUTE)) {
            target.textContent = '';
            if (target instanceof HTMLElement)
                target.style.background = '#9e9e9e';
            return;
        }
        if (original instanceof HTMLTextAreaElement)
            return;
        const childVisibleBox = visibleBox === null ? null : clipBoxOf(original, style, visibleBox);
        for (const childNode of Array.from(original.childNodes)) {
            if (childNode.nodeType === Node.TEXT_NODE) {
                target.appendChild(childNode.cloneNode(false));
                continue;
            }
            if (!(childNode instanceof Element))
                continue;
            if (LEFT_OUT_TAGS.has(childNode.tagName) || childNode.hasAttribute(AGENT_SKIP_ATTRIBUTE))
                continue;
            if (childVisibleBox !== null && isOutOfView(childNode, childVisibleBox))
                continue;
            if (copiedElements >= MAX_PICTURE_ELEMENTS)
                return;
            copiedElements += 1;
            const childClone = childNode.cloneNode(false);
            target.appendChild(childClone);
            copyInto(childNode, childClone, copyStyles ? style : null, childVisibleBox);
        }
    };
    copyInto(root, clone, null, rootVisibleBox);
    return clone;
};
/** Measures the height of a prepared clone laid out at `width`, off screen. */
const measureCloneHeight = (clone, width, documentOfRoot) => {
    const holder = documentOfRoot.createElement('div');
    holder.setAttribute('aria-hidden', 'true');
    holder.style.cssText = `position:fixed;left:-100000px;top:0;width:${width}px;visibility:hidden;pointer-events:none;`;
    const measured = clone.cloneNode(true);
    holder.appendChild(measured);
    documentOfRoot.body.appendChild(holder);
    try {
        return Math.ceil(Math.max(holder.scrollHeight, measured.scrollHeight ?? 0));
    }
    finally {
        holder.remove();
    }
};
const loadImage = (url) => new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error('the screen could not be drawn'));
    image.src = url;
});
const encodeCanvas = (canvas, mimeType, quality) => new Promise(resolve => {
    try {
        canvas.toBlob(blob => resolve(blob), mimeType, quality);
    }
    catch {
        resolve(null);
    }
});
const encodeBase64 = async (blob) => {
    const bytes = new Uint8Array(await blob.arrayBuffer());
    let binary = '';
    const chunkSize = 0x8000;
    for (let offset = 0; offset < bytes.length; offset += chunkSize) {
        binary += String.fromCharCode(...bytes.subarray(offset, offset + chunkSize));
    }
    return btoa(binary);
};
/**
 * The DOM default of `ScreenPictureCapturer`: draws a styled copy of what the browser window shows (SVG foreignObject
 * onto a canvas; elements out of view are not copied, styles only where they differ from the browser's default) at the
 * size of the preset, grey in `greyscale` and `machine` (64 levels), and encodes it as WebP (JPEG where the browser
 * has no WebP encoder) within the byte budget; a miss retries from the source size, at most
 * `MAX_PICTURE_ENCODE_ATTEMPTS` times. No dependency; what it cannot show is `PICTURE_LIMITATIONS`. Returns null when
 * the browser refuses (a tainted canvas, no canvas), and the runner answers `PICTURE_UNAVAILABLE`.
 */
export const createDomScreenPictureCapturer = (options = {}) => ({
    capture: async (request) => {
        if (typeof document === 'undefined')
            return null;
        const root = options.root?.() ?? document.documentElement;
        if (!root)
            return null;
        try {
            const sourceWidth = Math.max(1, Math.ceil(window.innerWidth || root.clientWidth));
            const clone = prepareScreenCloneForPicture(root);
            // the window's part of the page: the copy holds only what is in it
            const sourceHeight = Math.max(1, Math.min(measureCloneHeight(clone, sourceWidth, document), Math.ceil(window.innerHeight || sourceWidth * 8)));
            const serialized = new XMLSerializer().serializeToString(clone);
            const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${sourceWidth}" height="${sourceHeight}">` +
                `<foreignObject x="0" y="0" width="100%" height="100%">${serialized}</foreignObject></svg>`;
            const image = await loadImage(`data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`);
            const byteBudget = computePictureByteBudget(request.pictureSize, request.renderMode);
            let retryScale = 1;
            let quality = options.quality ?? 0.8;
            for (let attempt = 1; attempt <= MAX_PICTURE_ENCODE_ATTEMPTS; attempt += 1) {
                const dimensions = computePictureDimensions(sourceWidth, sourceHeight, request, retryScale);
                const canvas = document.createElement('canvas');
                canvas.width = dimensions.width;
                canvas.height = dimensions.height;
                const context = canvas.getContext('2d');
                if (!context)
                    return null;
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, dimensions.width, dimensions.height);
                context.drawImage(image, 0, 0, dimensions.width, dimensions.height);
                if (request.renderMode !== 'colour') {
                    const imageData = context.getImageData(0, 0, dimensions.width, dimensions.height);
                    convertPixelsToGrey(imageData.data, request.renderMode === 'machine' ? MACHINE_MODE_GREY_LEVELS : null);
                    context.putImageData(imageData, 0, 0);
                }
                let blob = await encodeCanvas(canvas, 'image/webp', quality);
                if (blob && blob.type !== 'image/webp')
                    blob = await encodeCanvas(canvas, 'image/jpeg', quality);
                if (!blob)
                    return null;
                if (blob.size <= byteBudget || attempt === MAX_PICTURE_ENCODE_ATTEMPTS) {
                    if (blob.size > byteBudget)
                        return null;
                    return {
                        base64: await encodeBase64(blob),
                        mimeType: blob.type === 'image/webp' ? 'image/webp' : 'image/jpeg',
                        width: dimensions.width,
                        height: dimensions.height,
                        byteSize: blob.size,
                    };
                }
                retryScale = computeRetryScale(retryScale, blob.size, byteBudget);
                quality = Math.min(quality, 0.65);
            }
            return null;
        }
        catch {
            return null;
        }
    },
});
