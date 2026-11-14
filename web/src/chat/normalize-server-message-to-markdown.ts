import { referenceNakedWidgetJson } from "../renderer/reference-naked-widget-json";
import { repairMalformedWidgetFences } from "../renderer/repair-malformed-widget-fences";

import { stripPreCallMessageMarker } from "./strip-pre-call-message-marker";

type ServerTextPart = { type: "text"; text?: string };

type ServerComponentPart = {
  type: "component";
  name?: string;
  props?: Record<string, unknown>;
};

type ServerToolUsePart = {
  type: "tool_use";
  name?: string;
  input?: unknown;
};

type ServerMessagePart = ServerTextPart | ServerComponentPart | ServerToolUsePart;

export type AssistantServerMessagePayload = {
  role?: string;
  content?: ServerMessagePart[];
  parts?: ServerMessagePart[];
  messageId?: string;
};

const WIDGET_ATTR_KEY = /^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/;

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === "object" && value !== null;

const isTextPart = (part: unknown): part is ServerTextPart => isRecord(part) && part.type === "text";

const isComponentPart = (part: unknown): part is ServerComponentPart =>
  isRecord(part) && part.type === "component" && typeof part.name === "string" && part.name.length > 0;

const isToolUsePart = (part: unknown): part is ServerToolUsePart => isRecord(part) && part.type === "tool_use";

const isMessagePart = (part: unknown): part is ServerMessagePart =>
  isTextPart(part) || isComponentPart(part) || isToolUsePart(part);

const isPayload = (value: unknown): value is AssistantServerMessagePayload => {
  if (!isRecord(value)) return false;
  const parts = value.content ?? value.parts;
  return Array.isArray(parts) && parts.every(isMessagePart);
};

/**
 * The model occasionally emits a single part object instead of an array
 * ({"content":{"type":"text",...}}). Coerce it so the message still renders.
 */
const coerceSinglePartPayload = (value: unknown): AssistantServerMessagePayload | null => {
  if (!isRecord(value)) return null;
  const part = value.content ?? value.parts;
  if (!isMessagePart(part)) return null;
  const rest = { ...value };
  delete rest.parts;
  return { ...rest, content: [part] };
};

const encodeAttrValue = (value: unknown) => JSON.stringify(value).replace(/'/g, "\\u0027");

const propsToAttrs = (props: Record<string, unknown>) =>
  Object.entries(props)
    .filter(([key, value]) => value !== undefined && WIDGET_ATTR_KEY.test(key))
    .map(([key, value]) => `${key}='${encodeAttrValue(value)}'`)
    .join(" ");

const componentPartToWidget = (part: ServerComponentPart) => {
  const attrs = propsToAttrs(part.props ?? {});
  return attrs ? `::${part.name}{${attrs}}` : `::${part.name}`;
};

const toolUsePartToWidget = (part: ServerToolUsePart) => {
  const payload = encodeAttrValue({ name: part.name, input: part.input });
  return `::ToolTrace{payload='${payload}'}`;
};

const partToMarkdown = (part: ServerMessagePart) => {
  if (isTextPart(part)) return part.text?.trim() ?? "";
  if (isToolUsePart(part)) return toolUsePartToWidget(part);
  return componentPartToWidget(part);
};

const payloadToMarkdown = (payload: AssistantServerMessagePayload) => {
  return (payload.content ?? payload.parts ?? []).map(partToMarkdown).filter(Boolean).join("\n\n");
};

const tryParsePayload = (value: string): AssistantServerMessagePayload | null => {
  try {
    const parsed = JSON.parse(value) as unknown;
    return isPayload(parsed) ? parsed : coerceSinglePartPayload(parsed);
  } catch {
    return null;
  }
};

export const normalizeServerMessageToMarkdown = (message: unknown): string => {
  let result: string;
  if (typeof message === "string") {
    const parsed = tryParsePayload(message);
    result = parsed ? payloadToMarkdown(parsed) : message;
  } else if (isPayload(message)) {
    result = payloadToMarkdown(message);
  } else {
    const coerced = coerceSinglePartPayload(message);
    if (coerced) {
      result = payloadToMarkdown(coerced);
    } else {
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
