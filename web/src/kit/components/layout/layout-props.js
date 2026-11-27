const LAYOUT_GAPS = ["none", "xsmall", "small", "medium", "large"];
/** The value when it is one of `allowedValues`, else `fallback`. */
export const readEnumProp = (value, allowedValues, fallback) => (allowedValues.includes(value) ? value : fallback);
export const readGapProp = (value, fallback = "medium") => readEnumProp(value, LAYOUT_GAPS, fallback);
/** A non-empty text (a number is shown as written), else `undefined`. */
export const readTextProp = (value) => {
    if (typeof value === "string")
        return value.trim() === "" ? undefined : value;
    if (typeof value === "number" && Number.isFinite(value))
        return String(value);
    return undefined;
};
export const readBooleanProp = (value, fallback) => typeof value === "boolean" ? value : fallback;
/** A `{"name","url"}` source; the url only when it is http(s). */
export const readSourceLinkProp = (value) => {
    if (typeof value !== "object" || value === null || Array.isArray(value))
        return undefined;
    const sourceObject = value;
    const name = readTextProp(sourceObject.name);
    const rawUrl = readTextProp(sourceObject.url);
    const url = rawUrl !== undefined && /^https?:\/\//i.test(rawUrl) ? rawUrl : undefined;
    if (name === undefined && url === undefined)
        return undefined;
    return { name, url };
};
