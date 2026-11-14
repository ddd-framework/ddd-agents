import { useMemo } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { EmbeddedConversationWidget } from "./EmbeddedConversationWidget";

/**
 * The core `Widget` node (`WidgetComposedViewNode.php`): an existing domain widget inside the tree,
 * rendered through {@link EmbeddedConversationWidget} with the tree's per-slot context. The node's `fields` become the
 * widget's `payload`, exactly as its own fence would carry them next to `type`. The widget reports into the tree at this
 * node's path (`runtime.reportNodeStatus`), never into the host block's reporter.
 */
const WidgetNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, runtime } = props;
  const fields = useMemo(
    () =>
      typeof nodeProps.fields === "object" && nodeProps.fields !== null && !Array.isArray(nodeProps.fields)
        ? (nodeProps.fields as Record<string, unknown>)
        : {},
    [nodeProps.fields],
  );
  return (
    <EmbeddedConversationWidget
      widgetName={typeof nodeProps.widget === "string" ? nodeProps.widget : ""}
      fields={fields}
      agentSpaceKey={runtime.agentSpaceKey}
      interactive={runtime.interactive}
      messageId={runtime.messageId}
      reportStatus={runtime.reportNodeStatus}
    />
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Widget", component: WidgetNode };
