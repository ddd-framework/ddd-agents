import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installWidgetPreviewRequests, widgetPreviewRequestKey } from "./widget-preview-requests";
/** One request through `fetch` or a plain `XMLHttpRequest`; `settled: false` when it has not ended after a while. */
const send = async (transport, method, url, body) => {
    const request = transport === "fetch"
        ? window
            .fetch(url, { method, body })
            .then(async (response) => {
            const text = await response.text();
            return { settled: true, status: response.status, body: text === "" ? null : JSON.parse(text) };
        })
            .catch(() => ({ settled: true, failed: true }))
        : new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open(method, url);
            xhr.responseType = "json";
            xhr.onload = () => resolve({ settled: true, status: xhr.status, body: xhr.response });
            xhr.onerror = () => resolve({ settled: true, failed: true });
            xhr.send(body ?? null);
        });
    const pending = new Promise((resolve) => setTimeout(() => resolve({ settled: false }), 20));
    return Promise.race([request, pending]);
};
const responses = {
    "GET /api/keywords?a=1&b=2": { names: ["dentist"] },
    "POST /api/events": { recorded: true },
    "GET /api/broken": { __status: 500, __body: { error: "boom" } },
    "GET /api/not-found": { __status: 404 },
    "GET /api/accepted": { __status: 202, __body: { queued: true } },
    "GET /api/no-content": { __status: 204 },
    "GET /api/loading": { __pending: true },
};
const missingAnswer = (transport) => transport === "xhr" ? { settled: true, failed: true } : { settled: true, status: 404, body: null };
describe("widgetPreviewRequestKey", () => {
    it("is the upper-case method, the path and the sorted query", () => {
        expect(widgetPreviewRequestKey("get", new URL("http://localhost/api/x?b=2&a=1"))).toBe("GET /api/x?a=1&b=2");
    });
});
describe.each(["fetch", "xhr"])("installWidgetPreviewRequests through %s", (transport) => {
    const pageFetch = window.fetch;
    const PageXMLHttpRequest = window.XMLHttpRequest;
    let network;
    let restore;
    let missing;
    let failed;
    beforeEach(() => {
        network = vi.fn(async () => new Response("{}"));
        window.fetch = network;
        missing = vi.fn();
        failed = vi.fn();
        restore = installWidgetPreviewRequests(responses, missing, failed);
    });
    afterEach(() => {
        restore?.();
        window.fetch = pageFetch;
    });
    it("answers a known read by its sorted key, with a copy", async () => {
        const first = await send(transport, "GET", "/api/keywords?b=2&a=1");
        expect(first).toEqual({ settled: true, status: 200, body: { names: ["dentist"] } });
        first.body.names.push("changed");
        expect(await send(transport, "GET", "/api/keywords?a=1&b=2")).toMatchObject({ body: { names: ["dentist"] } });
        expect(missing).not.toHaveBeenCalled();
    });
    it("answers a write only when the fixture names it", async () => {
        expect(await send(transport, "POST", "/api/events", "{}")).toEqual({ settled: true, status: 200, body: { recorded: true } });
        expect(await send(transport, "POST", "/api/keywords?a=1&b=2", "{}")).toEqual(missingAnswer(transport));
        expect(missing).toHaveBeenCalledWith("POST /api/keywords?a=1&b=2");
    });
    it("fails an unknown read (XMLHttpRequest: no response, as the former axios adapter; fetch: 404) and reports it missing", async () => {
        expect(await send(transport, "GET", "/api/unknown?z=1&y=2")).toEqual(missingAnswer(transport));
        expect(missing).toHaveBeenCalledWith("GET /api/unknown?y=2&z=1");
        expect(failed).not.toHaveBeenCalled();
    });
    it("answers a __status entry with its status and body, reporting a status of 400 or more as failed", async () => {
        expect(await send(transport, "GET", "/api/broken")).toEqual({ settled: true, status: 500, body: { error: "boom" } });
        expect(await send(transport, "GET", "/api/not-found")).toEqual({ settled: true, status: 404, body: null });
        expect(await send(transport, "GET", "/api/accepted")).toEqual({ settled: true, status: 202, body: { queued: true } });
        // fetch: unchanged from before, a Response cannot carry the fixture's body with 204.
        expect(await send(transport, "GET", "/api/no-content")).toEqual(transport === "xhr" ? { settled: true, status: 204, body: null } : { settled: true, failed: true });
        expect(failed.mock.calls).toEqual([["GET /api/broken"], ["GET /api/not-found"]]);
        expect(missing).not.toHaveBeenCalled();
    });
    it("never answers a __pending entry", async () => {
        expect(await send(transport, "GET", "/api/loading")).toEqual({ settled: false });
    });
    it("answers the app's error log empty without reporting it", async () => {
        expect(await send(transport, "POST", "/rest_api/v1/log", "{}")).toEqual({ settled: true, status: 200, body: {} });
        expect(missing).not.toHaveBeenCalled();
        expect(failed).not.toHaveBeenCalled();
    });
    it("restores fetch and XMLHttpRequest", () => {
        restore?.();
        restore = undefined;
        expect(window.fetch).toBe(network);
        expect(window.XMLHttpRequest).toBe(PageXMLHttpRequest);
    });
    it("sends nothing to a server", async () => {
        await send(transport, "GET", "https://elsewhere.test/api/keywords?a=1&b=2");
        await send(transport, "GET", "/api/unknown");
        expect(network).not.toHaveBeenCalled();
    });
});
describe("installWidgetPreviewRequests — fetch inputs", () => {
    it("reads a URL and a Request of any realm", async () => {
        const missing = vi.fn();
        const restore = installWidgetPreviewRequests(responses, missing);
        try {
            expect((await window.fetch(new URL("/api/keywords?b=2&a=1", window.location.origin))).status).toBe(200);
            expect((await window.fetch(new Request(`${window.location.origin}/api/events`, { method: "POST", body: "{}" }))).status).toBe(200);
            const otherRealm = Object.create({
                get [Symbol.toStringTag]() {
                    return "Request";
                },
            }, { url: { value: `${window.location.origin}/api/events` }, method: { value: "POST" } });
            expect(otherRealm instanceof Request).toBe(false);
            expect(await (await window.fetch(otherRealm)).json()).toEqual({ recorded: true });
            expect(missing).not.toHaveBeenCalled();
        }
        finally {
            restore();
        }
    });
});
