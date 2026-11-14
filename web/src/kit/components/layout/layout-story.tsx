import { useMemo } from "react";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";
import layoutPreviewFixtures from "./layout.preview-fixtures.json";

/**
 * The layout stories' one renderer (not a story file): a tree from the preview fixtures
 * (`layout.preview-fixtures.json`, the same dummy data as the preview loop) or an inline payload, read with the generated
 * schema and rendered through the real `ComposedViewRenderer` on the chat background, so a token that does not switch
 * with the scheme shows. The render report goes to the console.
 */
export const layoutStoryStore = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });

export const findLayoutFixturePayload = (fixtureName: string): Record<string, unknown> =>
  (layoutPreviewFixtures.fixtures.find((fixture) => fixture.name === fixtureName)?.payload ?? {}) as Record<
    string,
    unknown
  >;

export interface LayoutStoryProps {
  /** A fixture of `layout.preview-fixtures.json`, or … */
  fixtureName?: string;
  /** … an inline payload (the fence fields next to `type`). */
  payload?: Record<string, unknown>;
  isEmailRender?: boolean;
  /** The chat pane width; the narrow state also sets the toolbar viewport. */
  maxWidth?: number;
}

export const LayoutStory = (props: LayoutStoryProps) => {
  const { fixtureName, payload, isEmailRender = false, maxWidth = 640 } = props;
  const tree = useMemo(
    () =>
      readComposedViewTree(payload ?? (fixtureName ? findLayoutFixturePayload(fixtureName) : {}), getComposedNodeSchema()),
    [fixtureName, payload],
  );
  return (
    <div style={{ background: "var(--agents-bg-var)", padding: 24, maxWidth, width: "100%", boxSizing: "border-box" }}>
      <ComposedViewRenderer
        tree={tree}
        interactive={!isEmailRender}
        isEmailRender={isEmailRender}
        agentSpaceKey={7}
        onReport={(entry) => console.info("[Story] render report", entry)}
      />
    </div>
  );
};
