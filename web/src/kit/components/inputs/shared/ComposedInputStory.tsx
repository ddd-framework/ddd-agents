import { useMemo } from "react";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../../renderer/widget-hydration";
import { StandaloneConversationWidgetProvider } from "../../../../renderer/ConversationWidgetContext";
import { getComposedNodeSchema } from "../../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../../renderer/widget-tree/composed-view-tree";
import type { WidgetPreviewFixtureFile } from "../../../../preview/widget-preview.types";
import { ComposedViewRenderer } from "../../../ComposedViewRenderer";

/**
 * The stories of the input components render the SAME dummy data as the preview loop: every fixture of the
 * `*.preview-fixtures.json` files in this folder, by name, through the real renderer on the chat surface. One helper
 * for the nine story files; the narrow state is a story with a narrow container and the mobile viewport.
 */
const fixtureFiles = import.meta.glob<WidgetPreviewFixtureFile>("../*.preview-fixtures.json", {
  eager: true,
  import: "default",
});

const findFixturePayload = (fixtureName: string): Record<string, unknown> =>
  Object.values(fixtureFiles)
    .flatMap((fixtureFile) => fixtureFile.fixtures)
    .find((fixture) => fixture.name === fixtureName)?.payload ?? {};

/** The store the stories render in: the hydration slice the renderer needs, nothing of the app. */
export const composedInputStoryStore = configureStore({
  reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer },
});

export interface ComposedInputStoryProps {
  /** A fixture of this folder's preview fixtures, or an inline tree. */
  fixtureName?: string;
  payload?: Record<string, unknown>;
  interactive?: boolean;
  isEmailRender?: boolean;
  /** The chat width; 360 for the narrow state. */
  maxWidth?: number;
}

export const ComposedInputStory = (props: ComposedInputStoryProps) => {
  const { fixtureName, payload, interactive = true, isEmailRender = false, maxWidth = 720 } = props;
  const tree = useMemo(
    () => readComposedViewTree(payload ?? findFixturePayload(fixtureName ?? ""), getComposedNodeSchema()),
    [fixtureName, payload],
  );
  const callbacks = useMemo(() => ({}), []);
  return (
    <StandaloneConversationWidgetProvider callbacks={callbacks}>
      {/* the chat surface, so a token that does not switch with the scheme shows */}
      <div style={{ background: "var(--agents-bg-var)", padding: 24, maxWidth, width: "100%" }}>
        <ComposedViewRenderer
          tree={tree}
          interactive={interactive}
          isEmailRender={isEmailRender}
          agentSpaceKey={7}
          onReport={(entry) => console.info("[Story] render report", entry)}
        />
      </div>
    </StandaloneConversationWidgetProvider>
  );
};
