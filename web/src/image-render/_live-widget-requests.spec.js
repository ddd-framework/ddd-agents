import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installLiveWidgetRequests } from "./live-widget-requests";
import { getWidgetImageCaptureReadPathPrefix } from "../provider/environment";
const MAP_CAPTURE = { widgetName: "ComposedView", props: { payload: JSON.stringify({ view: { type: "Map" } }) } };
const bootstrap = (authToken = "capture-token", overrides = {}, input = {}) => ({
    snapshotId: "s",
    attemptId: "a",
    inputSha256: "h",
    renderVersion: "1",
    userId: 1,
    siteId: 2,
    expiresAt: Date.now() / 1000 + 60,
    authToken,
    input: { widgetName: "ReviewsWidget", props: {}, hydration: {}, settings: {}, locale: "en", ...input },
    ...overrides,
});
const readiness = () => {
    const failures = [];
    const open = { count: 0, begun: 0 };
    return {
        failures,
        open,
        isFailed: () => failures.length > 0,
        fail: (code) => void failures.push(code),
        beginRequest: () => {
            open.count += 1;
            open.begun += 1;
            return () => void (open.count -= 1);
        },
    };
};
/** One request through `fetch` or a plain `XMLHttpRequest` (as the page's own client would send it). */
const send = async (transport, url, options = {}) => {
    if (transport === "fetch") {
        try {
            const response = await window.fetch(url, {
                method: options.method,
                body: options.body,
                headers: options.headers,
                credentials: options.withCredentials ? "include" : undefined,
            });
            return { ok: true, status: response.status, text: await response.text() };
        }
        catch (error) {
            return { ok: false, error, message: String(error.message) };
        }
    }
    return new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        xhr.open(options.method ?? "GET", url);
        for (const [name, value] of Object.entries(options.headers ?? {}))
            xhr.setRequestHeader(name, value);
        xhr.withCredentials = options.withCredentials ?? false;
        xhr.onload = () => resolve({ ok: true, status: xhr.status, text: xhr.responseText });
        xhr.onerror = (event) => resolve({
            ok: false,
            error: event,
            status: xhr.status,
            text: xhr.responseText,
            headers: xhr.getAllResponseHeaders(),
            message: "",
        });
        xhr.send(options.body ?? null);
    });
};
describe.each(["fetch", "xhr"])("installLiveWidgetRequests — capture reads through %s", (transport) => {
    let backendFetch;
    let uninstall;
    const pageFetch = window.fetch;
    const PageXMLHttpRequest = window.XMLHttpRequest;
    const readPath = () => getWidgetImageCaptureReadPathPrefix();
    beforeEach(() => {
        backendFetch = vi.fn(async () => new Response('{"ok":true}', { status: 200, headers: { "X-Backend": "1" } }));
        window.fetch = backendFetch;
    });
    afterEach(() => {
        uninstall?.();
        uninstall = undefined;
        window.fetch = pageFetch;
    });
    it("reads the capture path with the capture token and nothing of the page", async () => {
        const state = readiness();
        uninstall = installLiveWidgetRequests(bootstrap(), state);
        const sent = await send(transport, `${readPath()}reviews?debug=1&noCache=1&top=5`, {
            headers: { Authorization: "Bearer page-token", Cookie: "session=page" },
            withCredentials: true,
        });
        expect(sent).toMatchObject({ ok: true, status: 200 });
        expect(JSON.parse(sent.text)).toEqual({ ok: true });
        const [url, init] = backendFetch.mock.calls[0];
        expect(new URL(url).search).toBe("?top=5");
        expect(init.credentials).toBe("omit");
        expect(init.cache).toBe("no-store");
        expect(init.redirect).toBe("error");
        expect(init.referrerPolicy).toBe("no-referrer");
        expect(init.headers).toEqual({
            Authorization: "Bearer capture-token",
            jwtSource: "internal_requests",
            Accept: "application/json",
        });
        expect(state.failures).toEqual([]);
        expect(state.open).toEqual({ count: 0, begun: 1 });
    });
    it("refuses a write, a read without capture token and an expired capture, and fails the capture", async () => {
        for (const [authToken, overrides, options] of [
            ["capture-token", {}, { method: "POST", body: "{}" }],
            [null, {}, {}],
            ["capture-token", { expiresAt: Date.now() / 1000 - 1 }, {}],
        ]) {
            const state = readiness();
            uninstall = installLiveWidgetRequests(bootstrap(authToken, overrides), state);
            expect(await send(transport, `${readPath()}reviews`, options)).toMatchObject({ ok: false });
            expect(state.failures).toEqual(["WIDGET_HYDRATION_FAILED"]);
            uninstall();
        }
        uninstall = undefined;
        expect(backendFetch).not.toHaveBeenCalled();
    });
    it("refuses every read once the capture failed", async () => {
        const state = readiness();
        uninstall = installLiveWidgetRequests(bootstrap(), state);
        state.fail("EARLIER");
        expect(await send(transport, `${readPath()}reviews`)).toMatchObject({ ok: false });
        expect(backendFetch).not.toHaveBeenCalled();
    });
    it("never hands the URL, the headers, the token or the remote body to the page when a read fails", async () => {
        backendFetch.mockResolvedValue(new Response('{"error":"secret backend detail"}', { status: 500, headers: { "X-Secret": "1" } }));
        const state = readiness();
        uninstall = installLiveWidgetRequests(bootstrap(), state);
        const sent = await send(transport, `${readPath()}reviews?top=5`);
        expect(sent.ok).toBe(false);
        expect(state.failures).toEqual(["WIDGET_HYDRATION_FAILED"]);
        const failure = sent;
        if (transport === "fetch") {
            expect(failure.error).toBeInstanceOf(TypeError);
            expect(failure.message).toBe("Widget hydration failed");
        }
        else {
            expect(failure.status).toBe(0);
            expect(failure.text).toBe("");
            expect(failure.headers).toBe("");
        }
        const exposed = JSON.stringify(failure) + failure.message;
        for (const secret of ["capture-token", "secret backend detail", "X-Secret", "reviews"]) {
            expect(exposed).not.toContain(secret);
        }
    });
    it("restores fetch and XMLHttpRequest", () => {
        uninstall = installLiveWidgetRequests(bootstrap(), readiness());
        uninstall();
        uninstall = undefined;
        expect(window.fetch).toBe(backendFetch);
        expect(window.XMLHttpRequest).toBe(PageXMLHttpRequest);
    });
});
describe("installLiveWidgetRequests — fetch rules", () => {
    let backendFetch;
    let uninstall;
    const pageFetch = window.fetch;
    beforeEach(() => {
        backendFetch = vi.fn(async () => new Response('{"ok":true}', { status: 200 }));
        window.fetch = backendFetch;
    });
    afterEach(() => {
        uninstall?.();
        uninstall = undefined;
        window.fetch = pageFetch;
    });
    it("refuses any other path and fails the capture as unscoped", async () => {
        for (const [url, options] of [
            ["/graphql", {}],
            ["https://elsewhere.test/collect", { method: "POST", body: "{}" }],
        ]) {
            const state = readiness();
            uninstall = installLiveWidgetRequests(bootstrap(), state);
            expect(await send("fetch", url, options)).toMatchObject({ ok: false });
            expect(state.failures).toEqual(["UNSCOPED_REQUEST"]);
            uninstall();
        }
        uninstall = undefined;
        expect(backendFetch).not.toHaveBeenCalled();
    });
    it("refuses a static image once the capture failed", async () => {
        const state = readiness();
        uninstall = installLiveWidgetRequests(bootstrap(), state);
        state.fail("EARLIER");
        expect(await send("fetch", "/react/app-build/assets/logo.svg")).toMatchObject({ ok: false });
        expect(backendFetch).not.toHaveBeenCalled();
    });
    it("loads static bundle images without credentials, but no static path with a query", async () => {
        const state = readiness();
        uninstall = installLiveWidgetRequests(bootstrap(), state);
        expect(await send("fetch", "/react/app-build/assets/review-sources/google.svg")).toMatchObject({ ok: true });
        const [url, init] = backendFetch.mock.calls[0];
        expect(url).toBe(`${window.location.origin}/react/app-build/assets/review-sources/google.svg`);
        expect(init.credentials).toBe("omit");
        expect(init.referrerPolicy).toBe("no-referrer");
        expect(await send("fetch", "/react/app-build/assets/review-sources/google.svg?x=1")).toMatchObject({ ok: false });
        expect(state.failures).toEqual(["UNSCOPED_REQUEST"]);
    });
    it("lets a map capture load the Google Maps SDK with its own headers only", async () => {
        const state = readiness();
        uninstall = installLiveWidgetRequests(bootstrap("capture-token", {}, MAP_CAPTURE), state);
        expect(await send("fetch", "https://maps.googleapis.com/$rpc/google.maps.Service/Get", {
            method: "POST",
            body: "[1]",
            headers: { "Content-Type": "application/json+protobuf", "X-Goog-Api-Key": "maps-key", Authorization: "Bearer page" },
            withCredentials: true,
        })).toMatchObject({ ok: true });
        const [mapRequest, init] = backendFetch.mock.calls[0];
        expect([mapRequest.url, mapRequest.method, await mapRequest.text()]).toEqual([
            "https://maps.googleapis.com/$rpc/google.maps.Service/Get",
            "POST",
            "[1]",
        ]);
        expect(init).toMatchObject({ credentials: "omit", referrerPolicy: "no-referrer", redirect: "error" });
        expect([...init.headers.entries()]).toEqual([
            ["content-type", "application/json+protobuf"],
            ["x-goog-api-key", "maps-key"],
        ]);
        expect(await send("fetch", "https://maps.gstatic.com/mapfiles/a.png")).toMatchObject({ ok: true });
        expect(state.failures).toEqual([]);
    });
    it("refuses Google Maps outside a map capture and fails the capture when a map request fails", async () => {
        const notMap = readiness();
        uninstall = installLiveWidgetRequests(bootstrap(), notMap);
        expect(await send("fetch", "https://maps.googleapis.com/maps/api/js")).toMatchObject({ ok: false });
        expect(notMap.failures).toEqual(["UNSCOPED_REQUEST"]);
        uninstall();
        backendFetch.mockResolvedValue(new Response("nope", { status: 403 }));
        const map = readiness();
        uninstall = installLiveWidgetRequests(bootstrap("capture-token", {}, MAP_CAPTURE), map);
        expect(await send("fetch", "https://maps.googleapis.com/maps/api/js")).toMatchObject({ ok: false });
        expect(map.failures).toEqual(["MAP_ASSET_FAILED"]);
    });
});
describe("installLiveWidgetRequests — XMLHttpRequest rules (the former axios adapter)", () => {
    let backendFetch;
    let uninstall;
    const pageFetch = window.fetch;
    const readPath = () => getWidgetImageCaptureReadPathPrefix();
    beforeEach(() => {
        backendFetch = vi.fn(async () => new Response('{"ok":true}', { status: 200, headers: { "X-Backend": "1" } }));
        window.fetch = backendFetch;
    });
    afterEach(() => {
        uninstall?.();
        uninstall = undefined;
        window.fetch = pageFetch;
    });
    it("refuses every other request — other paths, other origins, static images, Google Maps outside a map capture — as a failed hydration", async () => {
        for (const [input, url, options] of [
            [{}, "/graphql", {}],
            [{}, "https://elsewhere.test/collect", { method: "POST", body: "{}" }],
            [{}, "/react/app-build/assets/review-sources/google.svg", {}],
            [{}, "https://maps.googleapis.com/maps/api/js", {}],
            [MAP_CAPTURE, "https://elsewhere.test/collect", {}],
        ]) {
            const state = readiness();
            uninstall = installLiveWidgetRequests(bootstrap("capture-token", {}, input), state);
            const sent = await send("xhr", url, options);
            expect(sent).toMatchObject({ ok: false, status: 0, text: "", headers: "" });
            expect(state.failures).toEqual(["WIDGET_HYDRATION_FAILED"]);
            uninstall();
        }
        uninstall = undefined;
        expect(backendFetch).not.toHaveBeenCalled();
    });
    it("sends a map capture's Google Maps SDK request through the fetch rules: no credentials, its own headers only", async () => {
        const state = readiness();
        uninstall = installLiveWidgetRequests(bootstrap("capture-token", {}, MAP_CAPTURE), state);
        expect(await send("xhr", "https://maps.googleapis.com/$rpc/google.maps.Service/Get", {
            method: "POST",
            body: "[1]",
            headers: { "Content-Type": "application/json+protobuf", "X-Goog-Api-Key": "maps-key", Authorization: "Bearer page" },
            withCredentials: true,
        })).toMatchObject({ ok: true, status: 200 });
        const [mapRequest, init] = backendFetch.mock.calls[0];
        expect([mapRequest.url, mapRequest.method, await mapRequest.text()]).toEqual([
            "https://maps.googleapis.com/$rpc/google.maps.Service/Get",
            "POST",
            "[1]",
        ]);
        expect(init).toMatchObject({ credentials: "omit", referrerPolicy: "no-referrer", redirect: "error" });
        expect([...init.headers.entries()]).toEqual([
            ["content-type", "application/json+protobuf"],
            ["x-goog-api-key", "maps-key"],
        ]);
        backendFetch.mockResolvedValue(new Response("nope", { status: 403 }));
        expect(await send("xhr", "https://maps.gstatic.com/mapfiles/a.png")).toMatchObject({ ok: false, status: 0, text: "" });
        expect(state.failures).toEqual(["MAP_ASSET_FAILED"]);
    });
    it("refuses a GET that passes a body", async () => {
        const state = readiness();
        uninstall = installLiveWidgetRequests(bootstrap(), state);
        expect(await send("xhr", `${readPath()}reviews`, { body: "{}" })).toMatchObject({ ok: false });
        expect(state.failures).toEqual(["WIDGET_HYDRATION_FAILED"]);
        expect(backendFetch).not.toHaveBeenCalled();
    });
    it("answers the read's JSON without the backend's headers, and fails a body that is no JSON", async () => {
        const state = readiness();
        uninstall = installLiveWidgetRequests(bootstrap(), state);
        const xhr = await new Promise((resolve) => {
            const request = new XMLHttpRequest();
            request.open("GET", `${readPath()}reviews`);
            request.onloadend = () => resolve(request);
            request.send();
        });
        expect([xhr.status, xhr.responseText, xhr.getAllResponseHeaders()]).toEqual([200, '{"ok":true}', ""]);
        backendFetch.mockResolvedValue(new Response("<html>", { status: 200 }));
        expect(await send("xhr", `${readPath()}reviews`)).toMatchObject({ ok: false, status: 0, text: "" });
        expect(state.failures).toEqual(["WIDGET_HYDRATION_FAILED"]);
    });
});
describe("installLiveWidgetRequests — fetch inputs", () => {
    const pageFetch = window.fetch;
    afterEach(() => {
        window.fetch = pageFetch;
    });
    it("reads a capture path given as a URL or as a Request of this or another realm", async () => {
        const backendFetch = vi.fn(async () => new Response('{"ok":true}'));
        window.fetch = backendFetch;
        const state = readiness();
        const uninstall = installLiveWidgetRequests(bootstrap(), state);
        try {
            const path = `${getWidgetImageCaptureReadPathPrefix()}reviews`;
            await window.fetch(new URL(path, window.location.origin));
            await window.fetch(new Request(new URL(path, window.location.origin).href));
            // A Request of another realm: the same brand, another prototype chain.
            const source = new Request(new URL(path, window.location.origin).href);
            const otherRealm = Object.create({
                get [Symbol.toStringTag]() {
                    return "Request";
                },
            }, {
                url: { value: source.url },
                method: { value: "GET" },
                headers: { value: source.headers },
                body: { value: null },
                signal: { value: source.signal },
            });
            await window.fetch(otherRealm);
            expect(backendFetch).toHaveBeenCalledTimes(3);
            expect(state.failures).toEqual([]);
        }
        finally {
            uninstall();
        }
    });
});
