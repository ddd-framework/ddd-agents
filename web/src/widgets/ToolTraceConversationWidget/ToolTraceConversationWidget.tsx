import { Icon, Text } from "../../provider/components";
import { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import React, { useMemo } from "react";

type ToolTraceConversationWidgetProps = PayloadConversationWidgetProps;

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);

interface TracePayload {
  name?: unknown;
  input?: unknown;
}

const parsePayload = (raw: string): TracePayload | null => {
  try {
    const parsed = JSON.parse(raw) as unknown;
    return isRecord(parsed) ? (parsed as TracePayload) : null;
  } catch {
    return null;
  }
};

/**
 * Humanizes the last segment of a nav key.
 * "NAV.PROJECT.EXAMPLE.TASKS" → "Example Tasks"
 */
const humanizeNavKey = (key: string): string => {
  const lastSegment = key.split(".").pop() ?? key;
  return lastSegment
    .toLowerCase()
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
};

const resolveLabel = (payload: TracePayload): string => {
  if (payload.name === "Navigate" && isRecord(payload.input)) {
    const destination = payload.input.destination;
    if (typeof destination === "string" && destination.length > 0) {
      return humanizeNavKey(destination);
    }
  }
  const toolName = typeof payload.name === "string" ? payload.name : "Tool";
  return toolName;
};

/**
 * Renders a muted single-line trace for a tool_use assistant part.
 * Renders both on live delivery and history replay — never executes.
 */
export const ToolTraceConversationWidget = (props: ToolTraceConversationWidgetProps) => {
  const { payload } = props;

  const label = useMemo(() => {
    const parsed = parsePayload(payload);
    if (!parsed) return null;
    return resolveLabel(parsed);
  }, [payload]);

  useReportWidgetRenderStatus(
    label ? "mounted" : "failed",
    label ? undefined : "INVALID_PROP",
    label ? undefined : "The ToolTrace payload is not a JSON object.",
  );

  if (!label) return null;

  return (
    <span
      data-testid="tool-trace-widget"
      style={{
        display: "inline-flex",
        alignItems: "center",
        gap: "4px",
        fontSize: "12px",
        color: "var(--agents-text-subtle, #8a8a8a)",
        fontStyle: "italic",
      }}
    >
      <Icon name="arrowRight" />
      <Text replacements={{ label }} testId="tool-trace-widget-label">
        {"Opened %label%"}
      </Text>
    </span>
  );
};
