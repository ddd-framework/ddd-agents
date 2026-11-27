import { useEffect, useState } from "react";
/** The error of a picture read while no source is registered: the tile says the files are not connected. */
export const FILE_PREVIEW_SOURCE_MISSING = "FILE_PREVIEW_SOURCE_MISSING";
let registeredFilePreviewSource = null;
/** Registers the source of the pictures. Call it once, outside React, before the first chat; a second call replaces the first. */
export const registerFilePreviewSource = (source) => {
    registeredFilePreviewSource = source;
};
/** The registered source, or `null` while none is connected. */
export const readFilePreviewSource = () => registeredFilePreviewSource;
/** Takes the source off again (tests, a host that disconnects). */
export const unregisterFilePreviewSource = () => {
    registeredFilePreviewSource = null;
};
/** The picture of one file reference in one size, read through the registered source; `null` reference reads nothing. */
export const useFilePictureUrl = (reference, variant) => {
    const [state, setState] = useState({ url: null, status: "loading" });
    useEffect(() => {
        if (reference === null)
            return undefined;
        const source = readFilePreviewSource();
        if (source === null) {
            setState({ url: null, status: "notConnected" });
            return undefined;
        }
        const abortController = new AbortController();
        setState({ url: null, status: "loading" });
        source
            .readPictureUrl(reference, variant, abortController.signal)
            .then((url) => {
            if (!abortController.signal.aborted)
                setState({ url, status: "loaded" });
        })
            .catch(() => {
            if (!abortController.signal.aborted)
                setState({ url: null, status: "failed" });
        });
        return () => abortController.abort();
    }, [reference, variant]);
    return reference === null ? { url: null, status: "failed" } : state;
};
