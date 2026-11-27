/**
 * Whitelist helpers for LLM-emitted widget payload values. Filter values
 * flow into backend filter strings (`status eq '<value>'`, `directories in
 * ['<value>']`) and Redux filter slices, so nothing may pass through without
 * either matching a known literal or a safe-charset alias check — a stray
 * quote in a value would otherwise break or co-opt the filter expression.
 */
const SAFE_ALIAS = /^[a-z0-9_-]+$/i;
/** Keep only values present in `allowed`; undefined when nothing survives. */
export const pickAllowed = (values, allowed) => {
    if (!Array.isArray(values))
        return undefined;
    const filtered = values.filter((v) => typeof v === "string" && allowed.includes(v));
    return filtered.length ? filtered : undefined;
};
/** Single-value variant of `pickAllowed`. */
export const pickAllowedValue = (value, allowed) => typeof value === "string" && allowed.includes(value) ? value : undefined;
/**
 * Keep only strings matching the safe alias charset (letters, digits, `_`, `-`).
 * For open-ended vocabularies (e.g. aliases of data sources an app adds at runtime) where a fixed
 * whitelist is impossible but quotes/spaces must never pass.
 */
export const pickSafeAliases = (values) => {
    if (!Array.isArray(values))
        return undefined;
    const filtered = values.filter((v) => typeof v === "string" && SAFE_ALIAS.test(v));
    return filtered.length ? filtered : undefined;
};
/** Single-value variant of `pickSafeAliases` (e.g. a source alias or an id token of the app's own form). */
export const pickSafeAlias = (value) => typeof value === "string" && SAFE_ALIAS.test(value) ? value : undefined;
/**
 * An opaque id token a widget uses ONLY as a URL path segment of a read endpoint (`/api/example/{sourceAlias}/{id}`)
 * — never injected into an OData filter string. Ids of external sources are often base64 (`+`, `/`, `=`), so the
 * `SAFE_ALIAS` charset is too strict and would silently drop them (the widget then falls back to a filtered read).
 * Allows the base64 charset; still rejects quotes, spaces and anything else that could break the path.
 */
const OPAQUE_PATH_SEGMENT_ID_TOKEN = /^[A-Za-z0-9_\-+/=]+$/;
export const pickReviewExternalId = (value) => typeof value === "string" && value.length > 0 && OPAQUE_PATH_SEGMENT_ID_TOKEN.test(value) ? value : undefined;
/** Keep a free-text string, capped at `maxLength` — e.g. a model-drafted reply pre-filled into an input. */
export const pickText = (value, maxLength = 2000) => typeof value === "string" && value.length > 0 ? value.slice(0, maxLength) : undefined;
