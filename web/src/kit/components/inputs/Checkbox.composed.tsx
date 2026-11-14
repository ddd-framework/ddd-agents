import { escapePlainTextForText } from "../../../provider/environment";
import { Checkbox } from "../../../provider/components";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { ComposedInputField } from "./shared/ComposedInputField";
import { isComposedInputUsable, readComposedText } from "./shared/composed-input-values";
import { useComposedInputValue } from "./shared/use-composed-input-value";

const readChecked = (resolvedValue: ExpressionValue | undefined): boolean | undefined =>
  typeof resolvedValue === "boolean" ? resolvedValue : undefined;

/**
 * `Checkbox` — one option on or off (`CheckboxComposedViewNode.php`; ChatGPT's "show the comparison
 * values" of the analytics studio): writes `true` / `false` to the tree state through `onChange`. The label is the
 * model's text, an optional muted line explains it. Frozen on a replay and in the e-mail capture.
 */
const ComposedCheckbox = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, isPending, runtime, testId } = props;
  const label = readComposedText(nodeProps.label) ?? "";
  const description = readComposedText(nodeProps.description);
  const isUsable = isComposedInputUsable(runtime);
  const { value: isChecked, changeValue } = useComposedInputValue<boolean>({
    node,
    runtime,
    resolvedValue: nodeProps.checked,
    readValue: readChecked,
    fallbackValue: false,
    valuePropName: "checked",
  });
  return (
    <ComposedInputField
      description={description}
      isUsable={isUsable}
      isPending={isPending}
      pendingHeight={24}
      testId={testId}
    >
      <Checkbox
        testId={testId}
        label={escapePlainTextForText(label)}
        translate={false}
        checked={isChecked}
        breaksLongWords
        onChange={() => changeValue(!isChecked)}
      />
    </ComposedInputField>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "Checkbox",
  component: ComposedCheckbox,
};
