/**
 * The draft's Markdown as the plain text the owner pastes into a mail program, a letter or a post: the copy button and
 * the `mailto:` body use it, so no `**`, `#` or `[label](url)` syntax reaches the recipient. Paragraphs and line breaks
 * stay; list items keep a "- " / "1. " marker; a link keeps its label and, when the label is not the address itself,
 * the address in brackets. Pure, total, never throws.
 */

const replaceLinks = (line: string): string =>
  line.replace(/!?\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g, (_match, label: string, url: string) => {
    const trimmedLabel = label.trim();
    if (trimmedLabel === "" || trimmedLabel === url || `mailto:${trimmedLabel}` === url) {
      return url.replace(/^mailto:/i, "");
    }
    return `${trimmedLabel} (${url.replace(/^mailto:/i, "")})`;
  });

const removeEmphasis = (line: string): string =>
  line
    .replace(/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/g, "$2")
    .replace(/(^|[^*\w])\*(?=\S)([^*]+?)(?<=\S)\*(?!\*)/g, "$1$2")
    .replace(/(^|[^_\w])_(?=\S)([^_]+?)(?<=\S)_(?![_\w])/g, "$1$2")
    .replace(/~~(?=\S)(.+?)(?<=\S)~~/g, "$1")
    .replace(/`([^`]+)`/g, "$1");

const convertLine = (line: string): string => {
  if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) return "";
  const withoutHeading = line.replace(/^\s{0,3}#{1,6}\s+/, "").replace(/\s+#+\s*$/, "");
  const withoutQuote = withoutHeading.replace(/^\s{0,3}>\s?/, "");
  const withListMarker = withoutQuote.replace(/^(\s*)[*+]\s+/, "$1- ");
  // A hard line break in Markdown (two trailing spaces or a backslash) is a plain line end here.
  const withoutHardBreak = withListMarker.replace(/(\s{2,}|\\)$/, "");
  return removeEmphasis(replaceLinks(withoutHardBreak));
};

export const convertDraftMarkdownToPlainText = (markdown: string): string =>
  markdown
    .replace(/\r\n?/g, "\n")
    .split("\n")
    .map(convertLine)
    .join("\n")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
