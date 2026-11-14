import { useId } from "react";
import { TextArea } from "../../../provider/components";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { ComposedInputField } from "./shared/ComposedInputField";
import { ComposedInputFrozenValue } from "./shared/ComposedInputFrozenValue";
import { isComposedInputUsable, readComposedNumber, readComposedText } from "./shared/composed-input-values";
import { useComposedInputValue } from "./shared/use-composed-input-value";

/** The visible lines a textarea may ask for — beyond that it is a document, not a field. */
const MAX_TEXTAREA_ROWS = 12;

const readFieldText = (resolvedValue: ExpressionValue | undefined): string | undefined =>
  typeof resolvedValue === "string" ? resolvedValue : resolvedValue === null ? "" : undefined;

/**
 * `Textarea` — a multi-line text field (`TextareaComposedViewNode.php`; ChatGPT's `Textarea`): what
 * the owner types is written to the tree state through `onChange`; with `maxLength` the field shows a counter and takes
 * no more. It starts at `rows` lines and grows with its text up to {@link MAX_TEXTAREA_ROWS}. The tree state caps a string at its own length budget, so a longer text is cut there. On a replay and in
 * the e-mail capture it shows its current text as plain text, line breaks kept.
 */
const ComposedTextarea = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, isPending, runtime, testId } = props;
  const controlId = useId();
  const label = readComposedText(nodeProps.label);
  const placeholder = readComposedText(nodeProps.placeholder);
  const maxLength = readComposedNumber(nodeProps.maxLength, 0);
  const rows = Math.min(Math.max(Math.round(readComposedNumber(nodeProps.rows, 3)), 1), MAX_TEXTAREA_ROWS);
  const isUsable = isComposedInputUsable(runtime);
  const { value, changeValue } = useComposedInputValue<string>({
    node,
    runtime,
    resolvedValue: nodeProps.value,
    readValue: readFieldText,
    fallbackValue: "",
  });
  const typeText = (nextText: string) => changeValue(maxLength > 0 ? nextText.slice(0, maxLength) : nextText);
  return (
    <ComposedInputField
      label={label}
      controlId={controlId}
      isUsable={isUsable}
      isPending={isPending}
      pendingHeight={24 * rows + 24}
      testId={testId}
    >
      {isUsable ? (
        <TextArea
          id={controlId}
          testId={testId}
          value={value}
          placeholder={placeholder}
          // No fixed `rows`: the field starts at `rows` lines and grows with the text up to the cap, so a drafted
          // e-mail shows whole (ChatGPT's writing block) instead of scrolling inside a short box.
          minRows={rows}
          maxRows={Math.max(rows, MAX_TEXTAREA_ROWS)}
          maxLength={maxLength > 0 ? maxLength : undefined}
          showsCounter={maxLength > 0}
          onChange={typeText}
        />
      ) : (
        <ComposedInputFrozenValue shownValue={value} isMultiline testId={`${testId}-frozen`} />
      )}
    </ComposedInputField>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "Textarea",
  component: ComposedTextarea,
};
