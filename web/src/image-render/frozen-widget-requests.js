import { createHeaderlessResponse, interceptFetchRequests, interceptXMLHttpRequests, } from "../request-interception/browser-request-interception";
import { isGoogleMapsSdkRequestUrl } from "./widget-image-google-map";
import { isStaticBundleImageRequest } from "./widget-image-static-assets";
/** The key a frozen response is stored under: method, path and the sorted query (`GET /api/keywords?a=1&b=2`). */
const frozenRequestKey = (request) => {
    const url = new URL(request.url.href);
    url.searchParams.sort();
    return `${request.method} ${url.pathname}${url.search}`;
};
const findFrozenResponse = (responses, request) => {
    const key = frozenRequestKey(request);
    return request.method === "GET" && Object.prototype.hasOwnProperty.call(responses, key)
        ? { value: responses[key] }
        : undefined;
};
/**
 * The capture page serves supplied GET results in memory. It never lends the render token to the SDK.
 *
 * `XMLHttpRequest` (the transport of axios's browser adapter) is always answered: a supplied GET with its result, any
 * other request fails with MISSING_FROZEN_RESPONSE. `fetch` is left alone unless `options.interceptFetch` is set; then
 * a `fetch` gets the same answers and the same failure, except that a static bundle image of the page's origin and a
 * request of the Google Maps SDK still go to the network unchanged; any other origin fails as well.
 */
export const installFrozenWidgetRequests = (responses, fail, options = {}) => {
    const refuse = () => {
        fail("MISSING_FROZEN_RESPONSE");
        throw new Error("MISSING_FROZEN_RESPONSE");
    };
    const restoreXMLHttpRequest = interceptXMLHttpRequests(async (request) => {
        const frozen = findFrozenResponse(responses, request);
        if (!frozen)
            return refuse();
        return createHeaderlessResponse(JSON.stringify(frozen.value), 200, "OK");
    });
    const restoreFetch = options.interceptFetch
        ? interceptFetchRequests(async (request) => {
            const frozen = findFrozenResponse(responses, request);
            if (frozen) {
                return new Response(JSON.stringify(frozen.value), {
                    status: 200,
                    statusText: "OK",
                    headers: { "Content-Type": "application/json" },
                });
            }
            const passesUnanswered = isGoogleMapsSdkRequestUrl(request.url) || isStaticBundleImageRequest(request.url, request.method);
            return passesUnanswered ? request.forward() : refuse();
        })
        : () => { };
    return () => {
        restoreXMLHttpRequest();
        restoreFetch();
    };
};
