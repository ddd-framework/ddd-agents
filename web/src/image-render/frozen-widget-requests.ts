import axios, { AxiosRequestConfig } from "axios";

/** The capture page serves supplied GET results in memory. It never lends the render token to the SDK. */
export const installFrozenWidgetRequests = (responses: Record<string, unknown>, fail: (code: string) => void) => {
  const originalAdapter = axios.defaults.adapter;
  axios.defaults.adapter = async (config: AxiosRequestConfig) => {
    const url = new URL(axios.getUri(config), window.location.origin);
    url.searchParams.sort();
    const key = `${(config.method ?? "get").toUpperCase()} ${url.pathname}${url.search}`;
    if ((config.method ?? "get").toLowerCase() !== "get" || !Object.prototype.hasOwnProperty.call(responses, key)) {
      fail("MISSING_FROZEN_RESPONSE");
      throw new Error("MISSING_FROZEN_RESPONSE");
    }
    return { data: structuredClone(responses[key]), status: 200, statusText: "OK", config, headers: {} };
  };
  return () => {
    axios.defaults.adapter = originalAdapter;
  };
};
