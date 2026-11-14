import { useEffect, useMemo } from "react";
import { conversationWidgetRegistry } from "../../../renderer/conversationWidgetRegistry";
import { resolveWidgetName } from "../../../renderer/widget-name-resolver";
import { ConversationWidgetErrorBoundary } from "../../../renderer/ConversationWidgetErrorBoundary";
import {
  buildWidgetAgentSpaceKeyProps,
  readWidgetAgentSpaceKeyAttribute,
} from "../../../renderer/_agent-space/widget-agent-space-key-attribute";
import { ConversationWidgetFeatureGate } from "../../../renderer/_access/ConversationWidgetFeatureGate";
import {
  WidgetRenderReportCode,
  WidgetRenderReportContext,
  WidgetRenderReporter,
  WidgetRenderStatus,
} from "../../../renderer/render-report/widget-render-report";

export interface EmbeddedConversationWidgetProps {
  /** The wire name as written (a legacy `…Directive` name resolves as everywhere). */
  widgetName: string;
  /** The widget's own fields — its `payload`, exactly as its own fence would carry them next to `type`. */
  fields: Record<string, unknown>;
  /** The key of the tree's agent space; the key in the fields wins. */
  agentSpaceKey?: string | number;
  interactive: boolean;
  messageId?: string | number;
  /**
   * Where the widget's own render report goes — the tree's report at the node's path, never the host block's reporter
   * (an embedded widget and the tree would otherwise overwrite each other's report).
   */
  reportStatus: (status: WidgetRenderStatus, code?: WidgetRenderReportCode, detail?: string) => void;
}

/**
 * A domain widget rendered inside a `ComposedView` — as a `Widget` node in the tree, or as a widget the readers LIFTED
 * out of it and show after the view. It renders the registered component with the tree's
 * context: `interactive` and `messageId` of the host message, the agent space, and `justDelivered = false`, so it never
 * auto-opens a canvas (the owner opens it by clicking). It gets its OWN render-report context, which forwards its
 * statuses into the tree's report, names the widget in the detail, and turns a crash (`RENDER_FAILED`) or an unknown
 * name (`INVALID_NODE`) into a report instead of a silent nothing. The registry's feature gate applies as it does to
 * the widget's own fence. The registry is read at render time, never at module load (the registry imports this kit).
 */
export const EmbeddedConversationWidget = (props: EmbeddedConversationWidgetProps) => {
  const { widgetName, fields, agentSpaceKey, interactive, messageId, reportStatus } = props;
  const resolvedWidgetName = resolveWidgetName(widgetName);
  const entry = resolvedWidgetName
    ? conversationWidgetRegistry.widgets.find((widget) => widget.name === resolvedWidgetName)
    : undefined;
  const reporter = useMemo<WidgetRenderReporter>(
    () => ({
      report: (reportEntry) =>
        reportStatus(
          reportEntry.status,
          reportEntry.code,
          reportEntry.detail ? `${resolvedWidgetName ?? widgetName}: ${reportEntry.detail}` : undefined,
        ),
    }),
    [reportStatus, resolvedWidgetName, widgetName],
  );
  const payload = useMemo(() => JSON.stringify(fields), [fields]);

  useEffect(() => {
    if (!entry) reportStatus("failed", "INVALID_NODE", `no registered widget "${widgetName}"`);
  }, [entry, reportStatus, widgetName]);

  if (!entry) return null;
  const EmbeddedComponent = entry.component;
  const fieldAgentSpaceKey = readWidgetAgentSpaceKeyAttribute(fields) ?? agentSpaceKey;
  const embeddedAgentSpaceKey =
    typeof fieldAgentSpaceKey === "string" || typeof fieldAgentSpaceKey === "number"
      ? String(fieldAgentSpaceKey)
      : undefined;
  const embeddedWidget = (
    <WidgetRenderReportContext.Provider value={reporter}>
      <ConversationWidgetErrorBoundary
        widgetName={entry.name}
        onCrash={() => reportStatus("failed", "RENDER_FAILED", `the embedded ${entry.name} threw`)}
      >
        <EmbeddedComponent
          payload={payload}
          {...buildWidgetAgentSpaceKeyProps(embeddedAgentSpaceKey)}
          interactive={interactive}
          fresh={interactive}
          justDelivered={false}
          messageId={messageId}
        />
      </ConversationWidgetErrorBoundary>
    </WidgetRenderReportContext.Provider>
  );
  // The registry's feature gate holds inside a tree exactly as for the widget's own fence.
  if (!entry.featureFlag) return embeddedWidget;
  return (
    <ConversationWidgetFeatureGate
      featureFlag={entry.featureFlag}
      UpsellTrigger={entry.upsellTrigger}
      agentSpaceKeyAttribute={embeddedAgentSpaceKey}
    >
      {embeddedWidget}
    </ConversationWidgetFeatureGate>
  );
};
