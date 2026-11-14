import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { DataContainerContext, useDataContainer, useListMarker } from "./data-containers";
import { readText } from "./data-value-format";
import { ListEntry } from "./ListEntry/ListEntry";

/**
 * The `ListItem` node: one `List` entry — title, description, meta, value — with the nodes of its
 * slot under the title and those of `trailing` at its end. The nodes inside are no longer "in the list", so a
 * `Repeat` in an entry repeats freely. Outside a `List` it renders the same entry as a standalone row.
 */
const ListItemNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, slots, testId } = props;
  const container = useDataContainer();
  const marker = useListMarker();
  const isInList = container === "list";
  return (
    <ListEntry
      testId={testId}
      marker={isInList ? marker : "none"}
      title={readText(nodeProps.title)}
      description={readText(nodeProps.description)}
      meta={readText(nodeProps.meta)}
      value={readText(nodeProps.value)}
      trailing={
        slots.trailing && slots.trailing.length > 0 ? (
          <DataContainerContext.Provider value={null}>{slots.trailing}</DataContainerContext.Provider>
        ) : undefined
      }
      isStandalone={!isInList}
    >
      {slots.children && slots.children.length > 0 ? (
        <DataContainerContext.Provider value={null}>{slots.children}</DataContainerContext.Provider>
      ) : null}
    </ListEntry>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "ListItem", component: ListItemNode };
