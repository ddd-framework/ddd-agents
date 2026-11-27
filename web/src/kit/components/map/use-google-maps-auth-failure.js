import { useSyncExternalStore } from "react";
let hasGoogleMapsAuthFailed = false;
const authFailureListeners = new Set();
let isHandlerInstalled = false;
const markGoogleMapsAuthFailed = () => {
    hasGoogleMapsAuthFailed = true;
    authFailureListeners.forEach((listener) => listener());
};
const installAuthFailureHandler = () => {
    if (isHandlerInstalled || typeof window === "undefined")
        return;
    isHandlerInstalled = true;
    const previousHandler = window.gm_authFailure;
    window.gm_authFailure = () => {
        markGoogleMapsAuthFailed();
        previousHandler?.();
    };
};
const subscribeToAuthFailure = (listener) => {
    installAuthFailureHandler();
    authFailureListeners.add(listener);
    return () => {
        authFailureListeners.delete(listener);
    };
};
const readAuthFailure = () => hasGoogleMapsAuthFailed;
const readAuthFailureOnServer = () => false;
export const useGoogleMapsAuthFailure = () => useSyncExternalStore(subscribeToAuthFailure, readAuthFailure, readAuthFailureOnServer);
/** For specs: the flag back to its initial state. */
export const resetGoogleMapsAuthFailureForTests = () => {
    hasGoogleMapsAuthFailed = false;
};
/** For specs and stories: what the SDK's callback does. */
export const simulateGoogleMapsAuthFailure = () => markGoogleMapsAuthFailed();
