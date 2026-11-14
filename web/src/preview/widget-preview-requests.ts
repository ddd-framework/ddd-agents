import axios, { AxiosRequestConfig } from "axios";
import type { WidgetPreviewResponse } from "./widget-preview.types";

/** The key a fixture response is stored under: method, path and the sorted query (`GET /api/example/…?a=1&b=2`). */
export const widgetPreviewRequestKey = (method: string, url: URL): string => {
  const sortedUrl = new URL(url.toString());
  sortedUrl.searchParams.sort();
  return `${method.toUpperCase()} ${sortedUrl.pathname}${sortedUrl.search}`;
};

/** The app's own error log: answered empty and never reported — a fixture's error state must not count as a gap. */
const SILENTLY_ANSWERED_PATHS = ["/rest_api/v1/log"];
const isSilentlyAnswered = (url: URL): boolean => SILENTLY_ANSWERED_PATHS.includes(url.pathname);

export const isStatusResponse = (response: unknown): response is { __status: number; __body?: unknown } =>
  typeof response === "object" &&
  response !== null &&
  typeof (response as { __status?: unknown }).__status === "number";
export const isPendingResponse = (response: unknown): boolean =>
  typeof response === "object" && response !== null && (response as { __pending?: unknown }).__pending === true;

/**
 * Serves every request of the preview — axios and `fetch` — from the fixture: a known request gets its body (a copy), a
 * `__status` entry fails with that status (a status of 400 or more is reported as a failed request — the error state the
 * fixture asked for), a `__pending` entry never answers (the loading state), and anything else — an unknown read, a
 * write the fixture does not answer — fails as a missing response and is reported. A write is answered only when the
 * fixture names it (`"POST /api/example/…"`, e.g. a widget's fire-and-forget measurement event). Nothing reaches a server.
 */
export const installWidgetPreviewRequests = (
  responses: Record<string, WidgetPreviewResponse>,
  onMissingResponse: (requestKey: string) => void,
  onFailedRequest: (requestKey: string) => void = () => {},
): (() => void) => {
  const originalAdapter = axios.defaults.adapter;
  axios.defaults.adapter = async (config: AxiosRequestConfig) => {
    const method = (config.method ?? "get").toLowerCase();
    const requestUrl = new URL(axios.getUri(config), window.location.origin);
    if (isSilentlyAnswered(requestUrl)) return { data: {}, status: 200, statusText: "OK", config, headers: {} };
    const requestKey = widgetPreviewRequestKey(method, requestUrl);
    const response = Object.prototype.hasOwnProperty.call(responses, requestKey) ? responses[requestKey] : undefined;
    if (response === undefined) {
      onMissingResponse(requestKey);
      return Promise.reject(Object.assign(new Error(`no preview response for ${requestKey}`), { config }));
    }
    if (isPendingResponse(response)) return new Promise(() => {});
    if (isStatusResponse(response)) {
      if (response.__status >= 400) onFailedRequest(requestKey);
      return Promise.reject(
        Object.assign(new Error(`preview response ${response.__status}`), {
          config,
          response: { status: response.__status, data: response.__body ?? null, config, headers: {} },
        }),
      );
    }
    return { data: structuredClone(response), status: 200, statusText: "OK", config, headers: {} };
  };
  // `fetch` is answered the same way (a few modules bypass axios); a missing response is a 404, reported.
  const originalFetch = window.fetch;
  window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    const method = (init?.method ?? (input instanceof Request ? input.method : "GET")).toLowerCase();
    const url = new URL(input instanceof Request ? input.url : String(input), window.location.origin);
    if (isSilentlyAnswered(url)) return new Response("{}", { status: 200 });
    const requestKey = widgetPreviewRequestKey(method, url);
    const response = Object.prototype.hasOwnProperty.call(responses, requestKey) ? responses[requestKey] : undefined;
    if (response === undefined) {
      onMissingResponse(requestKey);
      return new Response(null, { status: 404 });
    }
    if (isPendingResponse(response)) return new Promise<Response>(() => {});
    if (isStatusResponse(response)) {
      if (response.__status >= 400) onFailedRequest(requestKey);
      return new Response(JSON.stringify(response.__body ?? null), { status: response.__status });
    }
    return new Response(JSON.stringify(response), { status: 200, headers: { "Content-Type": "application/json" } });
  };
  return () => {
    axios.defaults.adapter = originalAdapter;
    window.fetch = originalFetch;
  };
};
