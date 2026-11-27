import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { readFrozenHydration } from "./WidgetImageRenderContext";
import { installFrozenWidgetRequests } from "./frozen-widget-requests";
import { createWidgetImageReadiness } from "./widget-image-readiness";
import { publishWidgetImageReadiness } from "./widget-image-readiness-signal";
const bootstrap = {
    snapshotId: "snapshot",
    attemptId: "attempt",
    inputSha256: "hash",
    renderVersion: "build",
    userId: 1,
    siteId: 2,
    expiresAt: Math.floor(Date.now() / 1000) + 120,
    authToken: null,
    input: { widgetName: "ToolTrace", props: {}, hydration: {}, settings: {}, locale: "en-GB" },
};
beforeEach(() => {
    publishWidgetImageReadiness(undefined);
    vi.stubGlobal("requestAnimationFrame", (callback) => {
        callback(0);
        return 1;
    });
    Object.defineProperty(document, "fonts", {
        configurable: true,
        value: { ready: Promise.resolve(), forEach: () => { } },
    });
});
afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.body.innerHTML = "";
});
const element = () => {
    const node = document.createElement("div");
    node.textContent = "keyword rank 12";
    vi.spyOn(node, "getBoundingClientRect").mockReturnValue({
        x: 0,
        y: 0,
        width: 528,
        height: 180,
        right: 528,
        bottom: 180,
        top: 0,
        left: 0,
        toJSON: () => ({}),
    });
    document.body.append(node);
    return node;
};
/** One request through `fetch` or a plain `XMLHttpRequest`: its JSON answer, or `{ ok: false }` when it failed. */
const send = async (transport, method, url, body) => {
    if (transport === "fetch") {
        try {
            const response = await window.fetch(url, { method, body });
            const text = await response.text();
            return { ok: true, status: response.status, data: text === "asset" ? text : JSON.parse(text) };
        }
        catch {
            return { ok: false };
        }
    }
    return new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url);
        xhr.onload = () => resolve({ ok: true, status: xhr.status, data: xhr.responseText === "asset" ? "asset" : JSON.parse(xhr.responseText) });
        xhr.onerror = () => resolve({ ok: false });
        xhr.send(body ?? null);
    });
};
describe("frozen widget input", () => {
    it("uses exact hydration before the type default and treats explicit null as a result", () => {
        expect(readFrozenHydration({ keyword: "default", "keyword:1": null }, "keyword", "keyword:1")).toBeNull();
        expect(() => readFrozenHydration({}, "keyword", "keyword:1")).toThrow("MISSING_FROZEN_HYDRATION");
    });
    describe("by default (XMLHttpRequest, as the former axios adapter; fetch untouched)", () => {
        const pageFetch = window.fetch;
        afterEach(() => {
            window.fetch = pageFetch;
        });
        it("serves supplied GETs without transport and blocks mutations and unknown reads", async () => {
            const fail = vi.fn();
            const restore = installFrozenWidgetRequests({ "GET /api/keywords?a=1&b=2": { name: "dentist" } }, fail);
            try {
                expect(await send("xhr", "GET", "/api/keywords?b=2&a=1")).toEqual({ ok: true, status: 200, data: { name: "dentist" } });
                expect(await send("xhr", "POST", "/api/keywords?a=1&b=2", "{}")).toEqual({ ok: false });
                expect(await send("xhr", "GET", "/api/other")).toEqual({ ok: false });
                expect(fail).toHaveBeenCalledTimes(2);
                expect(fail).toHaveBeenCalledWith("MISSING_FROZEN_RESPONSE");
            }
            finally {
                restore();
            }
        });
        it("answers each read with its own copy", async () => {
            const responses = { "GET /api/keywords": { names: ["dentist"] } };
            const restore = installFrozenWidgetRequests(responses, vi.fn());
            try {
                const first = await send("xhr", "GET", "/api/keywords");
                first.data.names.push("changed");
                expect(await send("xhr", "GET", "/api/keywords")).toMatchObject({ data: { names: ["dentist"] } });
                expect(responses["GET /api/keywords"].names).toEqual(["dentist"]);
            }
            finally {
                restore();
            }
        });
        it("fails static images and other origins through XMLHttpRequest too, without the network", async () => {
            const network = vi.fn(async () => new Response("asset"));
            window.fetch = network;
            const fail = vi.fn();
            const restore = installFrozenWidgetRequests({}, fail);
            try {
                expect(await send("xhr", "GET", "/react/app-build/assets/logos/google.svg")).toEqual({ ok: false });
                expect(await send("xhr", "GET", "https://maps.googleapis.com/maps/api/js")).toEqual({ ok: false });
                expect(fail).toHaveBeenCalledTimes(2);
                expect(network).not.toHaveBeenCalled();
            }
            finally {
                restore();
            }
        });
        it("leaves fetch as it is", async () => {
            const network = vi.fn(async () => new Response('{"from":"network"}'));
            window.fetch = network;
            const fail = vi.fn();
            const restore = installFrozenWidgetRequests({ "GET /api/keywords": { name: "dentist" } }, fail);
            try {
                expect(window.fetch).toBe(network);
                expect(await send("fetch", "GET", "/api/keywords")).toEqual({ ok: true, status: 200, data: { from: "network" } });
                expect(fail).not.toHaveBeenCalled();
            }
            finally {
                restore();
            }
            expect(window.fetch).toBe(network);
        });
    });
    describe("with interceptFetch: fetch answered like XMLHttpRequest", () => {
        const pageFetch = window.fetch;
        afterEach(() => {
            window.fetch = pageFetch;
        });
        it("serves supplied GETs through fetch and blocks mutations and unknown reads of the page's origin", async () => {
            const network = vi.fn(async () => new Response("{}"));
            window.fetch = network;
            const fail = vi.fn();
            const restore = installFrozenWidgetRequests({ "GET /api/keywords?a=1&b=2": { name: "dentist" } }, fail, {
                interceptFetch: true,
            });
            try {
                expect(await send("fetch", "GET", "/api/keywords?b=2&a=1")).toEqual({ ok: true, status: 200, data: { name: "dentist" } });
                expect(await window.fetch(new URL("/api/keywords?a=1&b=2", window.location.origin)).then((r) => r.json())).toEqual({
                    name: "dentist",
                });
                expect(await send("fetch", "POST", "/api/keywords?a=1&b=2", "{}")).toEqual({ ok: false });
                expect(await send("fetch", "GET", "/api/other")).toEqual({ ok: false });
                expect(await send("xhr", "GET", "/api/keywords?a=1&b=2")).toMatchObject({ ok: true, data: { name: "dentist" } });
                expect(fail).toHaveBeenCalledTimes(2);
                expect(network).not.toHaveBeenCalled();
            }
            finally {
                restore();
            }
            expect(window.fetch).toBe(network);
        });
        it("lets a fetch of a static bundle image or of the Google Maps SDK pass unchanged; any other origin fails", async () => {
            const network = vi.fn(async () => new Response("asset"));
            window.fetch = network;
            const fail = vi.fn();
            const restore = installFrozenWidgetRequests({}, fail, { interceptFetch: true });
            try {
                expect(await send("fetch", "GET", "/react/app-build/assets/logos/google.svg")).toMatchObject({ ok: true });
                expect(await send("fetch", "GET", "https://maps.googleapis.com/maps/api/js")).toMatchObject({ ok: true });
                expect(network).toHaveBeenCalledTimes(2);
                expect(await send("fetch", "GET", "/react/app-build/assets/logos/google.svg?v=1")).toEqual({ ok: false });
                expect(await send("fetch", "POST", "https://elsewhere.test/collect", "{}")).toEqual({ ok: false });
                expect(await send("fetch", "GET", "http://maps.googleapis.com/maps/api/js")).toEqual({ ok: false });
                expect(network).toHaveBeenCalledTimes(2);
                expect(fail).toHaveBeenCalledTimes(3);
            }
            finally {
                restore();
            }
        });
    });
});
describe("capture readiness", () => {
    it("ignores empty decorative image slots but rejects a failed real image", async () => {
        const node = element();
        const img = document.createElement("img");
        img.alt = "";
        img.decode = vi.fn().mockRejectedValue(new Error("No image"));
        node.append(img);
        await createWidgetImageReadiness(bootstrap).ready(node);
        expect(img.decode).not.toHaveBeenCalled();
        expect(window.__AGENTS_WIDGET_IMAGE_V1__?.state).toBe("ready");
        img.src = "https://example.test/broken.png";
        await createWidgetImageReadiness(bootstrap).ready(node);
        expect(window.__AGENTS_WIDGET_IMAGE_V1__?.error_code).toBe("REQUIRED_ASSET_FAILED");
    });
    it("accepts completed score bars, but still waits when their container is busy", async () => {
        const node = element();
        node.innerHTML = '<span role="progressbar" aria-valuenow="48">48%</span>';
        await createWidgetImageReadiness(bootstrap).ready(node);
        expect(window.__AGENTS_WIDGET_IMAGE_V1__?.state).toBe("ready");
        node.firstElementChild.setAttribute("aria-busy", "true");
        await createWidgetImageReadiness(bootstrap).ready(node);
        expect(window.__AGENTS_WIDGET_IMAGE_V1__?.error_code).toBe("WIDGET_STILL_LOADING");
    });
    it("publishes the identity and measured rectangle after render", async () => {
        await createWidgetImageReadiness(bootstrap).ready(element());
        expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({
            state: "ready",
            snapshot_id: "snapshot",
            input_sha256: "hash",
            rect: { width: 528, height: 180 },
        });
        expect(document.documentElement.dataset.widgetImageState).toBe("ready");
    });
    it("waits for fonts and image decoding", async () => {
        let release;
        Object.defineProperty(document, "fonts", {
            configurable: true,
            value: {
                forEach: () => { },
                ready: new Promise((resolve) => {
                    release = resolve;
                }),
            },
        });
        const node = element();
        const img = document.createElement("img");
        img.src = "https://example.test/image.png";
        img.decode = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(img, "naturalWidth", { value: 20 });
        node.append(img);
        const result = createWidgetImageReadiness(bootstrap).ready(node);
        await Promise.resolve();
        expect(window.__AGENTS_WIDGET_IMAGE_V1__?.state).toBe("loading");
        release();
        await result;
        expect(img.decode).toHaveBeenCalledOnce();
        expect(window.__AGENTS_WIDGET_IMAGE_V1__?.state).toBe("ready");
    });
    it.each(["[aria-busy]", "[role]", ".Skeleton"])("never accepts a loading placeholder %s", async (selector) => {
        const node = element();
        const child = document.createElement("div");
        if (selector === "[aria-busy]")
            child.setAttribute("aria-busy", "true");
        else if (selector === "[role]")
            child.setAttribute("role", "progressbar");
        else
            child.className = "Skeleton";
        node.append(child);
        await createWidgetImageReadiness(bootstrap).ready(node);
        expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({ state: "failed", error_code: "WIDGET_STILL_LOADING" });
        expect(document.documentElement.dataset.widgetImageState).toBe("failed");
    });
    it("does not overwrite a failure or a newer DOM revision", async () => {
        const readiness = createWidgetImageReadiness(bootstrap);
        await readiness.ready(element(), () => false);
        expect(window.__AGENTS_WIDGET_IMAGE_V1__?.state).toBe("loading");
        readiness.fail("MISSING_FROZEN_RESPONSE");
        readiness.fail("WIDGET_HYDRATION_FAILED");
        await readiness.ready(element());
        expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({ state: "failed", error_code: "MISSING_FROZEN_RESPONSE" });
    });
    it("rejects expired requests and clipped output", async () => {
        await createWidgetImageReadiness({ ...bootstrap, expiresAt: 1 }).ready(element());
        expect(window.__AGENTS_WIDGET_IMAGE_V1__?.error_code).toBe("TOKEN_EXPIRED");
        publishWidgetImageReadiness(undefined);
        await createWidgetImageReadiness({ ...bootstrap, input: { ...bootstrap.input, maxHeight: 100 } }).ready(element());
        expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({ error_code: "WIDGET_BOUNDS_EXCEEDED" });
    });
    it("keeps the widget box on failure so a suppressed capture has a rectangle", async () => {
        await createWidgetImageReadiness({ ...bootstrap, input: { ...bootstrap.input, maxHeight: 100 } }).ready(element());
        expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({
            state: "failed",
            error_code: "WIDGET_BOUNDS_EXCEEDED",
            rect: { width: 528, height: 180 },
        });
    });
    it("reports a widget taller than the capture viewport instead of failing it", async () => {
        vi.stubGlobal("innerHeight", 100);
        await createWidgetImageReadiness(bootstrap).ready(element());
        expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({ state: "ready", rect: { height: 180 } });
    });
    it("does not expire a long-lived URL whose lifetime exceeds the timer range", () => {
        vi.useFakeTimers();
        try {
            const expiresAt = Math.floor(Date.now() / 1000) + 5 * 365 * 24 * 3600;
            const dispose = createWidgetImageReadiness({ ...bootstrap, expiresAt }).observe(element());
            vi.advanceTimersByTime(1000);
            expect(window.__AGENTS_WIDGET_IMAGE_V1__?.error_code).not.toBe("TOKEN_EXPIRED");
            dispose();
        }
        finally {
            vi.useRealTimers();
        }
    });
});
it("fails when a required font failed even though document.fonts.ready resolved", async () => {
    Object.defineProperty(document, "fonts", {
        configurable: true,
        value: {
            ready: Promise.resolve(),
            forEach: (callback) => callback({ status: "error" }),
        },
    });
    await createWidgetImageReadiness(bootstrap).ready(element());
    expect(window.__AGENTS_WIDGET_IMAGE_V1__).toMatchObject({ state: "failed", error_code: "REQUIRED_ASSET_FAILED" });
});
it("keeps live capture loading until both network reads and widget hydration settle", async () => {
    const readiness = createWidgetImageReadiness({ ...bootstrap, input: { ...bootstrap.input, mode: "live" } });
    const node = element();
    const finish = readiness.beginRequest();
    readiness.setHydrationPending(true);
    await readiness.ready(node);
    expect(window.__AGENTS_WIDGET_IMAGE_V1__?.state).toBe("loading");
    finish();
    await readiness.ready(node);
    expect(window.__AGENTS_WIDGET_IMAGE_V1__?.state).toBe("loading");
    readiness.setHydrationPending(false);
    await readiness.ready(node);
    expect(window.__AGENTS_WIDGET_IMAGE_V1__?.state).toBe("ready");
});
