import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { interceptFetchRequests, interceptXMLHttpRequests, isFetchRequest, toSameRealmFetchInput, } from "./browser-request-interception";
/** Sends one request through the page's `XMLHttpRequest` and resolves once it ended (load, error, abort or timeout). */
const sendXhr = (method, url, options = {}) => new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    const events = [];
    for (const type of ["loadstart", "progress", "load", "error", "abort", "timeout", "loadend"]) {
        xhr.addEventListener(type, () => events.push(type));
    }
    xhr.open(method, url);
    for (const [name, value] of Object.entries(options.headers ?? {}))
        xhr.setRequestHeader(name, value);
    if (options.responseType)
        xhr.responseType = options.responseType;
    xhr.onloadend = () => resolve({ xhr, events });
    xhr.send(options.body ?? null);
});
const jsonAnswer = (body, status = 200, headers = {}) => new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json", ...headers } });
describe("interceptFetchRequests and interceptXMLHttpRequests", () => {
    let restore;
    let seen;
    const install = (answer) => {
        seen = [];
        const handler = async (request) => {
            seen.push(request);
            return answer(request);
        };
        const restoreFetch = interceptFetchRequests(handler);
        const restoreXMLHttpRequest = interceptXMLHttpRequests(handler);
        restore = () => {
            restoreXMLHttpRequest();
            restoreFetch();
        };
    };
    const pageFetch = window.fetch;
    const PageXMLHttpRequest = window.XMLHttpRequest;
    beforeEach(() => {
        seen = [];
    });
    afterEach(() => {
        restore?.();
        restore = undefined;
        window.fetch = pageFetch;
    });
    it("replaces only what each install names", () => {
        const restoreXMLHttpRequest = interceptXMLHttpRequests(async () => jsonAnswer({}));
        expect(window.fetch).toBe(pageFetch);
        expect(window.XMLHttpRequest).not.toBe(PageXMLHttpRequest);
        restoreXMLHttpRequest();
        const restoreFetch = interceptFetchRequests(async () => jsonAnswer({}));
        expect(window.fetch).not.toBe(pageFetch);
        expect(window.XMLHttpRequest).toBe(PageXMLHttpRequest);
        restoreFetch();
        expect(window.fetch).toBe(pageFetch);
    });
    it("replaces fetch and XMLHttpRequest for the duration and restores both", () => {
        install(() => jsonAnswer({}));
        expect(window.fetch).not.toBe(pageFetch);
        expect(window.XMLHttpRequest).not.toBe(PageXMLHttpRequest);
        restore();
        restore = undefined;
        expect(window.fetch).toBe(pageFetch);
        expect(window.XMLHttpRequest).toBe(PageXMLHttpRequest);
    });
    describe("fetch", () => {
        it("reads a string, a URL and a Request alike, init taking precedence", async () => {
            install(() => jsonAnswer({ ok: true }));
            await window.fetch("/api/a?x=1", { method: "post", body: "payload", headers: { "X-One": "1" } });
            await window.fetch(new URL("https://example.test/b"));
            await window.fetch(new Request("http://localhost:3000/c", { method: "PUT", body: "from request", headers: { "X-Two": "2" } }));
            expect(seen.map((request) => [request.transport, request.method, request.url.href])).toEqual([
                ["fetch", "POST", "http://localhost:3000/api/a?x=1"],
                ["fetch", "GET", "https://example.test/b"],
                ["fetch", "PUT", "http://localhost:3000/c"],
            ]);
            expect(seen[0].headers.get("x-one")).toBe("1");
            expect(seen[0].body).toBe("payload");
            expect(seen[1].body).toBeNull();
            expect(seen[2].headers.get("x-two")).toBe("2");
            expect(new TextDecoder().decode(seen[2].body)).toBe("from request");
        });
        it("reads a Request of another realm by its brand, never by instanceof", async () => {
            install(() => jsonAnswer({ ok: true }));
            const foreign = new Request("http://localhost:3000/foreign?q=1", { method: "DELETE", headers: { "X-Realm": "other" } });
            // A Request of another realm: the same brand, another prototype chain.
            const otherRealm = Object.create({
                get [Symbol.toStringTag]() {
                    return "Request";
                },
            }, {
                url: { value: foreign.url },
                method: { value: foreign.method },
                headers: { value: foreign.headers },
                body: { value: null },
                signal: { value: foreign.signal },
            });
            expect(otherRealm instanceof Request).toBe(false);
            expect(isFetchRequest(otherRealm)).toBe(true);
            await window.fetch(otherRealm);
            expect(seen[0]).toMatchObject({ method: "DELETE", transport: "fetch" });
            expect(seen[0].url.href).toBe("http://localhost:3000/foreign?q=1");
            expect(seen[0].headers.get("x-realm")).toBe("other");
        });
        it("rejects with the handler's error", async () => {
            install(() => {
                throw new TypeError("refused");
            });
            await expect(window.fetch("/api/a")).rejects.toThrow("refused");
        });
        it("forwards a request unchanged to the original fetch on demand", async () => {
            const original = vi.fn(async () => jsonAnswer({ forwarded: true }));
            window.fetch = original;
            install((request) => request.forward());
            const init = { method: "POST", body: "x" };
            expect(await (await window.fetch("/api/a", init)).json()).toEqual({ forwarded: true });
            expect(original).toHaveBeenCalledWith("/api/a", init);
        });
    });
    describe("XMLHttpRequest", () => {
        it("sends method, URL, headers and body to the handler and answers text with status and headers", async () => {
            install(() => new Response("hello", { status: 201, statusText: "Created", headers: { "X-Answer": "yes" } }));
            const states = [];
            const xhr = new XMLHttpRequest();
            xhr.onreadystatechange = () => states.push(xhr.readyState);
            const done = new Promise((resolve) => xhr.addEventListener("loadend", () => resolve()));
            xhr.open("post", "/api/items?b=2");
            xhr.setRequestHeader("Content-Type", "text/plain");
            xhr.withCredentials = true;
            xhr.send("payload");
            await done;
            expect(seen[0]).toMatchObject({ transport: "xhr", method: "POST", body: "payload" });
            expect(seen[0].url.href).toBe("http://localhost:3000/api/items?b=2");
            expect(seen[0].headers.get("content-type")).toBe("text/plain");
            expect(xhr.status).toBe(201);
            expect(xhr.statusText).toBe("Created");
            expect(xhr.readyState).toBe(XMLHttpRequest.DONE);
            expect(xhr.responseText).toBe("hello");
            expect(xhr.response).toBe("hello");
            expect(xhr.getResponseHeader("x-answer")).toBe("yes");
            expect(xhr.getAllResponseHeaders()).toContain("x-answer: yes\r\n");
            expect(xhr.responseURL).toBe("http://localhost:3000/api/items?b=2");
            expect(states).toEqual([1, 2, 3, 4]);
        });
        it("shows the handler a body passed with GET, but never forwards it", async () => {
            const original = vi.fn(async () => jsonAnswer({}));
            window.fetch = original;
            install((request) => request.forward());
            await sendXhr("GET", "/api/a", { body: "passed" });
            expect(seen[0].body).toBe("passed");
            expect(original.mock.calls[0][1].body).toBeNull();
            await sendXhr("GET", "/api/a");
            expect(seen[1].body).toBeNull();
        });
        it.each([
            ["", "string"],
            ["text", "string"],
            ["json", "object"],
            ["arraybuffer", "arraybuffer"],
            ["blob", "blob"],
        ])("answers responseType %s", async (responseType, kind) => {
            install(() => jsonAnswer({ name: "dentist" }));
            const { xhr } = await sendXhr("GET", "/api/a", { responseType });
            if (kind === "string")
                expect(JSON.parse(xhr.response)).toEqual({ name: "dentist" });
            if (kind === "object")
                expect(xhr.response).toEqual({ name: "dentist" });
            if (kind === "arraybuffer") {
                expect(Object.prototype.toString.call(xhr.response)).toBe("[object ArrayBuffer]");
                expect(JSON.parse(new TextDecoder().decode(xhr.response))).toEqual({ name: "dentist" });
            }
            if (kind === "blob")
                expect(JSON.parse(await xhr.response.text())).toEqual({ name: "dentist" });
            if (responseType === "json")
                expect(() => xhr.responseText).toThrow();
        });
        it("fires the load events through listeners and on-handlers", async () => {
            install(() => jsonAnswer({}));
            const onload = vi.fn();
            const xhr = new XMLHttpRequest();
            xhr.onload = onload;
            const events = [];
            for (const type of ["loadstart", "progress", "load", "loadend", "readystatechange"]) {
                xhr.addEventListener(type, () => events.push(type));
            }
            const done = new Promise((resolve) => (xhr.onloadend = () => resolve()));
            xhr.open("GET", "/api/a");
            xhr.send();
            await done;
            expect(onload).toHaveBeenCalledOnce();
            expect(events).toEqual([
                "readystatechange",
                "loadstart",
                "readystatechange",
                "readystatechange",
                "progress",
                "readystatechange",
                "load",
                "loadend",
            ]);
        });
        it("reports upload progress of a body", async () => {
            install(() => jsonAnswer({}));
            const xhr = new XMLHttpRequest();
            const upload = [];
            for (const type of ["loadstart", "progress", "load", "loadend"]) {
                xhr.upload.addEventListener(type, (event) => {
                    const progress = event;
                    upload.push([type, progress.loaded, progress.total]);
                });
            }
            const onUploadProgress = vi.fn();
            xhr.upload.onprogress = onUploadProgress;
            const done = new Promise((resolve) => (xhr.onloadend = () => resolve()));
            xhr.open("POST", "/api/upload");
            xhr.send(new Blob(["12345"]));
            await done;
            expect(upload).toEqual([
                ["loadstart", 0, 5],
                ["progress", 5, 5],
                ["load", 5, 5],
                ["loadend", 5, 5],
            ]);
            expect(onUploadProgress).toHaveBeenCalledOnce();
        });
        it("fails like a network error when the handler throws: status 0, no headers, no body", async () => {
            install(() => {
                throw new Error("refused with secret detail");
            });
            const onerror = vi.fn();
            const xhr = new XMLHttpRequest();
            xhr.onerror = onerror;
            const done = new Promise((resolve) => xhr.addEventListener("loadend", () => resolve()));
            xhr.open("GET", "/api/a");
            xhr.send();
            await done;
            expect(onerror).toHaveBeenCalledOnce();
            expect(xhr.status).toBe(0);
            expect(xhr.readyState).toBe(4);
            expect(xhr.responseText).toBe("");
            expect(xhr.getAllResponseHeaders()).toBe("");
        });
        it("aborts a running request: abort and loadend, the handler's signal aborted, no load", async () => {
            let signal;
            install((request) => {
                signal = request.signal;
                return new Promise(() => { });
            });
            const onabort = vi.fn();
            const events = [];
            const xhr = new XMLHttpRequest();
            xhr.onabort = onabort;
            for (const type of ["load", "error", "abort", "loadend"])
                xhr.addEventListener(type, () => events.push(type));
            xhr.open("GET", "/api/slow");
            xhr.send();
            await Promise.resolve();
            await Promise.resolve();
            xhr.abort();
            expect(onabort).toHaveBeenCalledOnce();
            expect(events).toEqual(["abort", "loadend"]);
            expect(signal?.aborted).toBe(true);
            expect(xhr.status).toBe(0);
            expect(xhr.readyState).toBe(XMLHttpRequest.UNSENT);
        });
        it("keeps a request whose handler never settles pending", async () => {
            install(() => new Promise(() => { }));
            const xhr = new XMLHttpRequest();
            const onloadend = vi.fn();
            xhr.onloadend = onloadend;
            xhr.open("GET", "/api/pending");
            xhr.send();
            await new Promise((resolve) => setTimeout(resolve, 10));
            expect(onloadend).not.toHaveBeenCalled();
            expect(xhr.readyState).toBe(XMLHttpRequest.OPENED);
        });
        it("refuses setRequestHeader and send outside an opened request", () => {
            install(() => jsonAnswer({}));
            const xhr = new XMLHttpRequest();
            expect(() => xhr.setRequestHeader("a", "b")).toThrow();
            expect(() => xhr.send()).toThrow();
        });
        it("forwards with the credentials mode of withCredentials", async () => {
            const original = vi.fn(async () => jsonAnswer({ forwarded: true }));
            window.fetch = original;
            install((request) => request.forward());
            const xhr = new XMLHttpRequest();
            const done = new Promise((resolve) => (xhr.onloadend = () => resolve()));
            xhr.open("GET", "https://example.test/asset.png");
            xhr.withCredentials = false;
            xhr.send();
            await done;
            expect(xhr.responseText).toBe('{"forwarded":true}');
            const [url, init] = original.mock.calls[0];
            expect(url).toBe("https://example.test/asset.png");
            expect(init.credentials).toBe("same-origin");
        });
    });
});
describe("toSameRealmFetchInput", () => {
    it("rebuilds a Request of another realm as one of this realm and leaves other inputs as they are", async () => {
        const source = new Request("http://localhost:3000/api/x?a=1", { method: "POST", body: "data", headers: { "X-A": "1" } });
        const otherRealm = Object.create({
            get [Symbol.toStringTag]() {
                return "Request";
            },
        }, {
            url: { value: source.url },
            method: { value: source.method },
            headers: { value: source.headers },
            body: { value: source.body },
            signal: { value: source.signal },
            clone: { value: () => source.clone() },
        });
        const rebuilt = (await toSameRealmFetchInput(otherRealm));
        expect(rebuilt).toBeInstanceOf(Request);
        expect([rebuilt.url, rebuilt.method, rebuilt.headers.get("x-a"), await rebuilt.text()]).toEqual([
            "http://localhost:3000/api/x?a=1",
            "POST",
            "1",
            "data",
        ]);
        const url = new URL("http://localhost:3000/b");
        expect(await toSameRealmFetchInput(url)).toBe(url);
        expect(await toSameRealmFetchInput("/c")).toBe("/c");
        const sameRealm = new Request("http://localhost:3000/d");
        expect(await toSameRealmFetchInput(sameRealm)).toBe(sameRealm);
    });
});
