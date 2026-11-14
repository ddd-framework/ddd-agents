import { Text } from "../../../../provider/components";
import { useMemo, useState } from "react";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../../renderer/widget-hydration";
import { readComposedViewTree } from "../../../../renderer/widget-tree/composed-view-tree";
import type { WidgetRenderReportEntry } from "../../../../renderer/render-report/widget-render-report";
import type { WidgetPreviewFixtureFile } from "../../../../preview/widget-preview.types";
import { ComposedViewRenderer } from "../../../ComposedViewRenderer";
import styles from "./TextNodeStory.module.scss";

/** The store the text stories render in: the widget hydration slice the renderer's data sources read. */
export const textNodeStoryStore = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });

const fixtureFiles = import.meta.glob<WidgetPreviewFixtureFile>("../*.preview-fixtures.json", {
  eager: true,
  import: "default",
});

/** The payload of one of the text components' preview fixtures — the stories and the preview share the dummy data. */
export const findTextNodeFixturePayload = (fixtureName: string): Record<string, unknown> => {
  for (const fixtureFile of Object.values(fixtureFiles)) {
    const fixture = fixtureFile.fixtures.find((candidate) => candidate.name === fixtureName);
    if (fixture) return fixture.payload;
  }
  throw new Error(`No text preview fixture "${fixtureName}".`);
};

/** A prop bound to a tool result whose call has not stored it yet — the loading state of a node. */
export const PENDING_STORY_BINDING = { $from: { toolCall: "call_story_1", path: "$.value" } };

export interface TextNodeStoryProps {
  /** The block fields next to `type` (`view`, `state`, `data`, …), or a preview fixture by name. */
  payload?: Record<string, unknown>;
  fixtureName?: string;
  /** The pane width (the narrow state is 360). */
  maxWidth?: number;
  isEmailRender?: boolean;
}

/**
 * One text-component story: the tree read with the generated schema and rendered through the real
 * renderer on the chat surface, with the block's render report under it — the report is how the empty and error
 * states show what the model would learn.
 */
export const TextNodeStory = (props: TextNodeStoryProps) => {
  const { payload, fixtureName, maxWidth = 720, isEmailRender = false } = props;
  const tree = useMemo(
    () => readComposedViewTree(payload ?? findTextNodeFixturePayload(fixtureName ?? "")),
    [payload, fixtureName],
  );
  const [report, setReport] = useState<WidgetRenderReportEntry | null>(null);
  return (
    <div className={styles.chatSurface} style={{ maxWidth }}>
      <ComposedViewRenderer
        tree={tree}
        interactive={!isEmailRender}
        isEmailRender={isEmailRender}
        agentSpaceKey={7}
        onReport={setReport}
      />
      <Text role="caption" translate={false} color="--agents-fg-lightest" className={styles.report}>
        {`render report: ${report ? JSON.stringify(report) : "—"}`}
      </Text>
    </div>
  );
};
