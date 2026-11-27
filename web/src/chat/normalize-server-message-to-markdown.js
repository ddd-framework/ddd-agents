import { referenceNakedWidgetJson } from "../renderer/reference-naked-widget-json";
import { repairMalformedWidgetFences } from "../renderer/repair-malformed-widget-fences";
import { stripPreCallMessageMarker } from "./strip-pre-call-message-marker";
const WIDGET_ATTR_KEY = /^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/;
const isRecord = (value) => typeof value === "object" && value !== null;
const isTextPart = (part) => isRecord(part) && part.type === "text";
const isComponentPart = (part) => isRecord(part) && part.type === "component" && typeof part.name === "string" && part.name.length > 0;
const isToolUsePart = (part) => isRecord(part) && part.type === "tool_use";
const isMessagePart = (part) => isTextPart(part) || isComponentPart(part) || isToolUsePart(part);
const isPayload = (value) => {
    if (!isRecord(value))
        return false;
    const parts = value.content ?? value.parts;
    return Array.isArray(parts) && parts.every(isMessagePart);
};
/**
 * The model occasionally emits a single part object instead of an array
 * ({"content":{"type":"text",...}}). Coerce it so the message still renders.
 */
const coerceSinglePartPayload = (value) => {
    if (!isRecord(value))
        return null;
    const part = value.content ?? value.parts;
    if (!isMessagePart(part))
        return null;
    const rest = { ...value };
    delete rest.parts;
    return { ...rest, content: [part] };
};
const encodeAttrValue = (value) => JSON.stringify(value).replace(/'/g, "\\u0027");
const propsToAttrs = (props) => Object.entries(props)
    .filter(([key, value]) => value !== undefined && WIDGET_ATTR_KEY.test(key))
    .map(([key, value]) => `${key}='${encodeAttrValue(value)}'`)
    .join(" ");
const componentPartToWidget = (part) => {
    const attrs = propsToAttrs(part.props ?? {});
    return attrs ? `::${part.name}{${attrs}}` : `::${part.name}`;
};
const toolUsePartToWidget = (part) => {
    const payload = encodeAttrValue({ name: part.name, input: part.input });
    return `::ToolTrace{payload='${payload}'}`;
};
const partToMarkdown = (part) => {
    if (isTextPart(part))
        return part.text?.trim() ?? "";
    if (isToolUsePart(part))
        return toolUsePartToWidget(part);
    return componentPartToWidget(part);
};
const payloadToMarkdown = (payload) => {
    return (payload.content ?? payload.parts ?? []).map(partToMarkdown).filter(Boolean).join("\n\n");
};
const tryParsePayload = (value) => {
    try {
        const parsed = JSON.parse(value);
        return isPayload(parsed) ? parsed : coerceSinglePartPayload(parsed);
    }
    catch {
        return null;
    }
};
export const normalizeServerMessageToMarkdown = (message) => {
    let result;
    if (typeof message === "string") {
        const parsed = tryParsePayload(message);
        result = parsed ? payloadToMarkdown(parsed) : message;
    }
    else if (isPayload(message)) {
        result = payloadToMarkdown(message);
    }
    else {
        const coerced = coerceSinglePartPayload(message);
        if (coerced) {
            result = payloadToMarkdown(coerced);
        }
        else {
            console.warn("[AIExtensions] normalizeServerMessageToMarkdown: unrecognized message shape", message);
            result = "";
        }
    }
    // The backend stores the {preCallMessage} status-line sentinel raw (it only detects/signals on it) — strip it here.
    // Then repair a ```widget fence the model broke mid-text (opener glued to the prose line / never closed — the model
    // switched into a tool call), then re-fence any naked widget JSON (a weak model emitted
    // `{ "type": "ExampleWidget" }` without a ```widget fence): wrapping a known widget type lets
    // remarkWidgetFencePlugin render it, so the customer never sees raw JSON.
    return referenceNakedWidgetJson(repairMalformedWidgetFences(stripPreCallMessageMarker(result)));
};
