import { Text } from "../../../provider/components";
import { useMemo, useState } from "react";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import type { WidgetRenderReportEntry } from "../../../renderer/render-report/widget-render-report";
import { readOpenEntityActionTarget } from "../../../renderer/canvas/entity-detail-instance";
import { mapFixtureToDeclaredAgentSpaceKeyFieldName } from "../../../renderer/_agent-space/declared-agent-space-key-field-name";
import type { WidgetPreviewFixtureFile } from "../../../preview/widget-preview.types";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";
import fixtureFile from "./entity-link.preview-fixtures.json";
import styles from "./EntityNodeStory.module.scss";

/** The store the entity stories render in: the widget hydration slice the renderer's data sources read. */
export const entityNodeStoryStore = configureStore({
  reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer },
});

/**
 * The payload of one of the entity preview fixtures — the stories and the preview share the dummy data. An app that
 * keeps its own copy of the fixtures (its entity types, its endpoints) passes it as `entityFixtureFile`.
 */
export const findEntityNodeFixturePayload = (
  fixtureName: string,
  entityFixtureFile: WidgetPreviewFixtureFile = fixtureFile as WidgetPreviewFixtureFile,
): Record<string, unknown> => {
  const fixture = entityFixtureFile.fixtures.find((candidate) => candidate.name === fixtureName);
  if (!fixture) throw new Error(`No entity preview fixture "${fixtureName}".`);
  return mapFixtureToDeclaredAgentSpaceKeyFieldName(fixture.payload);
};

export interface EntityNodeStoryProps {
  fixtureName: string;
  /** The pane width (the narrow state is 360). */
  maxWidth?: number;
  isEmailRender?: boolean;
  /** The fixture file to read; default: the package's `entity-link.preview-fixtures.json`. */
  entityFixtureFile?: WidgetPreviewFixtureFile;
}

/**
 * One entity-component story: the fixture's tree read with the generated schema and rendered through the
 * real renderer on the chat surface, with the block's render report and the entity a click would open under it (the
 * chat opens the `entityDetail` canvas for it).
 */
export const EntityNodeStory = (props: EntityNodeStoryProps) => {
  const { fixtureName, maxWidth = 720, isEmailRender = false, entityFixtureFile } = props;
  const tree = useMemo(
    () => readComposedViewTree(findEntityNodeFixturePayload(fixtureName, entityFixtureFile)),
    [fixtureName, entityFixtureFile],
  );
  const [report, setReport] = useState<WidgetRenderReportEntry | null>(null);
  const [openedEntity, setOpenedEntity] = useState<string | null>(null);
  return (
    <div className={styles.chatSurface} style={{ maxWidth }}>
      <ComposedViewRenderer
        tree={tree}
        interactive={!isEmailRender}
        isEmailRender={isEmailRender}
        agentSpaceKey={7}
        messageId={1}
        onReport={setReport}
        onOpenEntity={(action) => setOpenedEntity(JSON.stringify(readOpenEntityActionTarget(action) ?? null))}
      />
      <Text role="caption" translate={false} color="--agents-fg-lightest" className={styles.log}>
        {`render report: ${report ? JSON.stringify(report) : "—"}`}
      </Text>
      <Text role="caption" translate={false} color="--agents-fg-lightest" className={styles.log}>
        {`opens entityDetail for: ${openedEntity ?? "—"}`}
      </Text>
    </div>
  );
};
