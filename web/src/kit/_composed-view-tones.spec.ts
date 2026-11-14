import { describe, expect, test } from "vitest";
import { composedNodeSchema } from "../renderer/widget-tree/composed-node-schema";
import {
  COMPOSED_VIEW_TONE_ALIASES,
  COMPOSED_VIEW_TONES,
  readComposedViewTone,
  readOptionalComposedViewTone,
} from "./composed-view-tones";

/** One tone list for the kit, the older words read as their tone. */
describe("the kit's one tone list", () => {
  test("every tone reads as itself, an older word as its tone, anything else as the fallback", () => {
    for (const tone of COMPOSED_VIEW_TONES) expect(readComposedViewTone(tone)).toBe(tone);
    expect(readComposedViewTone("default")).toBe("neutral");
    expect(readComposedViewTone("positive")).toBe("success");
    expect(readComposedViewTone("negative")).toBe("danger");
    expect(readComposedViewTone("purple", "muted")).toBe("muted");
    expect(readComposedViewTone(undefined)).toBe("neutral");
    expect(readOptionalComposedViewTone("purple")).toBeUndefined();
    expect(readOptionalComposedViewTone("negative")).toBe("danger");
  });

  test("every node's tone prop accepts the tones and the older words (the schema of ComposedViewTone.php)", () => {
    const acceptedWords = [...COMPOSED_VIEW_TONES, ...Object.keys(COMPOSED_VIEW_TONE_ALIASES)];
    for (const nodeType of ["Text", "Badge", "Icon", "KpiTile", "TableCell"]) {
      const toneProp = composedNodeSchema.nodeTypes[nodeType]?.props.find((prop) => prop.name === "tone");
      expect([...(toneProp?.allowedValues ?? [])].sort(), nodeType).toEqual([...acceptedWords].sort());
    }
  });
});
