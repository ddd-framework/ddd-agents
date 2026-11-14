import { DateInput, Icon } from "../../../provider/components";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { ComposedInputField } from "./shared/ComposedInputField";
import { ComposedInputFrozenValue } from "./shared/ComposedInputFrozenValue";
import {
  formatIsoDay,
  formatIsoDayForReading,
  isComposedInputUsable,
  parseIsoDay,
  readComposedText,
  toDatePatternLocale,
} from "./shared/composed-input-values";
import { useComposedInputDateLocale } from "./shared/use-composed-input-language";
import { useComposedInputValue } from "./shared/use-composed-input-value";
import styles from "./ComposedInputs.module.scss";

const readIsoDay = (resolvedValue: ExpressionValue | undefined): string | undefined =>
  parseIsoDay(resolvedValue) === undefined ? undefined : String(resolvedValue).trim();

/**
 * `DatePicker` — one day from a calendar (`DatePickerComposedViewNode.php`; the reference date of
 * ChatGPT's analytics studio): shown in the owner's date format (the field and the frozen value in one pattern), written to the tree state as `YYYY-MM-DD` through
 * `onChange`; `min` / `max` (also `YYYY-MM-DD`) bound the calendar. A value that is no valid day shows an empty field.
 * On a replay and in the e-mail capture it shows its current day as plain text.
 */
const ComposedDatePicker = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, isPending, runtime, testId } = props;
  const dateLocale = useComposedInputDateLocale();
  const label = readComposedText(nodeProps.label);
  const minDay = parseIsoDay(nodeProps.min);
  const maxDay = parseIsoDay(nodeProps.max);
  const isUsable = isComposedInputUsable(runtime);
  const { value, changeValue } = useComposedInputValue<string | undefined>({
    node,
    runtime,
    resolvedValue: nodeProps.value,
    readValue: readIsoDay,
    fallbackValue: undefined,
  });
  const pickDay = (pickedTimestamp: number | null | undefined) => {
    if (typeof pickedTimestamp !== "number" || !Number.isFinite(pickedTimestamp)) return;
    changeValue(formatIsoDay(pickedTimestamp));
  };
  return (
    <ComposedInputField label={label} isUsable={isUsable} isPending={isPending} pendingHeight={48} testId={testId}>
      {isUsable ? (
        <div className={styles.datePicker} data-testid={testId}>
          <DateInput
            // A new day from the tree (a reset) remounts the picker: it keeps its own copy of the value otherwise.
            key={value ?? "none"}
            value={parseIsoDay(value)}
            minDate={minDay}
            maxDate={maxDay}
            locale={toDatePatternLocale(dateLocale)}
            onChange={pickDay}
          />
          {/* The field says what it opens (ChatGPT's date field carries the calendar glyph); the click stays the field's. */}
          <Icon className={styles.datePickerIcon} color="var(--agents-fg-light)" pixelSize={18} name="calendar" />
        </div>
      ) : (
        <ComposedInputFrozenValue shownValue={formatIsoDayForReading(value, dateLocale)} testId={`${testId}-frozen`} />
      )}
    </ComposedInputField>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "DatePicker",
  component: ComposedDatePicker,
};
