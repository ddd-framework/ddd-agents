import { visit } from "unist-util-visit";

interface DraftMdastNode {
  type: string;
  value?: string;
  children?: DraftMdastNode[];
}

/**
 * A single line break in a draft is a line break on screen (a signature "Viele Grüße\nAnna Schmidt", an address block)
 * — in plain Markdown it would join the two lines into one. Splits every text node at its newlines into text and
 * `break` nodes, the way `remark-breaks` does (not a dependency of the app); code is left alone. A blank line stays a
 * new paragraph, as in Markdown.
 */
export const remarkDraftLineBreaks = () => (tree: DraftMdastNode) => {
  visit(tree, "text", (node: DraftMdastNode, index: number | undefined, parent: DraftMdastNode | undefined) => {
    if (!parent?.children || index === undefined || typeof node.value !== "string" || !node.value.includes("\n")) {
      return;
    }
    const lineNodes: DraftMdastNode[] = [];
    node.value.split("\n").forEach((line, lineIndex) => {
      if (lineIndex > 0) lineNodes.push({ type: "break" });
      if (line !== "") lineNodes.push({ type: "text", value: line });
    });
    parent.children.splice(index, 1, ...lineNodes);
    return index + lineNodes.length;
  });
};
