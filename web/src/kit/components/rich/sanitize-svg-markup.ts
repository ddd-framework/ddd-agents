import type { DOMPurify } from "dompurify";

/**
 * Sanitises SVG markup for the rich kit components: the `Svg` node's model-written graphic and the
 * SVG Mermaid draws for a `Diagram`. DOMPurify's SVG profile, plus:
 *
 * - no `script`, `foreignObject`, link (`a`), `image` / `feImage`, `use`, animation (`animate`, `set`, …) or embedding
 *   element (DOMPurify's SVG profile leaves out `use` itself);
 * - no `style` element for a model-written graphic (Mermaid's own scoped stylesheet stays, its CSS is Mermaid's);
 * - a reference (`href`, `xlink:href`) only to an element of the same graphic (`#id`), a paint `url(…)` only as
 *   `url(#id)` — nothing that loads from outside the graphic;
 * - exactly one `<svg>` element at the root; a root without a `viewBox` gets one from its `width` / `height`, and the
 *   fixed size is dropped so the graphic scales with its box.
 *
 * Each call builds its own DOMPurify instance, so the hooks never reach another caller of the library.
 */

export interface SanitizedSvgMarkup {
  /** The safe markup of the one `<svg>` element, or null when nothing safe is left to draw. */
  markup: string | null;
  /** The names of the elements and attributes that were removed (`script`, `onload`, `href`, …), deduplicated. */
  removedPartNames: string[];
}

export interface SanitizeSvgMarkupOptions {
  /** Keep a `<style>` element (Mermaid's scoped stylesheet); false for a model-written graphic. */
  keepStyleElement: boolean;
  /** The accessible name of the graphic (`role="img"` + `aria-label`); without one the graphic keeps its own. */
  label?: string;
}

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
const usesOnlyInternalUrls = (attributeValue: string): boolean => {
  const urlPattern = /url\s*\(\s*(['"]?)(.*?)\1\s*\)/gi;
  let urlMatch = urlPattern.exec(attributeValue);
  while (urlMatch !== null) {
    if (!urlMatch[2].trim().startsWith("#")) return false;
    urlMatch = urlPattern.exec(attributeValue);
  }
  return true;
};

const readNumericLength = (lengthValue: string | null): number | null => {
  if (lengthValue === null) return null;
  const length = Number.parseFloat(lengthValue);
  return Number.isFinite(length) && length > 0 && /^\s*[\d.]+(px)?\s*$/.test(lengthValue) ? length : null;
};

export const sanitizeSvgMarkup = (
  domPurifyFactory: DOMPurify,
  svgMarkup: string,
  options: SanitizeSvgMarkupOptions,
): SanitizedSvgMarkup => {
  const domPurify = domPurifyFactory(window);
  const hookRemovedPartNames: string[] = [];
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
  const removedPartNames = new Set<string>(hookRemovedPartNames);
  for (const removedPart of domPurify.removed) {
    if ("element" in removedPart && removedPart.element) {
      const removedElementName = removedPart.element.nodeName.toLowerCase();
      // The document wrapper DOMPurify parses into is listed as removed when it returns a fragment — not a part.
      if (!DOCUMENT_WRAPPER_ELEMENT_NAMES.has(removedElementName)) removedPartNames.add(removedElementName);
    } else if ("attribute" in removedPart && removedPart.attribute) {
      removedPartNames.add(removedPart.attribute.name.toLowerCase());
    }
  }
  domPurify.removeAllHooks();

  const rootElements = Array.from(sanitizedFragment.children);
  const svgElement =
    rootElements.length === 1 && rootElements[0].nodeName.toLowerCase() === "svg" ? rootElements[0] : null;
  if (svgElement === null) return { markup: null, removedPartNames: [...removedPartNames] };

  if (!svgElement.hasAttribute("viewBox")) {
    const width = readNumericLength(svgElement.getAttribute("width"));
    const height = readNumericLength(svgElement.getAttribute("height"));
    if (width !== null && height !== null) svgElement.setAttribute("viewBox", `0 0 ${width} ${height}`);
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
  if (!svgElement.hasChildNodes()) return { markup: null, removedPartNames: [...removedPartNames] };
  return { markup: svgElement.outerHTML, removedPartNames: [...removedPartNames] };
};
