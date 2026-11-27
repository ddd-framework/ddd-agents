const STATIC_ASSET_PATH = /^\/react\/(?:app-build|widget-image-build)\/assets\/(?:[\w-]+\/)*[\w.-]+\.(?:svg|png|jpe?g|webp|gif)$/;
/** A static bundle image of the page's origin (icons such as review-source logos that `useDynamicImport` fetches). */
export const isStaticBundleImageRequest = (url, method) => url.origin === window.location.origin && STATIC_ASSET_PATH.test(url.pathname) && url.search === "" && method === "GET";
