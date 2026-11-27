/**
 * Whether a Markdown image in model text may load its source. Model text is foreign: an image of any host would let a
 * page or a document the agent read make the browser send what the model knows to that host (the data rides in the
 * URL). Allowed: the page's own origin (a relative URL included), `blob:` URLs, inline `data:image/…` raster images and
 * the hosts the app lists in `AgentsEnvironment.markdownImageHosts` (`cdn.example.com`, or `*.example.com` for every
 * subdomain). Everything else renders as a link the owner may open.
 */
export const isAllowedMarkdownImageSource = (source, pageOrigin, allowedHosts) => {
    const trimmedSource = source.trim();
    if (trimmedSource === "") {
        return false;
    }
    if (/^data:image\/(png|jpe?g|gif|webp);/i.test(trimmedSource)) {
        return true;
    }
    let sourceUrl;
    try {
        sourceUrl = new URL(trimmedSource, pageOrigin ?? "http://invalid.invalid");
    }
    catch {
        return false;
    }
    if (sourceUrl.protocol === "blob:") {
        return true;
    }
    if (sourceUrl.protocol !== "https:" && sourceUrl.protocol !== "http:") {
        return false;
    }
    if (pageOrigin !== undefined && sourceUrl.origin === pageOrigin) {
        return true;
    }
    if (sourceUrl.protocol !== "https:") {
        return false;
    }
    const sourceHost = sourceUrl.hostname.toLowerCase();
    return (allowedHosts ?? []).some((allowedHost) => {
        const normalizedHost = allowedHost.trim().toLowerCase();
        if (normalizedHost.startsWith("*.")) {
            const parentHost = normalizedHost.slice(2);
            return sourceHost.endsWith(`.${parentHost}`);
        }
        return normalizedHost !== "" && sourceHost === normalizedHost;
    });
};
