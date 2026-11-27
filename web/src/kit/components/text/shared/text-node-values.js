import { useEffect } from "react";
/** A string, a finite number or a boolean is text; `null`, `undefined` and the empty string are missing; the rest is invalid. */
export const readTextNodeContent = (value) => {
    if (value === undefined || value === null)
        return { kind: "missing" };
    if (typeof value === "string")
        return value.trim() === "" ? { kind: "missing" } : { kind: "text", text: value };
    if (typeof value === "number")
        return Number.isFinite(value) ? { kind: "text", text: String(value) } : { kind: "missing" };
    if (typeof value === "boolean")
        return { kind: "text", text: String(value) };
    return { kind: "invalid" };
};
/** The value when it is one of `allowedValues`, else `fallback` — an enum prop bound to state may hold anything. */
export const readAllowedValue = (value, allowedValues, fallback) => (typeof value === "string" && allowedValues.includes(value) ? value : fallback);
/**
 * The address when it is an absolute http(s) URL, else null — `javascript:`, `data:`, `mailto:`, a relative path and a
 * malformed string are never a link target in the kit (the `openUrl` action holds the same rule).
 */
export const readSafeHttpUrl = (value) => {
    if (typeof value !== "string" || value.trim() === "")
        return null;
    try {
        const parsedUrl = new URL(value.trim());
        return parsedUrl.protocol === "http:" || parsedUrl.protocol === "https:" ? parsedUrl.href : null;
    }
    catch {
        return null;
    }
};
const HOSTNAME_PATTERN = /^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i;
/** The site's host name (without `www.`) of an http(s) URL or a bare domain (`wdr.de`), else null. */
export const readSiteHostname = (value) => {
    if (typeof value !== "string" || value.trim() === "")
        return null;
    const trimmedValue = value.trim();
    const httpUrl = readSafeHttpUrl(/^[a-z][a-z0-9+.-]*:/i.test(trimmedValue) ? trimmedValue : `https://${trimmedValue}`);
    if (httpUrl === null)
        return null;
    const hostname = new URL(httpUrl).hostname.replace(/^www\./i, "");
    return HOSTNAME_PATTERN.test(hostname) ? hostname : null;
};
/** The favicon of a host, from the Google favicon service — the source the web search references use. */
export const buildFaviconUrl = (hostname) => `https://www.google.com/s2/favicons?domain=${encodeURIComponent(hostname)}&sz=32`;
/**
 * Reports a text node's render state into the block report whenever it changes — never a silent nothing:
 * `empty` when there is nothing to show, `failed INVALID_PROP` when the value cannot be shown, `pending` while a bound
 * value waits for its data.
 */
export const useReportTextNodeRenderState = (runtime, renderState, nodeType, invalidDetail) => {
    const { reportNodeStatus } = runtime;
    useEffect(() => {
        if (renderState === "pending")
            reportNodeStatus("pending");
        if (renderState === "shown")
            reportNodeStatus("mounted");
        if (renderState === "empty")
            reportNodeStatus("empty", "NO_DATA", `the ${nodeType} has no text to show`);
        if (renderState === "invalid") {
            reportNodeStatus("failed", "INVALID_PROP", invalidDetail ?? `the ${nodeType} got a value that is not text`);
        }
    }, [reportNodeStatus, renderState, nodeType, invalidDetail]);
};
/** The render state of a node whose one text prop decides it. */
export const readTextNodeRenderState = (isPending, content) => {
    if (isPending)
        return "pending";
    if (content.kind === "text")
        return "shown";
    return content.kind === "invalid" ? "invalid" : "empty";
};
