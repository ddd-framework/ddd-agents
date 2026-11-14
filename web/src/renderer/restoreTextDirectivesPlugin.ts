import { visit } from "unist-util-visit";

/**
 * remark-directive parses EVERY `:name` run as a text directive — including the `:00` / `:30` inside a time such as
 * `08:00–16:30` (digits are valid directive names). Text directives are not part of our widget vocabulary (widgets
 * travel as leaf directives only, see `conversationWidgetAstPlugin`), so a text directive is always prose that the
 * parser misread: without this pass the customer chat rendered "Montag: 08:00–16:30" as "08" / "–16" (the `:00`
 * and `:30` swallowed, the unknown nodes rendered as breaks) while the admin inspector, which does not load
 * remark-directive, showed the line intact.
 *
 * Restores every text directive to the exact source slice it was parsed from, merging it into the neighbouring text
 * nodes so the paragraph reads as one run again.
 */
export default function restoreTextDirectivesPlugin() {
  return (tree: any, file: any) => {
    const source = String(file?.value ?? "");
    visit(tree, "textDirective", (node: any, index: number | undefined, parent: any) => {
      if (index === undefined || index === null || !parent) {
        return;
      }
      const start = node.position?.start?.offset;
      const end = node.position?.end?.offset;
      const literal =
        typeof start === "number" && typeof end === "number" && end > start
          ? source.slice(start, end)
          : `:${node.name}${node.children?.length ? `[${node.children.map((c: any) => c.value ?? "").join("")}]` : ""}`;
      const asText = { type: "text", value: literal };
      parent.children.splice(index, 1, asText);
      // Merge with the neighbours so "08" + ":00" + "–16" becomes one text node again.
      const prev = parent.children[index - 1];
      if (prev?.type === "text") {
        prev.value += asText.value;
        parent.children.splice(index, 1);
        const next = parent.children[index];
        if (next?.type === "text") {
          prev.value += next.value;
          parent.children.splice(index, 1);
        }
        return index - 1;
      }
      const next = parent.children[index + 1];
      if (next?.type === "text") {
        asText.value += next.value;
        parent.children.splice(index + 1, 1);
      }
      return index;
    });
  };
}
