import type { ComponentType } from "react";

/**
 * The contract of one ConceptExplainer TYPE. The `ConceptExplainer` widget is a frame — title,
 * the type's picture, the caption of the current state, a slider over the states — and every explainer type is one
 * file `*.concept-explainer.tsx` in the app's domain folder that owns its content, exporting `conceptExplainerType`;
 * the app registers them (`registerConceptExplainerTypes`), and the backend declares each type in a class of its own
 * — no list to edit.
 */

/** The numbers and words a type reads from the block's `values` (after its own alias-tolerant reading). */
export type ConceptExplainerValues = Readonly<Record<string, number | string>>;

/**
 * A text the frame renders through the component library's `Text`: `key` is a translation key (the English source string), never
 * model text — a model-written word reaches a text only as a `replacements` value the type escaped itself.
 */
export interface ConceptExplainerText {
  key: string;
  replacements?: Readonly<Record<string, string | number>>;
  /** Singular / plural of one key (`"one"` default, `"other"` = the key's MANY value). */
  context?: "one" | "other";
}

/** One state of the explainer — one position of the slider. */
export interface ConceptExplainerStep {
  /** Stable id (the payload's `step` may name it to start there). */
  id: string;
  /** Short label of the slider position ("Reviews", "+5"). */
  label: ConceptExplainerText;
  /** The state's name, shown large under the picture (ChatGPT's "New moon"). */
  caption: ConceptExplainerText;
  /** One or two sentences: what changes in this state and why. */
  description: ConceptExplainerText;
}

/** One alternative the owner can switch between beside the slider (the star rating of the new reviews). */
export interface ConceptExplainerVariant {
  id: string;
  label: ConceptExplainerText;
}

/** The values as read: the kept values, the problems of the dropped ones, and whether real numbers are present. */
export interface ConceptExplainerValuesReading {
  values: ConceptExplainerValues;
  /** One short English line per dropped value ("currentAverage 7 is not between 1 and 5") — reported to the model. */
  droppedValueProblems: string[];
  /**
   * False = the picture is an ILLUSTRATION without the business's numbers: the frame says so under the picture, and
   * the type shows no figure it would otherwise have to invent.
   */
  carriesBlockNumbers: boolean;
}

export interface ConceptExplainerVisualProps {
  values: ConceptExplainerValues;
  carriesBlockNumbers: boolean;
  steps: ConceptExplainerStep[];
  stepIndex: number;
  variantId: string | undefined;
  /** The owner's language (`de`, `en-GB`) for number formatting. */
  languageCode: string;
}

export interface ConceptExplainerStepsInput {
  values: ConceptExplainerValues;
  carriesBlockNumbers: boolean;
  variantId: string | undefined;
  languageCode: string;
}

export interface ConceptExplainerTypeDefinition {
  /** The wire id the block names in `explainer` (lower camel, e.g. `rankingFactors`) = the backend `TYPE_ID`. */
  typeId: string;
  /** The title when the block brings none. */
  defaultTitle: ConceptExplainerText;
  /** The sub-line under the title. */
  subtitle: ConceptExplainerText;
  /** The slider's label ("Step", "New reviews"). */
  controlLabel: ConceptExplainerText;
  /** The label of the variant row, when the type has variants. */
  variantLabel?: ConceptExplainerText;
  /** Where the explanation comes from; one line in the footer. */
  sourceLine?: ConceptExplainerText;
  /** The footer line when the picture carries no numbers of the business. */
  illustrationLine: ConceptExplainerText;
  /** The footer line when it does (how the figures were derived). */
  computedLine?: ConceptExplainerText;
  /** Reads the block's `values` — alias-tolerant, never throws. */
  readValues: (rawValues: Readonly<Record<string, unknown>>) => ConceptExplainerValuesReading;
  /** The variants in display order, the first picked at the start; none = no variant row. */
  buildVariants?: (values: ConceptExplainerValues, carriesBlockNumbers: boolean) => ConceptExplainerVariant[];
  buildSteps: (stepsInput: ConceptExplainerStepsInput) => ConceptExplainerStep[];
  /** The step a static render (the report e-mail) shows; default the first. */
  emailStepIndex?: (steps: ConceptExplainerStep[]) => number;
  /** The picture of the current state. */
  Visual: ComponentType<ConceptExplainerVisualProps>;
}
