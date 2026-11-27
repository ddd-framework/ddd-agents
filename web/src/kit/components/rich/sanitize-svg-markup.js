const FORBIDDEN_ELEMENT_NAMES = [
    "a",
    "animate",
    "animateColor",
    "animateMotion",
    "animateTransform",
    "discard",
    "embed",
    "feImage",
    "foreignObject",
    "iframe",
    "image",
    "object",
    "script",
    "set",
];
const REFERENCE_ATTRIBUTE_NAMES = new Set(["href", "xlink:href"]);
const DOCUMENT_WRAPPER_ELEMENT_NAMES = new Set(["html", "head", "body"]);
/** Every `url(…)` in the value points into the graphic (`url(#id)`). */
const usesOnlyInternalUrls = (attributeValue) => {
    const urlPattern = /url\s*\(\s*(['"]?)(.*?)\1\s*\)/gi;
    let urlMatch = urlPattern.exec(attributeValue);
    while (urlMatch !== null) {
        if (!urlMatch[2].trim().startsWith("#"))
            return false;
        urlMatch = urlPattern.exec(attributeValue);
    }
    return true;
};
const readNumericLength = (lengthValue) => {
    if (lengthValue === null)
        return null;
    const length = Number.parseFloat(lengthValue);
    return Number.isFinite(length) && length > 0 && /^\s*[\d.]+(px)?\s*$/.test(lengthValue) ? length : null;
};
export const sanitizeSvgMarkup = (domPurifyFactory, svgMarkup, options) => {
    const domPurify = domPurifyFactory(window);
    const hookRemovedPartNames = [];
    domPurify.addHook("uponSanitizeAttribute", (attributeOwner, hookEvent) => {
        const attributeName = hookEvent.attrName.toLowerCase();
        const attributeValue = hookEvent.attrValue ?? "";
        const isExternalReference = REFERENCE_ATTRIBUTE_NAMES.has(attributeName) && !attributeValue.trim().startsWith("#");
        const loadsFromOutside = /url\s*\(/i.test(attributeValue) && !usesOnlyInternalUrls(attributeValue);
        const isStyleExpression = attributeName === "style" && /@import|expression\s*\(|javascript:/i.test(attributeValue);
        if (isExternalReference || loadsFromOutside || isStyleExpression) {
            hookEvent.keepAttr = false;
            hookRemovedPartNames.push(attributeName);
        }
    });
    const sanitizedFragment = domPurify.sanitize(svgMarkup, {
        USE_PROFILES: { svg: true, svgFilters: true },
        // `role` is an accessibility attribute, not a script or a reference: Mermaid writes `role="graphics-document
        // document"` on every drawing, and removing it reported every valid diagram as unsafe.
        ADD_ATTR: ["role"],
        FORBID_TAGS: options.keepStyleElement ? FORBIDDEN_ELEMENT_NAMES : [...FORBIDDEN_ELEMENT_NAMES, "style"],
        RETURN_DOM_FRAGMENT: true,
    });
    const removedPartNames = new Set(hookRemovedPartNames);
    for (const removedPart of domPurify.removed) {
        if ("element" in removedPart && removedPart.element) {
            const removedElementName = removedPart.element.nodeName.toLowerCase();
            // The document wrapper DOMPurify parses into is listed as removed when it returns a fragment — not a part.
            if (!DOCUMENT_WRAPPER_ELEMENT_NAMES.has(removedElementName))
                removedPartNames.add(removedElementName);
        }
        else if ("attribute" in removedPart && removedPart.attribute) {
            removedPartNames.add(removedPart.attribute.name.toLowerCase());
        }
    }
    domPurify.removeAllHooks();
    const rootElements = Array.from(sanitizedFragment.children);
    const svgElement = rootElements.length === 1 && rootElements[0].nodeName.toLowerCase() === "svg" ? rootElements[0] : null;
    if (svgElement === null)
        return { markup: null, removedPartNames: [...removedPartNames] };
    if (!svgElement.hasAttribute("viewBox")) {
        const width = readNumericLength(svgElement.getAttribute("width"));
        const height = readNumericLength(svgElement.getAttribute("height"));
        if (width !== null && height !== null)
            svgElement.setAttribute("viewBox", `0 0 ${width} ${height}`);
    }
    if (svgElement.hasAttribute("viewBox")) {
        svgElement.removeAttribute("width");
        svgElement.removeAttribute("height");
    }
    svgElement.setAttribute("focusable", "false");
    if (options.label) {
        svgElement.setAttribute("role", "img");
        svgElement.setAttribute("aria-label", options.label);
    }
    if (!svgElement.hasChildNodes())
        return { markup: null, removedPartNames: [...removedPartNames] };
    return { markup: svgElement.outerHTML, removedPartNames: [...removedPartNames] };
};
