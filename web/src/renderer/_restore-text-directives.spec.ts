import { describe, expect, it } from "vitest";
import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import restoreTextDirectivesPlugin from "./restoreTextDirectivesPlugin";

const paragraphTexts = (markdown: string): string[] => {
  const processor = unified().use(remarkParse).use(remarkDirective).use(restoreTextDirectivesPlugin);
  const tree = processor.runSync(processor.parse(markdown), { value: markdown } as any);
  const texts: string[] = [];
  visit(tree, "paragraph", (node: any) => {
    texts.push(node.children.map((child: any) => (child.type === "text" ? child.value : `<${child.type}>`)).join(""));
  });
  return texts;
};

describe("restoreTextDirectivesPlugin", () => {
  it("keeps opening hours intact — the :00 and :30 of a time are not directives", () => {
    expect(paragraphTexts("- Montag: 08:00–16:30\n- Dienstag: 08:00–12:00 und 14:00–19:00")).toEqual([
      "Montag: 08:00–16:30",
      "Dienstag: 08:00–12:00 und 14:00–19:00",
    ]);
  });

  it("restores a word-like text directive verbatim, label included", () => {
    expect(paragraphTexts("Treffpunkt :abc[dort] um 9:15 Uhr")).toEqual(["Treffpunkt :abc[dort] um 9:15 Uhr"]);
  });

  it("leaves leaf directives (the widget form) untouched", () => {
    const markdown = "Hier:\n\n::ExampleWidget{step=1}\n";
    const processor = unified().use(remarkParse).use(remarkDirective).use(restoreTextDirectivesPlugin);
    const tree = processor.runSync(processor.parse(markdown), { value: markdown } as any);
    let leafDirectives = 0;
    visit(tree, "leafDirective", () => {
      leafDirectives += 1;
    });
    expect(leafDirectives).toBe(1);
  });
});
