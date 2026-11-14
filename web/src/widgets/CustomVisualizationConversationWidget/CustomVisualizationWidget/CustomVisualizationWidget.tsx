import { Icon, Text } from "../../../provider/components";
import type { ReactNode } from "react";
import { WidgetSurface } from "../../_shared/modern";
import { ModelText } from "../../../kit/ModelText/ModelText";
import type { CustomVisualizationPayload } from "../shared/custom-visualization-widget-payload";
import { CustomVisualizationFrame } from "./CustomVisualizationFrame";
import styles from "./CustomVisualizationWidget.module.scss";

interface CustomVisualizationHeaderProps {
  title: string;
  description: string;
  testId: string;
}

/** The model's title and caption above the frame — business text, shown as written. */
const CustomVisualizationHeader = (props: CustomVisualizationHeaderProps) => {
  const { title, description, testId } = props;
  if (title === "" && description === "") return null;
  return (
    <div className={styles.header}>
      {title !== "" ? (
        <ModelText role="intro" weight="bold" testId={`${testId}-title`}>
          {title}
        </ModelText>
      ) : null}
      {description !== "" ? (
        <ModelText role="caption" color="var(--agents-fg-light)" testId={`${testId}-description`}>
          {description}
        </ModelText>
      ) : null}
    </div>
  );
};

const CustomVisualizationSurface = (props: { children: ReactNode; testId: string }) => {
  const { children, testId } = props;
  return (
    <WidgetSurface className={styles.widget} testId={testId}>
      <div className={styles.content}>{children}</div>
    </WidgetSurface>
  );
};

/** The live widget: the header and the sandboxed frame on one elevated card. */
export const CustomVisualizationWidget = (props: { payload: CustomVisualizationPayload; testId?: string }) => {
  const { payload, testId = "custom-visualization-widget" } = props;
  return (
    <CustomVisualizationSurface testId={testId}>
      <CustomVisualizationHeader title={payload.title} description={payload.description} testId={testId} />
      <CustomVisualizationFrame payload={payload} testId={testId} />
    </CustomVisualizationSurface>
  );
};

/**
 * The e-mail form (in e-mail only a static preview renders): no frame and no script — the header and one
 * flat tile saying the visualization is interactive and lives in the conversation.
 */
export const CustomVisualizationStaticPreview = (props: { title: string; description: string; testId?: string }) => {
  const { title, description, testId = "custom-visualization-widget" } = props;
  return (
    <CustomVisualizationSurface testId={testId}>
      <CustomVisualizationHeader title={title} description={description} testId={testId} />
      <div className={styles.staticPreviewTile} data-testid={`${testId}-static-preview`}>
        <Icon className={styles.staticPreviewIcon} name="barChart" />
        <Text role="help" color="var(--agents-fg-light)">
          {"Interactive visualization — open the conversation to use it."}
        </Text>
      </div>
    </CustomVisualizationSurface>
  );
};
