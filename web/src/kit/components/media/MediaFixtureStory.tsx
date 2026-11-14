import { useMemo } from "react";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import type { WidgetPreviewFixtureFile } from "../../../preview/widget-preview.types";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";

export interface MediaFixtureStoryProps {
  fixtureFile: WidgetPreviewFixtureFile;
  fixtureName: string;
  isEmailRender?: boolean;
  /** The chat pane width; a story's narrow state sets a phone width. */
  paneWidth?: number;
}

/**
 * Story frame of the media kit: one fixture of the components' `*.preview-fixtures.json` — the same
 * dummy data the preview loop shoots — rendered through the real renderer on the chat background at a pane width.
 * Reports are logged, not sent.
 */
export const MediaFixtureStory = (props: MediaFixtureStoryProps) => {
  const { fixtureFile, fixtureName, isEmailRender = false, paneWidth = 528 } = props;
  const tree = useMemo(
    () =>
      readComposedViewTree(
        fixtureFile.fixtures.find((fixture) => fixture.name === fixtureName)?.payload ?? {},
        getComposedNodeSchema(),
      ),
    [fixtureFile, fixtureName],
  );
  return (
    // the chat surface, so a token that does not switch with the scheme shows
    <div style={{ background: "var(--agents-bg-var)", padding: 24, width: paneWidth, maxWidth: "100%" }}>
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
