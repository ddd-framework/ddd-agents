import React, { useMemo } from "react";
import type { CanvasBodyProps } from "../../renderer/canvas/canvas-body-props";
import { useAnchorWidgetPayload } from "../../renderer/canvas/use-anchor-widget-payload";
import { parseWebSearchResultsPayload } from "./shared/web-search-results-widget-payload";
import { WebSearchResultsCanvas } from "./WebSearchResultsCanvas";

/**
 * The `webSearchResults` CANVAS BODY (`canvasBodies.tsx`): re-reads the `WebSearchResults` fence of the anchor message
 * — the answer and its cited sources the inline widget rendered from — and shows them in the canvas. Renders nothing
 * when the anchor message no longer carries the fence.
 */
export const WebSearchResultsCanvasBody = ({ canvas, anchorMessageMarkdown }: CanvasBodyProps) => {
  const payload = useAnchorWidgetPayload(canvas, anchorMessageMarkdown, "WebSearchResults");
  const parsed = useMemo(() => (payload === undefined ? null : parseWebSearchResultsPayload(payload)), [payload]);
  if (parsed === null || (parsed.answer.trim() === "" && parsed.sources.length === 0)) return null;
  return (
    <WebSearchResultsCanvas
      query={parsed.query}
      answer={parsed.answer}
      sources={parsed.sources}
      provider={parsed.provider}
    />
  );
};
