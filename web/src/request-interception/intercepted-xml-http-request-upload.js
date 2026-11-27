/** The upload side of the `XMLHttpRequest` stand-in (`xhr.upload`) and the events both sides dispatch. */
export const createProgressEvent = (type, loaded, total) => typeof ProgressEvent === "function"
    ? new ProgressEvent(type, { lengthComputable: total > 0, loaded, total })
    : new Event(type);
/** The event target of `xhr.upload`: listeners and `on…` handlers for the upload's progress. */
export class InterceptedXMLHttpRequestUpload extends EventTarget {
    onloadstart = null;
    onprogress = null;
    onload = null;
    onerror = null;
    onabort = null;
    ontimeout = null;
    onloadend = null;
    emit(type, loaded = 0, total = 0) {
        const event = createProgressEvent(type, loaded, total);
        this.dispatchEvent(event);
        this[`on${type}`]?.call(this, event);
    }
}
