import { classNames } from "../../../provider/class-names";
import { RangeSlider } from "../../../provider/components";
import { WidgetSurface } from "../../_shared/modern";
import { ModelText } from "../../../kit/ModelText/ModelText";
import type {
  ConceptExplainerStep,
  ConceptExplainerTypeDefinition,
  ConceptExplainerValuesReading,
  ConceptExplainerVariant,
} from "../shared/concept-explainer-type";
import { ConceptExplainerTextView } from "../shared/ConceptExplainerTextView";
import styles from "./ConceptExplainerWidget.module.scss";

/** At most this many steps get a labelled button under the slider; more steps are reached by the slider alone. */
export const MAX_LABELLED_STEPS = 6;

export interface ConceptExplainerWidgetProps {
  definition: ConceptExplainerTypeDefinition;
  /** The model's headline; undefined → the type's default title. */
  modelTitle?: string;
  valuesReading: ConceptExplainerValuesReading;
  steps: ConceptExplainerStep[];
  stepIndex: number;
  onStepIndexChange: (stepIndex: number) => void;
  variants: ConceptExplainerVariant[];
  variantId: string | undefined;
  onVariantIdChange: (variantId: string) => void;
  languageCode: string;
  /** The static render (the report e-mail capture): the picture of one step, no controls. */
  isStatic: boolean;
  testId?: string;
}

/**
 * The ConceptExplainer frame — ChatGPT's LearningViz as the interaction model, our design: one
 * elevated surface with the title, the type's picture on a flat tile, the caption of the current state under it, the
 * variant pills (effect-free, so rounded), the slider over the states with its label and the current state's name,
 * labelled step buttons under the slider, and a footer naming the source and whether the picture carries the
 * business's numbers or is an illustration. Presentational: the outer widget owns the step and the variant.
 */
export const ConceptExplainerWidget = (props: ConceptExplainerWidgetProps) => {
  const {
    definition,
    modelTitle,
    valuesReading,
    steps,
    stepIndex,
    onStepIndexChange,
    variants,
    variantId,
    onVariantIdChange,
    languageCode,
    isStatic,
    testId = "concept-explainer-widget",
  } = props;
  const { Visual } = definition;
  const currentStep = steps[stepIndex] ?? steps[0];
  const lastStepIndex = steps.length - 1;
  const showsStepButtons = !isStatic && steps.length > 1 && steps.length <= MAX_LABELLED_STEPS;
  // Says what the figures are: computed from the block's numbers, or an illustration without them.
  const footerLine = valuesReading.carriesBlockNumbers ? definition.computedLine : definition.illustrationLine;

  return (
    <WidgetSurface className={styles.widget} testId={testId}>
      <header className={styles.header}>
        {modelTitle ? (
          <ModelText
            role="intro"
            weight="bold"
            className={styles.wrappingText}
            testId={`${testId}-title`}
          >
            {modelTitle}
          </ModelText>
        ) : (
          <ConceptExplainerTextView
            text={definition.defaultTitle}
            role="intro"
            weight="bold"
            className={styles.wrappingText}
            testId={`${testId}-title`}
          />
        )}
        <ConceptExplainerTextView
          text={definition.subtitle}
          role="help"
          color="var(--agents-fg-light)"
          className={styles.wrappingText}
        />
      </header>

      <div className={styles.scene} data-testid="concept-explainer-scene">
        <Visual
          values={valuesReading.values}
          carriesBlockNumbers={valuesReading.carriesBlockNumbers}
          steps={steps}
          stepIndex={stepIndex}
          variantId={variantId}
          languageCode={languageCode}
        />
      </div>

      {currentStep ? (
        <div className={styles.caption} aria-live="polite" data-testid="concept-explainer-caption">
          <ConceptExplainerTextView
            text={currentStep.caption}
            role="body"
            weight="bold"
            className={styles.wrappingText}
          />
          <ConceptExplainerTextView
            text={currentStep.description}
            role="help"
            color="var(--agents-fg-light)"
            className={styles.wrappingText}
          />
        </div>
      ) : null}

      {!isStatic && variants.length > 1 && definition.variantLabel ? (
        <div className={styles.controlBlock}>
          <ConceptExplainerTextView
            text={definition.variantLabel}
            role="caption"
            color="var(--agents-fg-light)"
          />
          <div className={styles.pillRow} role="group">
            {variants.map((variant) => (
              <button
                key={variant.id}
                type="button"
                data-testid={`concept-explainer-variant-${variant.id}`}
                aria-pressed={variant.id === variantId}
                className={classNames(styles.pill, variant.id === variantId && styles.pillSelected)}
                onClick={() => onVariantIdChange(variant.id)}
              >
                <ConceptExplainerTextView text={variant.label} role="help" />
              </button>
            ))}
          </div>
        </div>
      ) : null}

      {!isStatic && steps.length > 1 && currentStep ? (
        <div className={styles.controlBlock}>
          <div className={styles.sliderRow}>
            <ConceptExplainerTextView text={definition.controlLabel} role="help" />
            <ConceptExplainerTextView
              text={currentStep.label}
              role="help"
              weight="medium"
              className={styles.sliderValue}
              testId={`${testId}-current-step`}
            />
          </div>
          <div className={styles.sliderTrack} data-testid="concept-explainer-slider">
            <RangeSlider
              value={stepIndex}
              min={0}
              max={lastStepIndex}
              step={1}
              onMove={(movedValue) => {
                if (movedValue !== stepIndex) onStepIndexChange(movedValue);
              }}
            />
          </div>
          {showsStepButtons ? (
            <div className={styles.pillRow} role="group">
              {steps.map((step, index) => (
                <button
                  key={step.id}
                  type="button"
                  data-testid={`concept-explainer-step-${step.id}`}
                  aria-pressed={index === stepIndex}
                  className={classNames(styles.pill, index === stepIndex && styles.pillSelected)}
                  onClick={() => onStepIndexChange(index)}
                >
                  <ConceptExplainerTextView text={step.label} role="help" />
                </button>
              ))}
            </div>
          ) : null}
        </div>
      ) : null}

      <footer className={styles.footer}>
        {definition.sourceLine ? (
          <ConceptExplainerTextView
            text={definition.sourceLine}
            role="caption"
            color="var(--agents-fg-lightest)"
            className={styles.wrappingText}
          />
        ) : null}
        {footerLine ? (
          <ConceptExplainerTextView
            text={footerLine}
            role="caption"
            color="var(--agents-fg-lightest)"
            className={styles.wrappingText}
            testId="concept-explainer-data-line"
          />
        ) : null}
      </footer>
    </WidgetSurface>
  );
};
