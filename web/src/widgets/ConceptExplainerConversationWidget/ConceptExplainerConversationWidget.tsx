import { Text } from "../../provider/components";
import { useMemo, useState } from "react";
import type { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import {
  useReportWidgetRenderStatus,
  type WidgetRenderReportCode,
  type WidgetRenderStatus,
} from "../../renderer/render-report/widget-render-report";
import { useWidgetImageRender } from "../../image-render/WidgetImageRenderContext";
import type {
  ConceptExplainerStep,
  ConceptExplainerTypeDefinition,
  ConceptExplainerValuesReading,
  ConceptExplainerVariant,
} from "./shared/concept-explainer-type";
import { conceptExplainerTypes, findConceptExplainerType } from "./shared/concept-explainer-types";
import { parseConceptExplainerPayload, type ConceptExplainerPayload } from "./shared/concept-explainer-widget-payload";
import { useConceptExplainerLanguage } from "./shared/use-concept-explainer-language";
import { ConceptExplainerWidget } from "./ConceptExplainerWidget/ConceptExplainerWidget";
import styles from "./ConceptExplainerConversationWidget.module.scss";

type ConceptExplainerConversationWidgetProps = PayloadConversationWidgetProps;

interface ConceptExplainerRenderReport {
  status: WidgetRenderStatus;
  code?: WidgetRenderReportCode;
  detail?: string;
}

/** The longest model-written type id a report quotes back. */
const MAX_QUOTED_TYPE_ID_LENGTH = 40;

/** What the block reports to the model: the detail is English, for the model only. */
const buildConceptExplainerRenderReport = (
  payload: ConceptExplainerPayload,
  definition: ConceptExplainerTypeDefinition | undefined,
  valuesReading: ConceptExplainerValuesReading | undefined,
): ConceptExplainerRenderReport => {
  if (payload.explainer === "") {
    return { status: "empty", code: "NO_DATA", detail: "The ConceptExplainer widget names no explainer type." };
  }
  if (!definition) {
    return {
      status: "failed",
      code: "INVALID_PROP",
      detail: `Unknown explainer "${payload.explainer.slice(0, MAX_QUOTED_TYPE_ID_LENGTH)}" — nothing is shown. Known explainers: ${Object.keys(conceptExplainerTypes).sort().join(", ")}.`,
    };
  }
  if (valuesReading && valuesReading.droppedValueProblems.length > 0) {
    return {
      status: "failed",
      code: "INVALID_PROP",
      detail: `Values dropped: ${valuesReading.droppedValueProblems.join("; ")}. The explainer shows an illustration without them.`,
    };
  }
  return { status: "mounted" };
};

/** The step to start at: the payload's `step` by id or index, else the type's static step in e-mail, else the first. */
const readInitialStepIndex = (
  steps: ConceptExplainerStep[],
  payloadStep: string | number | undefined,
  definition: ConceptExplainerTypeDefinition | undefined,
  isStatic: boolean,
): number => {
  if (typeof payloadStep === "number" && payloadStep < steps.length) return payloadStep;
  if (typeof payloadStep === "string") {
    const namedStepIndex = steps.findIndex((step) => step.id.toLowerCase() === payloadStep.toLowerCase());
    if (namedStepIndex >= 0) return namedStepIndex;
  }
  if (isStatic && definition?.emailStepIndex) {
    const emailStepIndex = definition.emailStepIndex(steps);
    if (emailStepIndex >= 0 && emailStepIndex < steps.length) return emailStepIndex;
  }
  return 0;
};

/**
 * The `ConceptExplainer` widget (ChatGPT's LearningViz — as the interaction model): an
 * interactive explanation of how something works for the business, with a slider over the states, a picture per state
 * and a caption. Each explainer TYPE is one `*.concept-explainer.tsx` file in its domain, found by folder glob
 * (`shared/concept-explainer-types.ts`). DATA-COMPLETE and small: the block names the type and, where the type can
 * show the business's own figures, the few numbers it computes from (copied from a read of this conversation) —
 * without them the type shows an illustration that claims no number. The slider and the variants only change local
 * state, so they stay usable on a replay; the e-mail capture shows one step without controls. Reports `empty` for a
 * block without a type, `failed` for an unknown type (nothing shown) or dropped values (the illustration shown).
 */
export const ConceptExplainerConversationWidget = (props: ConceptExplainerConversationWidgetProps) => {
  const { payload } = props;
  const testId = "concept-explainer-widget";
  const isEmailRender = useIsEmailWidgetRender();
  const widgetImageRender = useWidgetImageRender();
  const isStatic = isEmailRender || widgetImageRender !== null;
  const languageCode = useConceptExplainerLanguage();
  const parsedPayload = useMemo(() => parseConceptExplainerPayload(payload), [payload]);
  const definition = parsedPayload.explainer === "" ? undefined : findConceptExplainerType(parsedPayload.explainer);
  const valuesReading = useMemo(() => definition?.readValues(parsedPayload.values), [definition, parsedPayload]);
  const variants = useMemo<ConceptExplainerVariant[]>(
    () =>
      definition && valuesReading
        ? (definition.buildVariants?.(valuesReading.values, valuesReading.carriesBlockNumbers) ?? [])
        : [],
    [definition, valuesReading],
  );
  const [pickedVariantId, setPickedVariantId] = useState<string | undefined>(undefined);
  const variantId = variants.some((variant) => variant.id === pickedVariantId) ? pickedVariantId : variants[0]?.id;
  const steps = useMemo<ConceptExplainerStep[]>(
    () =>
      definition && valuesReading
        ? definition.buildSteps({
            values: valuesReading.values,
            carriesBlockNumbers: valuesReading.carriesBlockNumbers,
            variantId,
            languageCode,
          })
        : [],
    [definition, valuesReading, variantId, languageCode],
  );
  const [pickedStepIndex, setPickedStepIndex] = useState<number | undefined>(undefined);
  const stepIndex = Math.min(
    pickedStepIndex ?? readInitialStepIndex(steps, parsedPayload.step, definition, isStatic),
    Math.max(steps.length - 1, 0),
  );

  const renderReport = buildConceptExplainerRenderReport(parsedPayload, definition, valuesReading);
  useReportWidgetRenderStatus(renderReport.status, renderReport.code, renderReport.detail);

  if (parsedPayload.explainer === "") {
    return (
      <Text
        role="help"
        color="var(--agents-fg-light)"
        className={styles.notice}
        testId={`${testId}-empty`}
      >
        {"No explanation to show."}
      </Text>
    );
  }
  if (!definition || !valuesReading || steps.length === 0) {
    return (
      <Text
        role="help"
        color="var(--agents-fg-light)"
        className={styles.notice}
        testId={`${testId}-error`}
      >
        {"This explanation is not available."}
      </Text>
    );
  }
  return (
    <ConceptExplainerWidget
      definition={definition}
      modelTitle={parsedPayload.title}
      valuesReading={valuesReading}
      steps={steps}
      stepIndex={stepIndex}
      onStepIndexChange={setPickedStepIndex}
      variants={variants}
      variantId={variantId}
      onVariantIdChange={setPickedVariantId}
      languageCode={languageCode}
      isStatic={isStatic}
      testId={testId}
    />
  );
};
