import { isClientToolToken } from "./client-tools";
/** The attribute that marks an element as a screen the user can open; its value is the target key. */
export const AGENT_ANCHOR_ATTRIBUTE = 'data-agent-anchor';
/** The attribute that puts a mark into a group (`groupKey` of `createDomScreenTargetSource()`). */
export const AGENT_ANCHOR_GROUP_ATTRIBUTE = 'data-agent-anchor-group';
const listAttribute = (element, attributeName) => (element.getAttribute(attributeName) ?? '')
    .split(',')
    .map(part => part.trim())
    .filter(part => part !== '');
/** The route of a mark: `data-agent-route`, else the path of its link. */
const routeOfMark = (element) => {
    const route = element.getAttribute('data-agent-route');
    if (route)
        return route;
    const href = element.getAttribute('href');
    if (!href)
        return null;
    try {
        const url = new URL(href, 'http://screen.invalid');
        return url.pathname;
    }
    catch {
        return null;
    }
};
/**
 * Reads one `ScreenTarget` from a mark, or null when it lacks a valid key or a route. The attributes:
 * `data-agent-anchor` (the key), `data-agent-label` (else the text), `data-agent-group`, `data-agent-route` (else the
 * link's path), and the comma lists `data-agent-parameters`, `data-agent-tabs`, `data-agent-filters`,
 * `data-agent-views`.
 */
export const readScreenTargetOfMark = (element) => {
    const key = element.getAttribute(AGENT_ANCHOR_ATTRIBUTE)?.trim() ?? '';
    if (!isClientToolToken(key))
        return null;
    const routePattern = routeOfMark(element);
    if (!routePattern)
        return null;
    const label = (element.getAttribute('data-agent-label') ?? element.textContent ?? '').replace(/\s+/g, ' ').trim() || key;
    return {
        key,
        label,
        group: element.getAttribute('data-agent-group')?.trim() || null,
        routePattern,
        parameters: listAttribute(element, 'data-agent-parameters'),
        tabs: listAttribute(element, 'data-agent-tabs'),
        filters: listAttribute(element, 'data-agent-filters').map(filterKey => ({ key: filterKey })),
        views: listAttribute(element, 'data-agent-views'),
    };
};
/**
 * A `ScreenTargetSource` from the marks of the rendered navigation: every element with `data-agent-anchor` the user
 * sees is a screen the user may open (the application renders only what its rights allow, so the marks carry its
 * rights). The first mark of a key wins. An application with a route table of its own writes its own source instead.
 */
export const createDomScreenTargetSource = (options = {}) => ({
    listTargets: () => {
        const root = options.root?.() ?? (typeof document !== 'undefined' ? document : null);
        if (!root)
            return [];
        const targets = [];
        const seenKeys = new Set();
        for (const element of Array.from(root.querySelectorAll(`[${AGENT_ANCHOR_ATTRIBUTE}]`))) {
            if (options.groupKey !== undefined && element.getAttribute(AGENT_ANCHOR_GROUP_ATTRIBUTE) !== options.groupKey)
                continue;
            const target = readScreenTargetOfMark(element);
            if (!target || seenKeys.has(target.key))
                continue;
            seenKeys.add(target.key);
            targets.push(target);
        }
        return targets;
    },
});
