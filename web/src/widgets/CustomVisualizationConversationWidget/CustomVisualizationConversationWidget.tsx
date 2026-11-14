import { Text } from "../../provider/components";
import { useMemo } from "react";
import type { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import { useWidgetImageRender } from "../../image-render/WidgetImageRenderContext";
import {
  useReportWidgetRenderStatus,
  type WidgetRenderReportCode,
} from "../../renderer/render-report/widget-render-report";
import {
  parseCustomVisualizationPayload,
  type CustomVisualizationPayloadProblem,
} from "./shared/custom-visualization-widget-payload";
import {
  CustomVisualizationStaticPreview,
  CustomVisualizationWidget,
} from "./CustomVisualizationWidget/CustomVisualizationWidget";

type CustomVisualizationConversationWidgetProps = PayloadConversationWidgetProps;

/** What each payload problem reports. */
const PROBLEM_REPORT_CODES: Readonly<Record<CustomVisualizationPayloadProblem["code"], WidgetRenderReportCode>> = {
  NO_CONTENT: "INVALID_PROP",
  UNKNOWN_LIBRARY: "INVALID_PROP",
  SOURCE_TOO_LARGE: "BUDGET_EXCEEDED",
};

/** A block the widget does not run: one muted line, and the reason for the model. */
const CustomVisualizationNotRun = (props: { problem: CustomVisualizationPayloadProblem; testId: string }) => {
  const { problem, testId } = props;
  useReportWidgetRenderStatus("failed", PROBLEM_REPORT_CODES[problem.code], problem.detail);
  return (
    <Text role="help" color="var(--agents-fg-light)" testId={`${testId}-error`}>
      {"This visualization could not be shown."}
    </Text>
  );
};

const CustomVisualizationEmailPreview = (props: { title: string; description: string; testId: string }) => {
  const { title, description, testId } = props;
  useReportWidgetRenderStatus("mounted");
  return <CustomVisualizationStaticPreview title={title} description={description} testId={testId} />;
};

/**
 * A visualization the model wrote itself — its own HTML, CSS and JavaScript with the data in the block. DATA-COMPLETE and sandboxed: the code runs in an iframe without same-origin rights
 * under a policy that loads nothing from the network; only allow-listed libraries come along. For what no kit node of
 * `ComposedView` can express (a canvas animation, a custom projection) — never for a table, a chart or a map.
 * Reports `pending` until the script ran, `mounted` then, `failed` for a block it does not run, a script error or a
 * frame that stopped responding. In the e-mail it renders a static preview without the frame.
 */
export const CustomVisualizationConversationWidget = (props: CustomVisualizationConversationWidgetProps) => {
  const { payload } = props;
  const testId = "custom-visualization-widget";
  const parsedPayload = useMemo(() => parseCustomVisualizationPayload(payload), [payload]);
  const isEmailRender = useIsEmailWidgetRender();
  const widgetImageCapture = useWidgetImageRender();

  if (!parsedPayload.isValid) return <CustomVisualizationNotRun problem={parsedPayload.problem} testId={testId} />;
  if (isEmailRender || widgetImageCapture !== null) {
    return (
      <CustomVisualizationEmailPreview
        title={parsedPayload.payload.title}
        description={parsedPayload.payload.description}
        testId={testId}
      />
    );
  }
  // A new payload (an edited message) is a new instance: a new frame and a new token.
  return <CustomVisualizationWidget key={payload} payload={parsedPayload.payload} testId={testId} />;
};
