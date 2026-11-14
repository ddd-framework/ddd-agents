import { describe, expect, test } from "vitest";
import blockFixture from "../custom-visualization-block-fixture.json";
import {
  CUSTOM_VISUALIZATION_DEFAULT_HEIGHT_PX,
  CUSTOM_VISUALIZATION_MAX_SOURCE_BYTES,
  parseCustomVisualizationPayload,
} from "./custom-visualization-widget-payload";

/** The payload the fence plugin hands the widget: the block's JSON without `type`. */
const readPayloadOfBlock = (block: string): string => {
  const blockJson = block.replace(/^```widget\n/, "").replace(/\n```$/, "");
  const { type, ...fields } = JSON.parse(blockJson) as Record<string, unknown>;
  expect(type).toBe("CustomVisualization");
  return JSON.stringify(fields);
};

describe("parseCustomVisualizationPayload", () => {
  test.each(blockFixture.cases)("reads the fixture case as the backend does: $name", (fixtureCase) => {
    const parsedPayload = parseCustomVisualizationPayload(readPayloadOfBlock(fixtureCase.block));
    expect(parsedPayload.isValid).toBe(fixtureCase.expectedRunnable);
    if (parsedPayload.isValid) {
      expect(parsedPayload.sourceBytes).toBe(fixtureCase.expectedSourceBytes);
    } else {
      expect(parsedPayload.problem.code).toBe(fixtureCase.expectedProblem);
    }
  });

  test("the cap is the backend's MAX_SOURCE_BYTES", () => {
    expect(CUSTOM_VISUALIZATION_MAX_SOURCE_BYTES).toBe(65536);
  });

  test("accepts the field aliases a weak model writes and clamps the height", () => {
    const parsedPayload = parseCustomVisualizationPayload(
      JSON.stringify({
        name: "Orbits",
        subtitle: "Scales",
        markup: "<p></p>",
        styles: "p{}",
        js: "run();",
        libs: "D3",
        initialHeight: 5000,
      }),
    );
    expect(parsedPayload.isValid).toBe(true);
    if (!parsedPayload.isValid) return;
    expect(parsedPayload.payload).toMatchObject({
      title: "Orbits",
      description: "Scales",
      html: "<p></p>",
      css: "p{}",
      script: "run();",
      libraries: ["d3"],
      initialHeight: 1200,
    });
  });

  test("never throws: malformed JSON is a block without content", () => {
    const parsedPayload = parseCustomVisualizationPayload("{not json");
    expect(parsedPayload.isValid).toBe(false);
    if (parsedPayload.isValid) return;
    expect(parsedPayload.problem.code).toBe("NO_CONTENT");
  });

  test("a block without a height gets the default height and null data", () => {
    const parsedPayload = parseCustomVisualizationPayload(JSON.stringify({ html: "<p></p>" }));
    expect(parsedPayload.isValid && parsedPayload.payload.initialHeight).toBe(CUSTOM_VISUALIZATION_DEFAULT_HEIGHT_PX);
    expect(parsedPayload.isValid && parsedPayload.payload.data).toBeNull();
  });
});
