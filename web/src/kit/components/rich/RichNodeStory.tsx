import { useMemo } from "react";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import type { WidgetPreviewFixtureFile } from "../../../preview/widget-preview.types";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";
import mathFixtures from "./math.preview-fixtures.json";
import codeFixtures from "./code.preview-fixtures.json";
import diagramFixtures from "./diagram.preview-fixtures.json";
import svgFixtures from "./svg.preview-fixtures.json";
import styles from "./RichNodeStory.module.scss";

/**
 * The story frame of the rich kit nodes: a fixture of the folder's `*.preview-fixtures.json` — the
 * same dummy data the preview loop shoots — read with the generated schema and rendered by the real renderer on the
 * chat background, at the chat width or a narrow one. Stories only; nothing in the app imports it.
 */
const RICH_FIXTURE_FILES = [mathFixtures, codeFixtures, diagramFixtures, svgFixtures] as WidgetPreviewFixtureFile[];

/** The store the renderer needs (its `$source` loaders read the widget hydration slice). */
export const richNodeStoryStore = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });

export interface RichNodeStoryProps {
  /** A fixture name of the four rich fixture files. */
  fixtureName: string;
  /** The e-mail capture's rendering: frozen inputs, no controls. */
  isEmailRender?: boolean;
  /** The pane width in CSS pixels (the chat's 528 by default). */
  maxWidth?: number;
}

export const RichNodeStory = (props: RichNodeStoryProps) => {
  const { fixtureName, isEmailRender = false, maxWidth = 528 } = props;
  const tree = useMemo(() => {
    const fixture = RICH_FIXTURE_FILES.flatMap((fixtureFile) => fixtureFile.fixtures).find(
      (richFixture) => richFixture.name === fixtureName,
    );
    return readComposedViewTree(fixture?.payload ?? {}, getComposedNodeSchema());
  }, [fixtureName]);
  return (
    <div className={styles.chatPane} style={{ maxWidth }}>
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
