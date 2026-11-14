import { normalizeServerMessageToMarkdown } from "./normalize-server-message-to-markdown";

/**
 * Whether the whole markdown is one bare JSON object with a string `type`. Normalization already fenced every
 * registered widget type, so what is left is a type no widget renders — model noise like `{"type":"no-op"}`
 * (GPT-6 Luna), never owner-facing text.
 */
const isBareUnknownTypeJson = (markdown: string): boolean => {
  if (!markdown.startsWith("{")) return false;
  let parsed: unknown;
  try {
    parsed = JSON.parse(markdown);
  } catch {
    return false;
  }
  if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed)) return false;
  return typeof (parsed as Record<string, unknown>).type === "string";
};

/**
 * True when an assistant message's stored content yields markdown that actually renders something.
 *
 * Not every ASSISTANT row carries text. An agentic run persists one ASSISTANT/TEXT turn per step, and a step
 * that only called a tool stores `message: ""` (13 such turns in a 30-message run); a payload whose parts are
 * all blank text likewise normalizes to "". Such a row would render an empty markdown container that still
 * consumed a full `$x3` gap in the message list, so ConversationMessageList drops it instead — the per-turn agentic
 * trace those rows carry is the admin console's job (AgentConversationInspector), not the customer chat's.
 *
 * The null/blank short-circuit also keeps normalizeServerMessageToMarkdown's "unrecognized message shape"
 * console warning from firing on every one of those rows.
 */
export const assistantMessageHasRenderableMarkdown = (message: unknown): boolean => {
  if (message == null) return false;
  if (typeof message === "string" && message.trim() === "") return false;
  const markdown = normalizeServerMessageToMarkdown(message).trim();
  return markdown !== "" && !isBareUnknownTypeJson(markdown);
};
