import { createProgressEvent, InterceptedXMLHttpRequestUpload, } from "./intercepted-xml-http-request-upload";
/**
 * An answer without any header, as the former axios adapters answered (`headers: {}`): a string body would add a
 * `Content-Type`, the bytes of it do not.
 */
export const createHeaderlessResponse = (body, status, statusText = "") => new Response(body === null ? null : new TextEncoder().encode(body), { status, statusText });
/** A `Request` of any realm (an iframe's, jsdom's, Node's) — never `instanceof`, which holds only for this realm. */
export const isFetchRequest = (input) => Object.prototype.toString.call(input) === "[object Request]";
const pageOrigin = () => typeof window !== "undefined" && window.location ? window.location.origin : "http://localhost";
const copyHeaders = (target, source) => {
    if (source === undefined)
        return;
    new Headers(source).forEach((value, name) => target.set(name, value));
};
/** The `fetch` arguments as one request: a `Request` of any realm, a `URL` or a string, with `init` taking precedence. */
export const readFetchRequest = async (input, init, originalFetch) => {
    const fromRequest = isFetchRequest(input) ? input : undefined;
    const headers = new Headers();
    if (fromRequest)
        fromRequest.headers.forEach((value, name) => headers.set(name, value));
    copyHeaders(headers, init?.headers);
    const initBody = init?.body ?? null;
    const body = initBody !== null
        ? initBody
        : fromRequest && fromRequest.body !== null
            ? await fromRequest.clone().arrayBuffer()
            : null;
    return {
        method: (init?.method ?? fromRequest?.method ?? "GET").toUpperCase(),
        url: new URL(fromRequest ? fromRequest.url : String(input), pageOrigin()),
        headers,
        body,
        signal: init?.signal ?? fromRequest?.signal ?? undefined,
        transport: "fetch",
        forward: () => originalFetch(input, init),
    };
};
const bodySize = (body) => {
    if (body === null)
        return 0;
    if (typeof body === "string")
        return new TextEncoder().encode(body).byteLength;
    if (typeof Blob !== "undefined" && body instanceof Blob)
        return body.size;
    if (body instanceof ArrayBuffer)
        return body.byteLength;
    if (ArrayBuffer.isView(body))
        return body.byteLength;
    return 0;
};
const invalidState = (message) => new DOMException(message, "InvalidStateError");
/** Builds the `XMLHttpRequest` class an install puts on the page: every request goes to `handle`. */
const createInterceptedXMLHttpRequest = (handle, originalFetch) => class InterceptedXMLHttpRequest extends EventTarget {
    static UNSENT = 0;
    static OPENED = 1;
    static HEADERS_RECEIVED = 2;
    static LOADING = 3;
    static DONE = 4;
    UNSENT = 0;
    OPENED = 1;
    HEADERS_RECEIVED = 2;
    LOADING = 3;
    DONE = 4;
    readyState = 0;
    status = 0;
    statusText = "";
    responseURL = "";
    responseType = "";
    timeout = 0;
    withCredentials = false;
    upload = new InterceptedXMLHttpRequestUpload();
    responseXML = null;
    onreadystatechange = null;
    onloadstart = null;
    onprogress = null;
    onload = null;
    onerror = null;
    onabort = null;
    ontimeout = null;
    onloadend = null;
    method = "GET";
    requestUrl = "";
    requestHeaders = new Headers();
    responseHeaders = new Headers();
    responseValue = "";
    responseTextValue = "";
    sent = false;
    settled = false;
    controller;
    timer;
    get response() {
        if (this.responseType === "" || this.responseType === "text")
            return this.responseText;
        return this.readyState === 4 ? this.responseValue : null;
    }
    get responseText() {
        if (this.responseType !== "" && this.responseType !== "text") {
            throw invalidState(`responseText is not available for responseType "${this.responseType}"`);
        }
        return this.readyState >= 3 ? this.responseTextValue : "";
    }
    open(method, url) {
        // A running request is dropped without events, as a browser does.
        if (this.sent && !this.settled)
            this.settle();
        this.controller?.abort();
        this.controller = undefined;
        this.method = method.toUpperCase();
        this.requestUrl = String(url);
        this.requestHeaders = new Headers();
        this.responseHeaders = new Headers();
        this.responseValue = "";
        this.responseTextValue = "";
        this.status = 0;
        this.statusText = "";
        this.responseURL = "";
        this.sent = false;
        this.settled = false;
        this.setReadyState(1);
    }
    setRequestHeader(name, value) {
        if (this.readyState !== 1 || this.sent)
            throw invalidState("setRequestHeader needs an opened, unsent request");
        this.requestHeaders.append(name, value);
    }
    overrideMimeType() { }
    getAllResponseHeaders() {
        if (this.readyState < 2)
            return "";
        let lines = "";
        this.responseHeaders.forEach((value, name) => {
            lines += `${name}: ${value}\r\n`;
        });
        return lines;
    }
    getResponseHeader(name) {
        return this.readyState < 2 ? null : this.responseHeaders.get(name);
    }
    send(body) {
        if (this.readyState !== 1 || this.sent)
            throw invalidState("send needs an opened, unsent request");
        this.sent = true;
        const controller = new AbortController();
        this.controller = controller;
        const passedBody = body === undefined || body === null ? null : body;
        // A browser sends no body with a GET or HEAD; the handler still sees what the client passed.
        const requestBody = this.method === "GET" || this.method === "HEAD" ? null : passedBody;
        const headers = new Headers(this.requestHeaders);
        const url = new URL(this.requestUrl, pageOrigin());
        const credentials = this.withCredentials ? "include" : "same-origin";
        const method = this.method;
        const request = {
            method,
            url,
            headers,
            body: passedBody,
            signal: controller.signal,
            transport: "xhr",
            forward: () => originalFetch(url.href, {
                method,
                headers: new Headers(headers),
                body: requestBody,
                credentials,
                signal: controller.signal,
            }),
        };
        const responseUrl = url.href;
        if (this.timeout > 0)
            this.timer = setTimeout(() => this.finishWithoutResponse("timeout"), this.timeout);
        this.emit("loadstart");
        const size = bodySize(requestBody);
        if (requestBody !== null) {
            this.upload.emit("loadstart", 0, size);
            this.upload.emit("progress", size, size);
            this.upload.emit("load", size, size);
            this.upload.emit("loadend", size, size);
        }
        Promise.resolve()
            .then(() => handle(request))
            .then((response) => this.receive(controller, response, responseUrl), () => {
            if (this.controller === controller)
                this.finishWithoutResponse("error");
        });
    }
    abort() {
        if (!this.sent || this.settled)
            return;
        this.finishWithoutResponse("abort");
        this.readyState = 0;
    }
    async receive(controller, response, responseUrl) {
        if (this.controller !== controller || this.settled)
            return;
        try {
            this.status = response.status;
            this.statusText = response.statusText;
            this.responseURL = responseUrl;
            this.responseHeaders = new Headers(response.headers);
            this.setReadyState(2);
            const value = await this.readBody(response);
            if (this.controller !== controller || this.settled)
                return;
            this.responseValue = value;
            this.setReadyState(3);
            const size = typeof this.responseTextValue === "string" ? this.responseTextValue.length : 0;
            this.emit("progress", size, size);
            this.settle();
            this.setReadyState(4);
            this.emit("load", size, size);
            this.emit("loadend", size, size);
        }
        catch {
            if (this.controller === controller && !this.settled)
                this.finishWithoutResponse("error");
        }
    }
    async readBody(response) {
        switch (this.responseType) {
            case "json": {
                const text = await response.text();
                this.responseTextValue = text;
                try {
                    return text === "" ? null : JSON.parse(text);
                }
                catch {
                    return null;
                }
            }
            case "blob":
                return response.blob();
            case "arraybuffer":
                return response.arrayBuffer();
            case "document":
                return null;
            default: {
                const text = await response.text();
                this.responseTextValue = text;
                return text;
            }
        }
    }
    /** A request that ends without a response — an error, an abort or a timeout: status 0, nothing of a body. */
    finishWithoutResponse(type) {
        if (this.settled)
            return;
        this.settle();
        this.controller?.abort();
        this.status = 0;
        this.statusText = "";
        this.responseHeaders = new Headers();
        this.responseValue = null;
        this.responseTextValue = "";
        this.setReadyState(4);
        this.emit(type);
        this.emit("loadend");
    }
    settle() {
        this.settled = true;
        if (this.timer !== undefined)
            clearTimeout(this.timer);
        this.timer = undefined;
    }
    setReadyState(state) {
        this.readyState = state;
        this.emit("readystatechange");
    }
    emit(type, loaded = 0, total = 0) {
        const event = type === "readystatechange" ? new Event(type) : createProgressEvent(type, loaded, total);
        this.dispatchEvent(event);
        this[`on${type}`]?.call(this, event);
    }
};
/** The scopes that carry the page's `fetch` and `XMLHttpRequest` (the window and the global object, once each). */
const globalScopes = () => {
    const scopes = [globalThis];
    if (typeof window !== "undefined" && window !== globalThis)
        scopes.push(window);
    return scopes;
};
/** The page's `fetch` as it is now (the window's), callable after an install replaced it. */
const capturePageFetch = () => {
    const pageFetch = (typeof window !== "undefined" ? window.fetch : globalThis.fetch);
    return (input, init) => pageFetch.call(globalThis, input, init);
};
/** Puts `value` on every global scope under `name` until the returned function restores what was there. */
const replaceGlobal = (name, value) => {
    const saved = globalScopes().map((scope) => ({ scope, value: scope[name] }));
    for (const { scope } of saved)
        scope[name] = value;
    return () => {
        for (const entry of saved)
            entry.scope[name] = entry.value;
    };
};
/**
 * Replaces `XMLHttpRequest` on the page until the returned function is called; `fetch` stays as it is. A handler's
 * `forward()` goes through the page's `fetch` as it was at the install.
 */
export const interceptXMLHttpRequests = (handle) => replaceGlobal("XMLHttpRequest", createInterceptedXMLHttpRequest(handle, capturePageFetch()));
/** Replaces `fetch` on the page until the returned function is called; `XMLHttpRequest` stays as it is. */
export const interceptFetchRequests = (handle) => {
    const originalFetch = capturePageFetch();
    const interceptedFetch = async (input, init) => handle(await readFetchRequest(input, init, originalFetch));
    return replaceGlobal("fetch", interceptedFetch);
};
/**
 * A `fetch` input this realm's `Request` constructor reads correctly: a `Request` of another realm becomes one of this
 * realm with the same URL, method, headers, body and signal; anything else is returned as it is.
 */
export const toSameRealmFetchInput = async (input) => {
    if (!isFetchRequest(input) || input instanceof Request)
        return input;
    const foreign = input;
    const body = foreign.body !== null ? await foreign.clone().arrayBuffer() : null;
    return new Request(foreign.url, {
        method: foreign.method,
        headers: new Headers(foreign.headers),
        body,
        // A signal of another realm is not accepted by this realm's constructor.
        signal: foreign.signal instanceof AbortSignal ? foreign.signal : undefined,
    });
};
