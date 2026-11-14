export async function copyRichToClipboard(plainText: string, html: string) {
  if (!plainText) {
    console.warn("No text to copy");
    return;
  }

  if (!navigator.clipboard) {
    console.warn("Clipboard API not available");
    return;
  }

  if (!(html && typeof ClipboardItem !== "undefined" && navigator.clipboard.write)) {
    console.warn("Clipboard does not support rich text, copying plain text only");
    return navigator.clipboard.writeText(plainText);
  }
  try {
    const item = new ClipboardItem({
      "text/plain": new Blob([plainText], { type: "text/plain" }),
      "text/html": new Blob([html], { type: "text/html" }),
    });
    return navigator.clipboard.write([item]);
  } catch (error) {
    console.error("Failed to copy rich text to clipboard:", error);
    return navigator.clipboard.writeText(plainText);
  }
}
