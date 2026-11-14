import { useId } from "react";
import { Select } from "../../../provider/components";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { ComposedInputField } from "./shared/ComposedInputField";
import { ComposedInputFrozenValue } from "./shared/ComposedInputFrozenValue";
import {
  findComposedInputOption,
  isComposedInputUsable,
  readComposedInputOptions,
  readComposedText,
} from "./shared/composed-input-values";
import { useComposedInputValue } from "./shared/use-composed-input-value";
import { useReportMissingOptions } from "./shared/use-report-missing-options";

const readChoiceId = (resolvedValue: ExpressionValue | undefined): string | number | undefined =>
  typeof resolvedValue === "string" || typeof resolvedValue === "number" ? resolvedValue : undefined;

/**
 * `Select` — a drop-down (`SelectComposedViewNode.php`; ChatGPT's channel select of the analytics
 * studio): one of a longer list of choices, the picked one written to the tree state through `onChange`. The option
 * texts are the model's (not translated). On a replay and in the e-mail capture it shows its current choice as plain
 * text.
 */
const ComposedSelect = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, isPending, runtime, testId } = props;
  const controlId = useId();
  const options = readComposedInputOptions(nodeProps.options);
  const label = readComposedText(nodeProps.label);
  const placeholder = readComposedText(nodeProps.placeholder);
  const { value, changeValue } = useComposedInputValue<string | number | undefined>({
    node,
    runtime,
    resolvedValue: nodeProps.value,
    readValue: readChoiceId,
    fallbackValue: undefined,
  });
  useReportMissingOptions(options.length, "Select", isPending, runtime);
  if (!isPending && options.length === 0) return null;
  const selectedOption = findComposedInputOption(options, value);
  const isUsable = isComposedInputUsable(runtime);
  // The option texts are the model's, shown as written.
  const selectOptions = options.map((option) => ({
    value: String(option.id),
    label: option.label,
    description: option.description,
    disabled: option.disabled,
  }));
  const selectOption = (selectedKey: string) => {
    const pickedOption = options.find((option) => String(option.id) === selectedKey);
    if (pickedOption) changeValue(pickedOption.id);
  };
  return (
    <ComposedInputField
      label={label}
      controlId={controlId}
      isUsable={isUsable}
      isPending={isPending}
      pendingHeight={48}
      testId={testId}
    >
      {isUsable ? (
        <Select
          id={controlId}
          testId={testId}
          options={selectOptions}
          value={selectedOption ? String(selectedOption.id) : ""}
          placeholder={placeholder}
          onChange={selectOption}
        />
      ) : (
        <ComposedInputFrozenValue shownValue={selectedOption?.label} testId={`${testId}-frozen`} />
      )}
    </ComposedInputField>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Select", component: ComposedSelect };
