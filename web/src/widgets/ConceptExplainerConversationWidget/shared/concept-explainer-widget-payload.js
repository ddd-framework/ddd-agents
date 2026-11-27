/** The backend's `ConceptExplainerConversationWidget::MAX_TITLE_LENGTH`. */
export const MAX_TITLE_LENGTH = 160;
/** The backend's `ConceptExplainerConversationWidget::MAX_VALUES`. */
export const MAX_VALUES = 20;
const MAX_TYPE_ID_LENGTH = 64;
const MAX_STRING_VALUE_LENGTH = 120;
const EXPLAINER_ALIASES = ["explainer", "explainerType", "explainer_type", "typeId", "type_id", "concept", "kind"];
const VALUES_ALIASES = ["values", "parameters", "params", "data", "inputs"];
const TITLE_ALIASES = ["title", "headline", "heading"];
const STEP_ALIASES = ["step", "initialStep", "initial_step", "startStep", "start_step"];
const isPlainObject = (candidate) => typeof candidate === "object" && candidate !== null && !Array.isArray(candidate);
const readFirst = (source, aliases) => {
    for (const alias of aliases) {
        if (Object.prototype.hasOwnProperty.call(source, alias) && source[alias] !== undefined && source[alias] !== null) {
            return source[alias];
        }
    }
    return undefined;
};
const readTrimmedString = (candidate, maxLength) => {
    if (typeof candidate !== "string")
        return undefined;
    const trimmed = candidate.trim();
    return trimmed === "" ? undefined : trimmed.slice(0, maxLength);
};
/** Scalars only: a number, a numeric-looking string or a short string; objects, lists and prototype names are dropped. */
const readScalarValues = (rawValues) => {
    const scalarValues = {};
    if (!isPlainObject(rawValues))
        return scalarValues;
    for (const [valueName, value] of Object.entries(rawValues).slice(0, MAX_VALUES)) {
        if (valueName === "__proto__" || valueName === "constructor" || valueName === "prototype")
            continue;
        if (typeof value === "number" && Number.isFinite(value))
            scalarValues[valueName] = value;
        else if (typeof value === "string")
            scalarValues[valueName] = value.trim().slice(0, MAX_STRING_VALUE_LENGTH);
        else if (typeof value === "boolean")
            scalarValues[valueName] = value;
    }
    return scalarValues;
};
export const parseConceptExplainerPayload = (payload) => {
    let parsed;
    try {
        parsed = JSON.parse(payload ?? "");
    }
    catch {
        return { explainer: "", values: {} };
    }
    if (!isPlainObject(parsed))
        return { explainer: "", values: {} };
    const rawValues = readFirst(parsed, VALUES_ALIASES);
    // ChatGPT's LearningViz shape: `{"parameters": {"type_id": "MOON_PHASES"}}` — the id may sit inside the values.
    const explainer = readTrimmedString(readFirst(parsed, EXPLAINER_ALIASES), MAX_TYPE_ID_LENGTH) ??
        (isPlainObject(rawValues)
            ? readTrimmedString(readFirst(rawValues, EXPLAINER_ALIASES), MAX_TYPE_ID_LENGTH)
            : undefined) ??
        "";
    const title = readTrimmedString(readFirst(parsed, TITLE_ALIASES), MAX_TITLE_LENGTH);
    const rawStep = readFirst(parsed, STEP_ALIASES);
    const step = typeof rawStep === "number" && Number.isInteger(rawStep) && rawStep >= 0
        ? rawStep
        : readTrimmedString(rawStep, MAX_TYPE_ID_LENGTH);
    return {
        explainer,
        ...(title !== undefined ? { title } : {}),
        values: readScalarValues(rawValues),
        ...(step !== undefined ? { step } : {}),
    };
};
/** Reads a finite number from a value the model wrote as a number or a numeric string (`"4,3"` included). */
export const readConceptExplainerNumber = (value) => {
    if (typeof value === "number")
        return Number.isFinite(value) ? value : undefined;
    if (typeof value !== "string")
        return undefined;
    const normalized = value.trim().replace(",", ".");
    if (!/^-?\d+(\.\d+)?$/.test(normalized))
        return undefined;
    const parsedNumber = Number(normalized);
    return Number.isFinite(parsedNumber) ? parsedNumber : undefined;
};
/** The first value present under one of the aliases. */
export const readConceptExplainerValue = (rawValues, aliases) => readFirst(rawValues, aliases);
