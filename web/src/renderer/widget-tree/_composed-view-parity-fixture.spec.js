import { describe, expect, it } from "vitest";
import moduleSchema from "./composed-node-schema.json";
import parityFixture from "./composed-view-parity.json";
/**
 * The parity fixture hosts read (`stubSchema`) carries the module's whole schema plus its test-only stubs: a widget,
 * embeddable widget or data source of the shipped module schema that the fixture lacks fails a host's parity test.
 * (That the shipped schema equals the backend export is checked by the package's PHP suite.)
 */
describe("composed-view-parity.json stubSchema", () => {
    const stubSchema = parityFixture.stubSchema;
    it("carries every widget type of the module schema", () => {
        expect(moduleSchema.widgetTypes.filter((widgetType) => !stubSchema.widgetTypes.includes(widgetType))).toEqual([]);
    });
    it("carries every embeddable widget and data source of the module schema", () => {
        const missingEmbeddableWidgets = Object.keys(moduleSchema.embeddableWidgets).filter((name) => !(name in stubSchema.embeddableWidgets));
        const missingDataSources = Object.keys(moduleSchema.dataSources).filter((name) => !(name in stubSchema.dataSources));
        expect(missingEmbeddableWidgets).toEqual([]);
        expect(missingDataSources).toEqual([]);
    });
});
