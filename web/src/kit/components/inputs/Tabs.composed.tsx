import { Tabs, Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
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

const readChoiceId = (resolvedValue: ExpressionValue | undefined): string | number | undefined =>
  typeof resolvedValue === "string" || typeof resolvedValue === "number" ? resolvedValue : undefined;

/**
 * `Tabs` — the segmented control (`TabsComposedViewNode.php`; ChatGPT's 7/30-day toggle of the
 * analytics dashboard, the Bear/Base/Bull scenarios of the MRR simulator): a few short choices side by side, the picked
 * one written to the tree state through `onChange`. Labels are the model's text. A disabled choice is not shown. Frozen on a replay and in the e-mail capture.
 */
const ComposedTabs = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, isPending, runtime, testId } = props;
  const options = readComposedInputOptions(nodeProps.options);
  const label = readComposedText(nodeProps.label);
  const { value, changeValue } = useComposedInputValue<string | number | undefined>({
    node,
    runtime,
    resolvedValue: nodeProps.value,
    readValue: readChoiceId,
    fallbackValue: options[0]?.id,
  });
  useReportMissingOptions(options.length, "Tabs", isPending, runtime);
  if (!isPending && options.length === 0) return null;
  const selectedOption = findComposedInputOption(options, value) ?? options[0];
  const tabs = options.map((option) => ({
    value: String(option.id),
    disabled: option.disabled,
    testId: `${testId}-option-${option.id}`,
    label: <Text translate={false}>{escapePlainTextForText(option.label)}</Text>,
  }));
  const selectOption = (selectedTabValue: unknown) => {
    const pickedOption = options.find((option) => String(option.id) === String(selectedTabValue));
    if (pickedOption && String(pickedOption.id) !== String(selectedOption?.id)) changeValue(pickedOption.id);
  };
  return (
    <ComposedInputField
      label={label}
      isUsable={isComposedInputUsable(runtime)}
      isPending={isPending}
      pendingHeight={36}
      testId={testId}
    >
      <div className={styles.tabsBar} data-testid={testId}>
        <Tabs
          options={tabs}
          value={selectedOption ? String(selectedOption.id) : undefined}
          onChange={selectOption}
          ariaLabel={label}
        />
      </div>
    </ComposedInputField>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Tabs", component: ComposedTabs };
