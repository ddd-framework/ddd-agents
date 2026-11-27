/**
 * Bounded walks over decoded JSON: the widget payloads come from the model, so no
 * walk may recurse without a bound, run in the size of a shared sub-structure, or throw. The depth rule is the
 * backend's: a block is decoded with `json_decode(…, depth: 32)` (`WidgetBlocks.php`), which admits 31 nested
 * containers — a deeper block is MALFORMED there and is dropped here the same way.
 */
/** The deepest container nesting `json_decode(…, 32)` admits (the top-level object counts as 1). */
export const MAX_JSON_CONTAINER_DEPTH = 31;
/** Whether `value` nests containers deeper than `maxDepth` — iterative, never throws. */
export const exceedsJsonContainerDepth = (value, maxDepth = MAX_JSON_CONTAINER_DEPTH) => {
    const pending = [[value, 1]];
    while (pending.length > 0) {
        const [current, depth] = pending.pop();
        if (typeof current !== "object" || current === null)
            continue;
        if (depth > maxDepth)
            return true;
        for (const nested of Object.values(current)) {
            if (typeof nested === "object" && nested !== null)
                pending.push([nested, depth + 1]);
        }
    }
    return false;
};
const isSequentialIndexObject = (value) => {
    const keys = Object.keys(value);
    return keys.length > 0 && keys.every((key, position) => key === String(position));
};
/**
 * The value as `json_decode(…, true)` sees it: an object whose keys are `"0"`, `"1"`, … in order is a LIST there
 * (`array_is_list`), so it becomes an array here. Bounded by the depth rule (call it on a value within the depth).
 */
export const normalizeToPhpArrays = (value, depth = 1) => {
    if (typeof value !== "object" || value === null || depth > MAX_JSON_CONTAINER_DEPTH)
        return value;
    if (Array.isArray(value))
        return value.map((element) => normalizeToPhpArrays(element, depth + 1));
    const objectValue = value;
    if (isSequentialIndexObject(objectValue)) {
        return Object.values(objectValue).map((element) => normalizeToPhpArrays(element, depth + 1));
    }
    const normalized = {};
    for (const [key, nested] of Object.entries(objectValue)) {
        // defineProperty, not assignment: a `__proto__` key stays an own data property instead of replacing the prototype.
        Object.defineProperty(normalized, key, {
            value: normalizeToPhpArrays(nested, depth + 1),
            enumerable: true,
            writable: true,
            configurable: true,
        });
    }
    return normalized;
};
const structuralSizes = new WeakMap();
/**
 * The size of a value as a TREE (every container and scalar counted where it appears) — a shared sub-structure counts
 * once per use, as serialising it would. Memoised per object, so the cost is the number of DISTINCT objects even for a
 * DAG whose tree is exponential; the result saturates at `Number.MAX_SAFE_INTEGER`.
 */
export const structuralSizeOf = (value, depth = 1) => {
    if (typeof value !== "object" || value === null)
        return 1;
    const memoised = structuralSizes.get(value);
    if (memoised !== undefined)
        return memoised;
    if (depth > 256)
        return Number.MAX_SAFE_INTEGER;
    let size = 1;
    for (const nested of Object.values(value)) {
        size = Math.min(Number.MAX_SAFE_INTEGER, size + structuralSizeOf(nested, depth + 1));
    }
    structuralSizes.set(value, size);
    return size;
};
/** `JSON.stringify(value, null, 2)` — or undefined once the text would pass `maxLength` (checked before it is built). */
export const stringifyWithinLimit = (value, maxLength) => {
    if (structuralSizeOf(value) > maxLength)
        return undefined;
    const text = JSON.stringify(value ?? null, null, 2);
    return text.length > maxLength ? undefined : text;
};
