/** The attribute on the root element that pins the package's colour scheme (`dark` or `light`). */
export const AGENTS_THEME_ATTRIBUTE_NAME = "data-agents-theme";
const isHostDark = (element, source) => {
    if (source.darkClassName && element.classList.contains(source.darkClassName))
        return true;
    if (source.attributeName) {
        return element.getAttribute(source.attributeName) === (source.darkAttributeValue ?? "dark");
    }
    return false;
};
/**
 * Lets the package's colour scheme (its dark set, `color-scheme`) follow a host theme kept as a class or an attribute:
 * mirrors it now and on every change into `data-agents-theme="dark" | "light"` on the root element, which the package
 * stylesheet reads before `prefers-color-scheme` and `data-theme`. Returns the stop, which removes the attribute again.
 *
 * ```ts
 * followHostColorScheme({ darkClassName: "dark" });          // <html class="dark">
 * followHostColorScheme({ attributeName: "data-mode" });     // <html data-mode="dark">
 * ```
 */
export const followHostColorScheme = (source) => {
    if (typeof document === "undefined")
        return () => undefined;
    const rootElement = document.documentElement;
    const themeElement = source.element ?? rootElement;
    const mirrorHostColorScheme = () => {
        rootElement.setAttribute(AGENTS_THEME_ATTRIBUTE_NAME, isHostDark(themeElement, source) ? "dark" : "light");
    };
    mirrorHostColorScheme();
    const attributeFilter = ["class", ...(source.attributeName ? [source.attributeName] : [])];
    const mutationObserver = new MutationObserver(mirrorHostColorScheme);
    mutationObserver.observe(themeElement, { attributes: true, attributeFilter });
    return () => {
        mutationObserver.disconnect();
        rootElement.removeAttribute(AGENTS_THEME_ATTRIBUTE_NAME);
    };
};
