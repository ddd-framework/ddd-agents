import { useSyncExternalStore } from "react";

/**
 * Whether Google refused the Maps key for this page. The SDK reports a refused key — a referrer the
 * key does not allow, a disabled API — only through the global `window.gm_authFailure` callback; the script itself
 * loads fine, so `useGoogleMapApiLoader` sees no error. After a refusal every new map fails inside the SDK, so the
 * `Map` node stops creating Google maps, shows its points without the map and reports `failed`.
 *
 * One page-wide flag: the handler is installed once and keeps any handler that was there before.
 */

declare global {
  interface Window {
    gm_authFailure?: () => void;
  }
}

let hasGoogleMapsAuthFailed = false;
const authFailureListeners = new Set<() => void>();
let isHandlerInstalled = false;

const markGoogleMapsAuthFailed = () => {
  hasGoogleMapsAuthFailed = true;
  authFailureListeners.forEach((listener) => listener());
};

const installAuthFailureHandler = () => {
  if (isHandlerInstalled || typeof window === "undefined") return;
  isHandlerInstalled = true;
  const previousHandler = window.gm_authFailure;
  window.gm_authFailure = () => {
    markGoogleMapsAuthFailed();
    previousHandler?.();
  };
};

const subscribeToAuthFailure = (listener: () => void) => {
  installAuthFailureHandler();
  authFailureListeners.add(listener);
  return () => {
    authFailureListeners.delete(listener);
  };
};

const readAuthFailure = () => hasGoogleMapsAuthFailed;
const readAuthFailureOnServer = () => false;

export const useGoogleMapsAuthFailure = (): boolean =>
  useSyncExternalStore(subscribeToAuthFailure, readAuthFailure, readAuthFailureOnServer);

/** For specs: the flag back to its initial state. */
export const resetGoogleMapsAuthFailureForTests = () => {
  hasGoogleMapsAuthFailed = false;
};

/** For specs and stories: what the SDK's callback does. */
export const simulateGoogleMapsAuthFailure = () => markGoogleMapsAuthFailed();
