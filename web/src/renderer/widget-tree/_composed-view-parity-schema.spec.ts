import { describe, expect, it } from "vitest";
import moduleSchema from "./composed-node-schema.json";
import parityFixture from "./composed-view-parity.json";

/**
 * The parity fixture's `stubSchema` carries the package's widgets plus the test-only stub widgets (its node types are
 * test-only stubs by design): every widget and embeddable widget the package ships must be in it, or a host parity
 * test reads a package widget as unknown.
 * (The freshness of composed-node-schema.json against the PHP classes is held by the package's PHP suite:
 * ComposedViewNodeSchemaExportCommandTest.)
 */
describe("composed-view parity fixture — stub schema covers the package schema", () => {
  const stubSchema = parityFixture.stubSchema;

  it("lists every widget type of the package", () => {
    for (const widgetType of moduleSchema.widgetTypes) {
      expect(stubSchema.widgetTypes).toContain(widgetType);
    }
  });

  it("lists every embeddable widget of the package", () => {
    for (const widgetType of Object.keys(moduleSchema.embeddableWidgets)) {
      expect(Object.keys(stubSchema.embeddableWidgets)).toContain(widgetType);
    }
  });
});
