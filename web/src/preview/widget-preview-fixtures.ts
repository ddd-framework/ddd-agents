import { mapFixtureToDeclaredAgentSpaceKeyFieldName } from "../renderer/_agent-space/declared-agent-space-key-field-name";
import type { WidgetPreviewFixture, WidgetPreviewFixtureFile } from "./widget-preview.types";

/**
 * Every `*.preview-fixtures.json` below the package's `widgets/` and `kit/`, by wire name — read by folder glob, so the
 * fixtures of a widget are added as one new file next to it; an app adds the fixtures of its widgets with
 * `registerWidgetPreviewFixtureFiles`. Imports nothing from the app, so the preview entry can read it before the
 * request transport is installed.
 */
const fixtureModules = import.meta.glob<WidgetPreviewFixtureFile>(
  ["../widgets/**/*.preview-fixtures.json", "../kit/**/*.preview-fixtures.json"],
  {
    eager: true,
    import: "default",
  },
);

const sortByWireName = (fixtureFiles: WidgetPreviewFixtureFile[]): WidgetPreviewFixtureFile[] =>
  fixtureFiles.sort((first, second) => first.wireName.localeCompare(second.wireName));

/** Every fixture file, sorted by wire name; filled in place by `registerWidgetPreviewFixtureFiles`. */
export const widgetPreviewFixtureFiles: WidgetPreviewFixtureFile[] = sortByWireName(Object.values(fixtureModules));

/** Adds an app's fixture files (its glob stays in its code). Runs before the preview reads a fixture. */
export const registerWidgetPreviewFixtureFiles = (fixtureFiles: readonly WidgetPreviewFixtureFile[]): void => {
  widgetPreviewFixtureFiles.push(...fixtureFiles);
  sortByWireName(widgetPreviewFixtureFiles);
};

/**
 * The fixtures of a widget from EVERY file naming it — a `ComposedView` component can add its own file next to it
 * instead of editing the widget's — or an empty list. A fixture an app registers under the name of a package fixture
 * of the same widget replaces it in place (an app's copy with its own entity types and endpoints). Each is read under
 * the agent space key field the app declares (`mapFixtureToDeclaredAgentSpaceKeyFieldName`; the package's files name
 * `agentSpaceId`), at call time: an app installs its environment after this module is loaded.
 */
export const findWidgetPreviewFixtures = (widgetName: string): readonly WidgetPreviewFixture[] => {
  const fixturesByName = new Map<string, WidgetPreviewFixture>();
  for (const fixtureFile of widgetPreviewFixtureFiles) {
    if (fixtureFile.wireName !== widgetName) continue;
    for (const fixture of fixtureFile.fixtures) fixturesByName.set(fixture.name, fixture);
  }
  return [...fixturesByName.values()].map(mapFixtureToDeclaredAgentSpaceKeyFieldName);
};

/** One named fixture, or undefined. */
export const findWidgetPreviewFixture = (widgetName: string, fixtureName: string): WidgetPreviewFixture | undefined =>
  findWidgetPreviewFixtures(widgetName).find((fixture) => fixture.name === fixtureName);

/** The fence the fixture stands for — what a canvas body re-reads as its anchor message. */
export const buildWidgetPreviewFence = (widgetName: string, fixture: WidgetPreviewFixture): string =>
  "```widget\n" + JSON.stringify({ type: widgetName, ...fixture.payload }) + "\n```";
