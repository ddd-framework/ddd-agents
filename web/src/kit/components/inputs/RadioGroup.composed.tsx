import { RadioGroup, Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { useId } from "react";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { ComposedInputField } from "./shared/ComposedInputField";
import {
  findComposedInputOption,
  isComposedInputUsable,
  readComposedInputOptions,
  readComposedText,
} from "./shared/composed-input-values";
import { useComposedInputValue } from "./shared/use-composed-input-value";
import { useReportMissingOptions } from "./shared/use-report-missing-options";
import styles from "./ComposedInputs.module.scss";

const readChoiceId = (resolvedValue: ExpressionValue | undefined): string | undefined =>
  typeof resolvedValue === "string" || typeof resolvedValue === "number" ? String(resolvedValue) : undefined;

/**
 * `RadioGroup` — radio buttons (`RadioGroupComposedViewNode.php`; ChatGPT's `RadioGroup`): one of a
 * few choices that each carry a visible explanation (`description` under the label), the picked one written to the
 * tree state through `onChange`. Under each other by default, side by side with `direction: "row"`. Frozen on a replay
 * and in the e-mail capture.
 */
const ComposedRadioGroup = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, isPending, runtime, testId } = props;
  const groupName = useId();
  const options = readComposedInputOptions(nodeProps.options);
  const label = readComposedText(nodeProps.label);
  const direction = nodeProps.direction === "row" ? "row" : "column";
  const { value, changeValue } = useComposedInputValue<string | undefined>({
    node,
    runtime,
    resolvedValue: nodeProps.value,
    readValue: readChoiceId,
    fallbackValue: undefined,
  });
  useReportMissingOptions(options.length, "RadioGroup", isPending, runtime);
  if (!isPending && options.length === 0) return null;
  const selectedOption = findComposedInputOption(options, value);
  const radioOptions = options.map((option) => ({
    // The label and its explanation as one node.
    label: (
      <span className={styles.radioOptionText} data-testid={`${testId}-option-${option.id}`}>
        <Text role="body" translate={false}>
          {escapePlainTextForText(option.label)}
        </Text>
        {option.description ? (
          <Text role="caption" color="var(--agents-fg-light)" translate={false}>
            {escapePlainTextForText(option.description)}
          </Text>
        ) : null}
      </span>
    ),
    value: String(option.id),
    disabled: option.disabled,
  }));
  const selectOption = (selectedRadioValue: unknown) => {
    const pickedOption = options.find((option) => String(option.id) === String(selectedRadioValue));
    if (pickedOption) changeValue(String(pickedOption.id));
  };
  return (
    <ComposedInputField
      label={label}
      isUsable={isComposedInputUsable(runtime)}
      isPending={isPending}
      pendingHeight={72}
      testId={testId}
    >
      <div role="radiogroup" aria-label={label} data-testid={testId}>
        <RadioGroup
          name={groupName}
          value={selectedOption ? String(selectedOption.id) : ""}
          options={radioOptions}
          direction={direction}
          onChange={selectOption}
        />
      </div>
    </ComposedInputField>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "RadioGroup",
  component: ComposedRadioGroup,
};
