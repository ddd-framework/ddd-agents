import { useId, useState } from "react";
import { TextInput } from "../../../provider/components";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { ComposedInputField } from "./shared/ComposedInputField";
import { ComposedInputFrozenValue } from "./shared/ComposedInputFrozenValue";
import { isComposedInputUsable, readComposedNumber, readComposedText } from "./shared/composed-input-values";
import { useComposedInputValue } from "./shared/use-composed-input-value";

const readFieldText = (resolvedValue: ExpressionValue | undefined): string | undefined => {
  if (typeof resolvedValue === "string") return resolvedValue;
  if (typeof resolvedValue === "number" && Number.isFinite(resolvedValue)) return String(resolvedValue);
  if (resolvedValue === null) return "";
  return undefined;
};

/** The typed text as the state value: a number field writes a finite number, or null while it is empty or unparsable. */
const toStateValue = (typedText: string, isNumberField: boolean): string | number | null => {
  if (!isNumberField) return typedText;
  const trimmedText = typedText.trim().replace(",", ".");
  if (trimmedText === "") return null;
  const typedNumber = Number(trimmedText);
  return Number.isFinite(typedNumber) ? typedNumber : null;
};

/**
 * `Input` — a one-line text or number field (`InputComposedViewNode.php`; ChatGPT's `Input`): what
 * the owner types is written to the tree state through `onChange` on every keystroke, so a computed result follows. A
 * `number` field writes a number (null while empty). The browser checks nothing beyond the type; the tree computes with
 * what it gets. On a replay and in the e-mail capture it shows its current text as plain text.
 */
const ComposedInput = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, isPending, runtime, testId } = props;
  const controlId = useId();
  const inputType = nodeProps.inputType === "number" || nodeProps.inputType === "email" ? nodeProps.inputType : "text";
  const isNumberField = inputType === "number";
  const label = readComposedText(nodeProps.label);
  const placeholder = readComposedText(nodeProps.placeholder);
  const maxLength = readComposedNumber(nodeProps.maxLength, 0);
  const isUsable = isComposedInputUsable(runtime);
  const { value, changeValue } = useComposedInputValue<string>({
    node,
    runtime,
    resolvedValue: nodeProps.value,
    readValue: readFieldText,
    fallbackValue: "",
    toEventValue: (typedText) => toStateValue(typedText, isNumberField),
  });
  // The text as typed survives a state that holds the same number ("3." and "3,0" stay on screen while the state is 3).
  const [typedText, setTypedText] = useState<string | undefined>(undefined);
  const shownText =
    typedText !== undefined && toStateValue(typedText, isNumberField) === toStateValue(value, isNumberField)
      ? typedText
      : value;
  const typeText = (nextText: string) => {
    const limitedText = maxLength > 0 ? nextText.slice(0, maxLength) : nextText;
    setTypedText(limitedText);
    changeValue(limitedText);
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
        <TextInput
          id={controlId}
          testId={testId}
          type={inputType}
          value={shownText}
          placeholder={placeholder}
          maxLength={maxLength > 0 ? maxLength : undefined}
          onChange={typeText}
        />
      ) : (
        <ComposedInputFrozenValue shownValue={shownText} testId={`${testId}-frozen`} />
      )}
    </ComposedInputField>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Input", component: ComposedInput };
