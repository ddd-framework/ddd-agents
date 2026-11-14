import axios, { AxiosRequestConfig } from "axios";
import type { WidgetImageRenderBootstrap } from "./widget-image-render.types";
import type { createWidgetImageReadiness } from "./widget-image-readiness";
import { isGoogleMapCapture } from "./widget-image-google-map";
import { getWidgetImageCaptureReadPathPrefix } from "../provider/environment";

const STATIC_ASSET_PATH =
  /^\/react\/(?:app-build|widget-image-build)\/assets\/(?:[\w-]+\/)*[\w.-]+\.(?:svg|png|jpe?g|webp|gif)$/;

/** Loaded only by the capture entry. SDK signatures stay unchanged; no ambient credentials are sent. */
export const installLiveWidgetRequests = (
  bootstrap: WidgetImageRenderBootstrap,
  readiness: ReturnType<typeof createWidgetImageReadiness>,
) => {
  const originalAdapter = axios.defaults.adapter;
  const originalFetch = window.fetch;
  const fetchRead = originalFetch.bind(window);
  // Captured widgets may import shared telemetry/helpers. They cannot bypass the scoped adapter. Two reads pass:
  // static bundle images (useDynamicImport fetches icons such as review-source logos) and, for map captures only,
  // the Google Maps SDK (a map widget, or a ComposedView tree holding a map).
  window.fetch = async (input, init) => {
    const request = new Request(typeof input === "string" ? new URL(input, window.location.origin).href : input, init);
    const url = new URL(request.url, window.location.origin);
    const unavailable = readiness.isFailed() || Date.now() >= bootstrap.expiresAt * 1000;
    const isStaticAsset =
      url.origin === window.location.origin &&
      STATIC_ASSET_PATH.test(url.pathname) &&
      url.search === "" &&
      request.method === "GET";
    if (isStaticAsset && !unavailable) {
      const finish = readiness.beginRequest();
      try {
        return await fetchRead(url.toString(), {
          credentials: "omit",
          redirect: "error",
          referrerPolicy: "no-referrer",
        });
      } finally {
        finish();
      }
    }
    const isMap = isGoogleMapCapture(bootstrap.input.widgetName, bootstrap.input.props);
    const isGoogleMapRequest =
      url.protocol === "https:" &&
      ((url.hostname === "maps.googleapis.com" &&
        (url.pathname.startsWith("/maps/") || url.pathname.startsWith("/$rpc/"))) ||
        (url.hostname === "maps.gstatic.com" && url.pathname.startsWith("/mapfiles/")));
    if (!isMap || !isGoogleMapRequest || !["GET", "POST"].includes(request.method) || unavailable) {
      readiness.fail("UNSCOPED_REQUEST");
      throw new Error("Unscoped render request");
    }
    const headers = new Headers();
    for (const name of ["content-type", "x-goog-api-key", "x-goog-api-client", "x-user-agent"]) {
      const value = request.headers.get(name);
      if (value) headers.set(name, value);
    }
    const finish = readiness.beginRequest();
    try {
      const response = await fetchRead(request, {
        credentials: "omit",
        referrerPolicy: "no-referrer",
        redirect: "error",
        headers,
      });
      if (!response.ok) throw new Error("Map request failed");
      return response;
    } catch {
      readiness.fail("MAP_ASSET_FAILED");
      throw new Error("Map request failed");
    } finally {
      finish();
    }
  };
  axios.defaults.adapter = async (config: AxiosRequestConfig) => {
    const finish = readiness.beginRequest();
    try {
      const url = new URL(axios.getUri(config), window.location.origin);
      if (
        !bootstrap.authToken ||
        url.origin !== window.location.origin ||
        !url.pathname.startsWith(getWidgetImageCaptureReadPathPrefix()) ||
        (config.method ?? "get").toLowerCase() !== "get" ||
        config.data !== undefined ||
        readiness.isFailed() ||
        Date.now() >= bootstrap.expiresAt * 1000
      )
        throw new Error("RENDER_READ_DENIED");
      // The ordinary helper propagates debug/noCache from the page; capture requests never do.
      url.searchParams.delete("debug");
      url.searchParams.delete("noCache");
      const response = await fetchRead(url.toString(), {
        method: "GET",
        credentials: "omit",
        cache: "no-store",
        redirect: "error",
        referrerPolicy: "no-referrer",
        signal: config.signal as AbortSignal | undefined,
        headers: {
          Authorization: `Bearer ${bootstrap.authToken}`,
          jwtSource: "internal_requests",
          Accept: "application/json",
        },
      });
      if (!response.ok) throw new Error("RENDER_READ_FAILED");
      const data = await response.json();
      return { data, status: response.status, statusText: "OK", config: {}, headers: {} };
    } catch {
      readiness.fail("WIDGET_HYDRATION_FAILED");
      // HttpStore logs errors and submits telemetry for status 500. Never expose the URL,
      // request headers, auth token or remote exception body to those shared interceptors.
      throw { message: "Widget hydration failed", response: { status: 0, data: { error: "Widget hydration failed" } } };
    } finally {
      finish();
    }
  };
  return () => {
    axios.defaults.adapter = originalAdapter;
    window.fetch = originalFetch;
  };
};
