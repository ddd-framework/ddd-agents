/**
 * Mirrors `AIConversationMessage::PRE_CALL_MESSAGE_MARKER` (PHP). The model prefixes a short "I'm about to do X"
 * status line with this sentinel; the BACKEND only detects/signals on it and stores it RAW (it never strips). Every
 * render layer — this is the customer chat one — strips it before showing the text to the user.
 */
export const PRE_CALL_MESSAGE_MARKER = "{preCallMessage}";

/**
 * The MANGLED JSON form some models emit instead of the sentinel (Gemini 3.5 Flash): the status line
 * arrives as `{ "preCallMessage": "…" }<` — optionally with extra fields and/or a trailing `<|im_call:…` pseudo call
 * marker — fused into the assistant text. Normalized to the inner status text so the user never sees raw JSON.
 * Mirrors the PHP normalization in `AIConversationMessage::stripPreCallMessageMarker`.
 */
const MANGLED_PRE_CALL_RE = /\{\s*"preCallMessage"\s*:\s*"((?:[^"\\]|\\.)*)"\s*,?\s*[^}]*\}\s*(?:<\S*)?/g;

/**
 * Remove the {preCallMessage} sentinel from assistant text before display, and normalize the mangled JSON form to
 * its inner status line. Also holds back a leading PARTIAL of either shape while it is still streaming in (so the
 * user never sees a "{preCa…" or `{ "preCallMessage": "Einen Mo` flash before the full form arrives).
 */
export const stripPreCallMessageMarker = (text: string): string => {
  if (!text) return text;
  const leading = text.replace(/^\s+/, "");
  // The sentinel itself is still streaming (nothing after it yet) → render nothing until real content arrives.
  if (leading.length < PRE_CALL_MESSAGE_MARKER.length && PRE_CALL_MESSAGE_MARKER.startsWith(leading)) return "";
  // The mangled JSON form is still streaming (opened but not yet closed) → hold it back the same way.
  const mangledPrefix = '{ "preCallMessage"';
  const normalizedLeading = leading.replace(/\s+/g, " ");
  if (
    (normalizedLeading.length < mangledPrefix.length && mangledPrefix.startsWith(normalizedLeading)) ||
    (normalizedLeading.startsWith(mangledPrefix) && !normalizedLeading.includes("}"))
  ) {
    return "";
  }
  const withMangledNormalized = text.replace(MANGLED_PRE_CALL_RE, (_match, escapedStatusLine: string) => {
    try {
      return JSON.parse(`"${escapedStatusLine}"`) as string;
    } catch {
      return escapedStatusLine;
    }
  });
  const withMarkerRemoved = withMangledNormalized.split(PRE_CALL_MESSAGE_MARKER).join("");
  // The marker (or its mangled form) sits at the very start, after only whitespace → that whitespace was there
  // only to separate it from the rest of the text and is removed along with it. Anything else — a message that
  // never carried the marker there at all — keeps its OWN leading whitespace untouched, so a four-space-indented
  // code block at the start of a message stays indented (plain CommonMark code) instead of sliding to column 0
  // and becoming a widget fence.
  const markerWasLeading = leading.startsWith(PRE_CALL_MESSAGE_MARKER) || normalizedLeading.startsWith(mangledPrefix);
  return markerWasLeading ? withMarkerRemoved.replace(/^\s+/, "") : withMarkerRemoved;
};
