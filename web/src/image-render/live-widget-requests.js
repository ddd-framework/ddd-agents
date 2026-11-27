import { isGoogleMapCapture, isGoogleMapsSdkRequestUrl } from "./widget-image-google-map";
import { isStaticBundleImageRequest } from "./widget-image-static-assets";
import { getWidgetImageCaptureReadPathPrefix } from "../provider/environment";
import { createHeaderlessResponse, interceptXMLHttpRequests, toSameRealmFetchInput, } from "../request-interception/browser-request-interception";
/** Loaded only by the capture entry. SDK signatures stay unchanged; no ambient credentials are sent. */
export const installLiveWidgetRequests = (bootstrap, readiness) => {
    const originalFetch = window.fetch;
    const fetchRead = originalFetch.bind(window);
    // The one read a capture may make of the backend: a GET without body under the capture read path, with the
    // capture token and nothing of the page (cookies, debug/noCache) — through XMLHttpRequest and through fetch alike.
    const isScopedRead = (url, method, hasBody) => Boolean(bootstrap.authToken) &&
        url.origin === window.location.origin &&
        url.pathname.startsWith(getWidgetImageCaptureReadPathPrefix()) &&
        method.toLowerCase() === "get" &&
        !hasBody &&
        !readiness.isFailed() &&
        Date.now() < bootstrap.expiresAt * 1000;
    const readScoped = async (url, signal) => {
        // The ordinary helper propagates debug/noCache from the page; capture requests never do.
        url.searchParams.delete("debug");
        url.searchParams.delete("noCache");
        const response = await fetchRead(url.toString(), {
            method: "GET",
            credentials: "omit",
            cache: "no-store",
            redirect: "error",
            referrerPolicy: "no-referrer",
            signal,
            headers: {
                Authorization: `Bearer ${bootstrap.authToken}`,
                jwtSource: "internal_requests",
                Accept: "application/json",
            },
        });
        if (!response.ok)
            throw new Error("RENDER_READ_FAILED");
        return response;
    };
    // Captured widgets may import shared telemetry/helpers. They cannot bypass the scoped reads. Three reads pass:
    // the capture read path (as through XMLHttpRequest: a client on fetch reads its data the same way), static bundle
    // images (useDynamicImport fetches icons such as review-source logos) and, for map captures only, the Google Maps SDK
    // (a map widget, or a ComposedView tree holding a map). A `Request` of another realm is read like one of this realm.
    window.fetch = async (input, init) => {
        const sameRealmInput = await toSameRealmFetchInput(input);
        const request = new Request(typeof sameRealmInput === "string" ? new URL(sameRealmInput, window.location.origin).href : sameRealmInput, init);
        const url = new URL(request.url, window.location.origin);
        const unavailable = readiness.isFailed() || Date.now() >= bootstrap.expiresAt * 1000;
        const isStaticAsset = isStaticBundleImageRequest(url, request.method);
        if (url.origin === window.location.origin && url.pathname.startsWith(getWidgetImageCaptureReadPathPrefix())) {
            const finish = readiness.beginRequest();
            try {
                if (!isScopedRead(url, request.method, request.body !== null))
                    throw new Error("RENDER_READ_DENIED");
                return await readScoped(url, init?.signal ?? request.signal);
            }
            catch {
                readiness.fail("WIDGET_HYDRATION_FAILED");
                // never the URL, the headers, the token or the remote body to the page's own error handling
                throw new TypeError("Widget hydration failed");
            }
            finally {
                finish();
            }
        }
        if (isStaticAsset && !unavailable) {
            const finish = readiness.beginRequest();
            try {
                return await fetchRead(url.toString(), {
                    credentials: "omit",
                    redirect: "error",
                    referrerPolicy: "no-referrer",
                });
            }
            finally {
                finish();
            }
        }
        const isMap = isGoogleMapCapture(bootstrap.input.widgetName, bootstrap.input.props);
        const isGoogleMapRequest = isGoogleMapsSdkRequestUrl(url);
        if (!isMap || !isGoogleMapRequest || !["GET", "POST"].includes(request.method) || unavailable) {
            readiness.fail("UNSCOPED_REQUEST");
            throw new Error("Unscoped render request");
        }
        const headers = new Headers();
        for (const name of ["content-type", "x-goog-api-key", "x-goog-api-client", "x-user-agent"]) {
            const value = request.headers.get(name);
            if (value)
                headers.set(name, value);
        }
        const finish = readiness.beginRequest();
        try {
            const response = await fetchRead(request, {
                credentials: "omit",
                referrerPolicy: "no-referrer",
                redirect: "error",
                headers,
            });
            if (!response.ok)
                throw new Error("Map request failed");
            return response;
        }
        catch {
            readiness.fail("MAP_ASSET_FAILED");
            throw new Error("Map request failed");
        }
        finally {
            finish();
        }
    };
    // XMLHttpRequest (the transport of axios's browser adapter): the scoped capture read and, for a map capture, the
    // Google Maps SDK; everything else fails closed. A Maps SDK request goes through the fetch stand-in above (the page's
    // `fetch` at this install), so it gets exactly the rules of a fetch: no credentials, no referrer, the allowed headers.
    // A failure reaches the page as a network error — status 0, no header, no body: never the URL, request headers, auth
    // token or remote exception body to the page's shared interceptors (which log errors and submit telemetry).
    const isMapCapture = isGoogleMapCapture(bootstrap.input.widgetName, bootstrap.input.props);
    const restoreXMLHttpRequest = interceptXMLHttpRequests(async (request) => {
        if (isMapCapture && isGoogleMapsSdkRequestUrl(request.url))
            return request.forward();
        const finish = readiness.beginRequest();
        try {
            if (!isScopedRead(request.url, request.method, request.body !== null))
                throw new Error("RENDER_READ_DENIED");
            const response = await readScoped(request.url, request.signal);
            const text = await response.text();
            JSON.parse(text);
            return createHeaderlessResponse(text, response.status, "OK");
        }
        catch {
            readiness.fail("WIDGET_HYDRATION_FAILED");
            throw new TypeError("Widget hydration failed");
        }
        finally {
            finish();
        }
    });
    return () => {
        restoreXMLHttpRequest();
        window.fetch = originalFetch;
    };
};
