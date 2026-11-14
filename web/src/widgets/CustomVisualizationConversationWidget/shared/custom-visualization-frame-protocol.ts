/**
 * The message protocol between the chat and the sandbox frame of a CustomVisualization. The frame
 * (`sandbox-frame/custom-visualization-frame.html`) runs the model's code on an opaque origin; everything it says is
 * untrusted. The host therefore accepts a message only when it comes from the frame's own `contentWindow`, names the
 * channel and the per-instance token, has exactly the keys of its type, stays under a size limit and — for a height —
 * carries a finite number, which the host clamps. A message is never a command: the host reads a height, a heartbeat,
 * "ready" and an error line, nothing else.
 */

export const CUSTOM_VISUALIZATION_FRAME_CHANNEL = "agents-custom-visualization";

/** The global the visualization's script reads (`window.AGENTS_VISUALIZATION.data`, `.colorScheme`). */
export const CUSTOM_VISUALIZATION_GLOBAL_NAME = "AGENTS_VISUALIZATION";

/** The prefix of the theme variables the frame sets (`--agents-fg`, `--agents-accent`, …). */
export const CUSTOM_VISUALIZATION_THEME_VARIABLE_PREFIX = "--agents-";

/** The frame posts a heartbeat every second; this long without one (an endless loop) and the host removes the frame. */
export const CUSTOM_VISUALIZATION_HEARTBEAT_TIMEOUT_MS = 5000;

/** From the frame's mount until its "ready" (libraries + script ran); longer and the host removes the frame. */
export const CUSTOM_VISUALIZATION_READY_TIMEOUT_MS = 15000;

/** How often the host checks the two deadlines above. */
export const CUSTOM_VISUALIZATION_WATCHDOG_INTERVAL_MS = 500;

/** The frame's height is clamped to this range (CSS px). */
export const CUSTOM_VISUALIZATION_MIN_HEIGHT_PX = 80;
export const CUSTOM_VISUALIZATION_MAX_HEIGHT_PX = 1200;

/** At most this many height messages are applied per second; the rest are ignored. */
export const CUSTOM_VISUALIZATION_MAX_HEIGHT_MESSAGES_PER_SECOND = 20;

/** A message whose JSON is longer than this is ignored. */
export const CUSTOM_VISUALIZATION_MAX_MESSAGE_CHARS = 1024;

/** The frame cuts an error line to this length; a longer one is ignored as malformed. */
export const CUSTOM_VISUALIZATION_MAX_ERROR_MESSAGE_CHARS = 300;

export type CustomVisualizationFrameMessage =
  | { type: "heartbeat" }
  | { type: "ready" }
  | { type: "height"; height: number }
  | { type: "error"; message: string };

/** The keys each message type carries — exactly these, nothing more. */
const FRAME_MESSAGE_KEYS: Readonly<Record<CustomVisualizationFrameMessage["type"], readonly string[]>> = {
  heartbeat: ["channel", "token", "type"],
  ready: ["channel", "token", "type"],
  height: ["channel", "token", "type", "height"],
  error: ["channel", "token", "type", "message"],
};

const isFrameMessageType = (type: unknown): type is CustomVisualizationFrameMessage["type"] =>
  typeof type === "string" && Object.prototype.hasOwnProperty.call(FRAME_MESSAGE_KEYS, type);

const measureMessageChars = (data: unknown): number => {
  try {
    return JSON.stringify(data)?.length ?? Number.POSITIVE_INFINITY;
  } catch {
    return Number.POSITIVE_INFINITY;
  }
};

/**
 * The frame message `event` carries, or null when it is not one: another window sent it, the token or channel is not
 * this instance's, a key is missing or extra, the message is too large, or a value has the wrong type.
 */
export const readCustomVisualizationFrameMessage = (
  event: Pick<MessageEvent, "data" | "source">,
  expectedSource: MessageEventSource | null,
  expectedToken: string,
): CustomVisualizationFrameMessage | null => {
  if (expectedSource === null || event.source !== expectedSource) return null;
  const data: unknown = event.data;
  if (typeof data !== "object" || data === null || Array.isArray(data)) return null;
  if (measureMessageChars(data) > CUSTOM_VISUALIZATION_MAX_MESSAGE_CHARS) return null;
  const fields = data as Record<string, unknown>;
  if (fields.channel !== CUSTOM_VISUALIZATION_FRAME_CHANNEL || fields.token !== expectedToken) return null;
  if (!isFrameMessageType(fields.type)) return null;
  const expectedKeys = FRAME_MESSAGE_KEYS[fields.type];
  const keys = Object.keys(fields);
  if (keys.length !== expectedKeys.length || !keys.every((key) => expectedKeys.includes(key))) return null;
  if (fields.type === "height") {
    return typeof fields.height === "number" && Number.isFinite(fields.height)
      ? { type: "height", height: fields.height }
      : null;
  }
  if (fields.type === "error") {
    return typeof fields.message === "string" && fields.message.length <= CUSTOM_VISUALIZATION_MAX_ERROR_MESSAGE_CHARS
      ? { type: "error", message: fields.message }
      : null;
  }
  return { type: fields.type };
};

/** A height the frame asked for, as the host applies it: rounded up and clamped to the allowed range. */
export const clampCustomVisualizationHeight = (requestedHeight: number): number =>
  Math.min(
    CUSTOM_VISUALIZATION_MAX_HEIGHT_PX,
    Math.max(CUSTOM_VISUALIZATION_MIN_HEIGHT_PX, Math.ceil(requestedHeight)),
  );

/**
 * Whether one more height message may be applied now: keeps the times of the applied ones of the last second in
 * `appliedAtTimes` (mutated) and refuses beyond {@link CUSTOM_VISUALIZATION_MAX_HEIGHT_MESSAGES_PER_SECOND}.
 */
export const admitCustomVisualizationHeightMessage = (appliedAtTimes: number[], now: number): boolean => {
  while (appliedAtTimes.length > 0 && now - appliedAtTimes[0] >= 1000) appliedAtTimes.shift();
  if (appliedAtTimes.length >= CUSTOM_VISUALIZATION_MAX_HEIGHT_MESSAGES_PER_SECOND) return false;
  appliedAtTimes.push(now);
  return true;
};

/** A fresh per-instance token: 128 random bits as hex. */
export const createCustomVisualizationInstanceToken = (): string => {
  const randomBytes = new Uint8Array(16);
  crypto.getRandomValues(randomBytes);
  return Array.from(randomBytes, (randomByte) => randomByte.toString(16).padStart(2, "0")).join("");
};

/** The one message the host sends: everything the frame shows and runs. */
export interface CustomVisualizationRenderMessage {
  channel: typeof CUSTOM_VISUALIZATION_FRAME_CHANNEL;
  type: "render";
  token: string;
  html: string;
  css: string;
  script: string;
  librarySources: string[];
  data: unknown;
  /** The theme variables under the package's prefix (`--agents-fg` → a resolved colour). */
  theme: Record<string, string>;
  colorScheme: "light" | "dark";
  /**
   * Further names the frame defines the same frozen global under, beside `AGENTS_VISUALIZATION` — the names the app
   * declares (`AgentsEnvironment.sandboxLegacyAliases.visualizationGlobalNames`). Empty without a declaration.
   */
  visualizationGlobalNames: string[];
  /**
   * Further prefixes the frame sets every theme variable under, beside `--agents-` — the prefixes the app declares
   * (`AgentsEnvironment.sandboxLegacyAliases.themeVariablePrefixes`). Empty without a declaration.
   */
  themeVariablePrefixes: string[];
}
