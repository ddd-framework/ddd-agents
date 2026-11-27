import { createHeaderlessResponse, interceptXMLHttpRequests, isFetchRequest } from "../request-interception/browser-request-interception";
/** The key a fixture response is stored under: method, path and the sorted query (`GET /api/example/…?a=1&b=2`). */
export const widgetPreviewRequestKey = (method, url) => {
    const sortedUrl = new URL(url.toString());
    sortedUrl.searchParams.sort();
    return `${method.toUpperCase()} ${sortedUrl.pathname}${sortedUrl.search}`;
};
/** The app's own error log: answered empty and never reported — a fixture's error state must not count as a gap. */
const SILENTLY_ANSWERED_PATHS = ["/rest_api/v1/log"];
const isSilentlyAnswered = (url) => SILENTLY_ANSWERED_PATHS.includes(url.pathname);
export const isStatusResponse = (response) => typeof response === "object" &&
    response !== null &&
    typeof response.__status === "number";
export const isPendingResponse = (response) => typeof response === "object" && response !== null && response.__pending === true;
/** A fixture answer as an `XMLHttpRequest` reads it; a status a `Response` cannot carry fails like a network error. */
const xhrAnswer = (body, status) => {
    if (status < 200 || status > 599)
        throw new TypeError(`preview response ${status}`);
    if ([204, 205, 304].includes(status))
        return createHeaderlessResponse(null, status);
    return createHeaderlessResponse(JSON.stringify(body), status, status === 200 ? "OK" : "");
};
/**
 * Serves every request of the preview — `XMLHttpRequest` (the transport of axios's browser adapter) and `fetch` — from
 * the fixture: a known request gets its body (a copy), a `__status` entry fails with that status (a status of 400 or
 * more is reported as a failed request — the error state the fixture asked for), a `__pending` entry never answers (the
 * loading state), and anything else — an unknown read, a write the fixture does not answer — fails as a missing
 * response and is reported. A write is answered only when the fixture names it (`"POST /api/example/…"`, e.g. a
 * widget's fire-and-forget measurement event). Nothing reaches a server.
 */
export const installWidgetPreviewRequests = (responses, onMissingResponse, onFailedRequest = () => { }) => {
    // XMLHttpRequest: a missing response fails like a network error (no response), a `__status` entry answers with its
    // status and `__body`.
    const restoreXMLHttpRequest = interceptXMLHttpRequests(async (request) => {
        if (isSilentlyAnswered(request.url))
            return xhrAnswer({}, 200);
        const requestKey = widgetPreviewRequestKey(request.method, request.url);
        const response = Object.prototype.hasOwnProperty.call(responses, requestKey) ? responses[requestKey] : undefined;
        if (response === undefined) {
            onMissingResponse(requestKey);
            throw new Error(`no preview response for ${requestKey}`);
        }
        if (isPendingResponse(response))
            return new Promise(() => { });
        if (isStatusResponse(response)) {
            if (response.__status >= 400)
                onFailedRequest(requestKey);
            return xhrAnswer(response.__body ?? null, response.__status);
        }
        return xhrAnswer(response, 200);
    });
    // `fetch` is answered the same way (a few modules bypass the XMLHttpRequest client); a missing response is a 404,
    // reported. A `Request` of another realm is read by its brand, not by `instanceof`.
    const originalFetch = window.fetch;
    window.fetch = async (input, init) => {
        const fromRequest = isFetchRequest(input) ? input : undefined;
        const method = (init?.method ?? (fromRequest ? fromRequest.method : "GET")).toLowerCase();
        const url = new URL(fromRequest ? fromRequest.url : String(input), window.location.origin);
        if (isSilentlyAnswered(url))
            return new Response("{}", { status: 200 });
        const requestKey = widgetPreviewRequestKey(method, url);
        const response = Object.prototype.hasOwnProperty.call(responses, requestKey) ? responses[requestKey] : undefined;
        if (response === undefined) {
            onMissingResponse(requestKey);
            return new Response(null, { status: 404 });
        }
        if (isPendingResponse(response))
            return new Promise(() => { });
        if (isStatusResponse(response)) {
            if (response.__status >= 400)
                onFailedRequest(requestKey);
            return new Response(JSON.stringify(response.__body ?? null), { status: response.__status });
        }
        return new Response(JSON.stringify(response), { status: 200, headers: { "Content-Type": "application/json" } });
    };
    return () => {
        restoreXMLHttpRequest();
        window.fetch = originalFetch;
    };
};
