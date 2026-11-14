import { Text } from "../../../../provider/components";
import { classNames } from "../../../../provider/class-names";
import { escapePlainTextForText } from "../../../../provider/environment";
import styles from "./ComposedInputFrozenValue.module.scss";

export interface ComposedInputFrozenValueProps {
  /** The control's current value as the owner reads it; empty shows a muted dash. */
  shownValue: string | undefined;
  /** Keep the line breaks of a multi-line text. */
  isMultiline?: boolean;
  testId?: string;
}

/**
 * A text-like control (a select, a field, a date) on a replayed message or in the e-mail capture:
 * its value as plain readable text in the field's shape — normal text colour, no disabled grey, no caret, no chevron.
 * Only the affordance goes; what the owner had chosen stays legible.
 */
export const ComposedInputFrozenValue = (props: ComposedInputFrozenValueProps) => {
  const { shownValue, isMultiline = false, testId } = props;
  const hasValue = shownValue !== undefined && shownValue.trim() !== "";
  return (
    <div
      className={classNames(styles.frozenValue, isMultiline && styles.multiline)}
      data-frozen-value="true"
      data-testid={testId}
    >
      {hasValue ? (
        <Text role="body" translate={false} allowNewLines={isMultiline}>
          {escapePlainTextForText(shownValue)}
        </Text>
      ) : (
        <Text role="body" translate={false} color="var(--agents-fg-lightest)">
          {"—"}
        </Text>
      )}
    </div>
  );
};
