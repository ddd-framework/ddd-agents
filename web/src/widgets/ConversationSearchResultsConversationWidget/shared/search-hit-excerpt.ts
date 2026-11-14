type OpenAiTextPart = {
  type?: string;
  text?: string;
};

type OpenAiTextPayload = {
  content?: OpenAiTextPart[] | string;
  text?: string;
};

const parseJsonString = (value: string): unknown => {
  try {
    return JSON.parse(value);
  } catch {
    return undefined;
  }
};

const readTextFromParsedPayload = (parsed: unknown): string | undefined => {
  if (typeof parsed === "string") {
    return parsed;
  }
  if (typeof parsed !== "object" || parsed === null) {
    return undefined;
  }
  const payload = parsed as OpenAiTextPayload;
  if (typeof payload.text === "string") {
    return payload.text;
  }
  if (typeof payload.content === "string") {
    return payload.content;
  }
  if (Array.isArray(payload.content)) {
    const textParts = payload.content
      .map((part) => (typeof part.text === "string" ? part.text : ""))
      .filter((text) => text.trim().length > 0);
    return textParts.length > 0 ? textParts.join("\n\n") : undefined;
  }
  return undefined;
};

const unescapeJsonString = (value: string): string => {
  const parsed = parseJsonString(`"${value}"`);
  return typeof parsed === "string" ? parsed : value;
};

const readTextFromJsonExcerpt = (excerpt: string): string | undefined => {
  const matches = Array.from(excerpt.matchAll(/"text"\s*:\s*"((?:\\.|[^"\\])*)"/g));
  if (matches.length === 0) {
    return undefined;
  }
  return matches
    .map((match) => unescapeJsonString(match[1] ?? ""))
    .filter((text) => text.trim().length > 0)
    .join("\n\n");
};

export const normalizeSearchHitExcerpt = (rawExcerpt: unknown): string => {
  if (typeof rawExcerpt !== "string") {
    const parsedText = readTextFromParsedPayload(rawExcerpt);
    return parsedText ?? "";
  }
  const trimmedExcerpt = rawExcerpt.trim();
  const parsedText = readTextFromParsedPayload(parseJsonString(trimmedExcerpt));
  if (parsedText !== undefined) {
    return parsedText;
  }
  return readTextFromJsonExcerpt(trimmedExcerpt) ?? rawExcerpt;
};
