import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { AGENTS_ICON_NAMES } from "./icon-names";

/**
 * The package default draws every icon name of the contract: a name without a glyph falls back to a dot ("•"), which
 * reads as a broken icon in a button.
 */
describe("default icon glyphs", () => {
  const stylesheet = readFileSync(path.resolve(__dirname, "../../styles/_default-components.scss"), "utf8");
  const glyphMap = stylesheet.slice(stylesheet.indexOf("$agents-icon-glyphs: ("), stylesheet.indexOf(");", stylesheet.indexOf("$agents-icon-glyphs: (")));
  const namesWithGlyph = new Set([...glyphMap.matchAll(/^\s*"([a-zA-Z]+)":/gm)].map((match) => match[1]));

  it("has a glyph for every icon name", () => {
    expect(AGENTS_ICON_NAMES.filter((iconName) => !namesWithGlyph.has(iconName))).toEqual([]);
  });
});
