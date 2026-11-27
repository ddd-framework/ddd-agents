import { SiteFavicon } from "./shared/SiteFavicon";
import { SkeletonCircle } from "../../../widgets/_shared/skeleton-primitives";
import { readSiteHostname, useReportTextNodeRenderState } from "./shared/text-node-values";
/**
 * The `Favicon` node (`FaviconComposedViewNode.php`): the small icon of the site of `url` (a page address or
 * a bare domain). A url that names no site shows the generic link glyph and reports `failed INVALID_PROP`.
 */
const FaviconNode = (props) => {
    const { props: nodeProps, isPending, runtime } = props;
    const hostname = readSiteHostname(nodeProps.url);
    const siteName = typeof nodeProps.name === "string" && nodeProps.name.trim() !== "" ? nodeProps.name : undefined;
    let renderState = "shown";
    if (isPending)
        renderState = "pending";
    else if (hostname === null)
        renderState = "invalid";
    useReportTextNodeRenderState(runtime, renderState, "Favicon", "the Favicon url names no http(s) site");
    if (isPending)
        return <SkeletonCircle size={16}/>;
    return <SiteFavicon hostname={hostname} siteName={siteName} isEmailRender={runtime.isEmailRender}/>;
};
export const composedViewComponent = { nodeType: "Favicon", component: FaviconNode };
