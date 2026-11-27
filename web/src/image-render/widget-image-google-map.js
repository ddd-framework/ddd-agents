import { getGoogleMapWidgetNames } from "../provider/environment";
/** The deepest tree the walk follows — the backend decodes a block with the same depth (`json_decode(…, 32)`). */
const MAX_TREE_DEPTH = 32;
const isMapNodeInValue = (value, depth, googleMapWidgetNames) => {
    if (depth > MAX_TREE_DEPTH || typeof value !== "object" || value === null)
        return false;
    if (Array.isArray(value))
        return value.some((element) => isMapNodeInValue(element, depth + 1, googleMapWidgetNames));
    const wireObject = value;
    if (wireObject.type === "Map")
        return true;
    if (wireObject.type === "Widget" && typeof wireObject.widget === "string") {
        const embeddedWidgetName = wireObject.widget.replace(/Directive$/, "");
        if (googleMapWidgetNames.includes(embeddedWidgetName))
            return true;
    }
    return Object.values(wireObject).some((nested) => isMapNodeInValue(nested, depth + 1, googleMapWidgetNames));
};
/**
 * Whether a capture renders a Google map and so may load the Maps SDK (the narrow CSP and transport exception).
 * The twin of the backend's check that opens the page's CSP: the app's map widgets themselves
 * (`AgentsEnvironment.googleMapWidgetNames`), and a `ComposedView` whose tree holds a `Map` node or embeds one of them.
 * The payload is read as written; a payload that does not parse renders no map.
 */
export const isGoogleMapCapture = (widgetName, props) => {
    const googleMapWidgetNames = getGoogleMapWidgetNames();
    if (googleMapWidgetNames.includes(widgetName))
        return true;
    if (widgetName !== "ComposedView" || typeof props.payload !== "string")
        return false;
    try {
        return isMapNodeInValue(JSON.parse(props.payload)?.view, 0, googleMapWidgetNames);
    }
    catch {
        return false;
    }
};
/**
 * A request of the Google Maps SDK (its API and RPC paths, its static files) — the only other origin a map capture
 * may reach, through `fetch` and `XMLHttpRequest` alike.
 */
export const isGoogleMapsSdkRequestUrl = (url) => url.protocol === "https:" &&
    ((url.hostname === "maps.googleapis.com" && (url.pathname.startsWith("/maps/") || url.pathname.startsWith("/$rpc/"))) ||
        (url.hostname === "maps.gstatic.com" && url.pathname.startsWith("/mapfiles/")));
