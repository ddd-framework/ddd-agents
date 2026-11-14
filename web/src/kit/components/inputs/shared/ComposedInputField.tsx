import { Text } from "../../../../provider/components";
import { classNames } from "../../../../provider/class-names";
import { escapePlainTextForText } from "../../../../provider/environment";
import type { ReactNode } from "react";
import { SkeletonLine } from "../../../../widgets/_shared/skeleton-primitives";
import styles from "./ComposedInputField.module.scss";

export interface ComposedInputFieldProps {
  /** The model's label above the control (business content, never translated). */
  label?: string;
  /** The id of the control the label names — a native `<label for>`; omit when the control labels itself. */
  controlId?: string;
  /** Shown at the end of the label row — the slider's formatted value. */
  labelRowValue?: string;
  /** One muted line under the control. */
  description?: string;
  /** False on a replay and in the e-mail capture: the control stays visible, but takes no pointer, key or focus. */
  isUsable: boolean;
  /** A bound value is still loading: the control's place is a skeleton of the same height. */
  isPending?: boolean;
  /** The height of that skeleton in px (the control's own height). */
  pendingHeight?: number;
  className?: string;
  testId?: string;
  children: ReactNode;
}

/**
 * The frame every kit input sits in: the label row (label left, a value right), the control, an
 * optional muted line. A control the owner may not use (a replayed message, the e-mail capture) keeps its look and its
 * current value but is `inert` — no click, no key, no focus — so a frozen tree never pretends to answer. Every model
 * string goes through `escapePlainTextForText`: the app's `Text` may parse a string child as markup.
 */
export const ComposedInputField = (props: ComposedInputFieldProps) => {
  const {
    label,
    controlId,
    labelRowValue,
    description,
    isUsable,
    isPending = false,
    pendingHeight = 40,
    className,
    testId,
    children,
  } = props;
  const labelText = label ? (
    <Text role="body" weight="medium" translate={false}>
      {escapePlainTextForText(label)}
    </Text>
  ) : null;
  return (
    <div
      className={classNames(styles.field, !isUsable && styles.frozen, className)}
      inert={!isUsable || undefined}
      data-usable={isUsable ? "true" : "false"}
      data-testid={testId ? `${testId}-field` : undefined}
    >
      {labelText || labelRowValue ? (
        <div className={styles.labelRow}>
          {labelText && controlId ? (
            <label htmlFor={controlId} className={styles.label}>
              {labelText}
            </label>
          ) : (
            <span className={styles.label}>{labelText}</span>
          )}
          {labelRowValue ? (
            <Text role="body" weight="bold" translate={false} className={styles.labelValue}>
              {escapePlainTextForText(labelRowValue)}
            </Text>
          ) : null}
        </div>
      ) : null}
      {isPending ? (
        <span aria-busy="true" className={styles.pending} data-testid={testId ? `${testId}-skeleton` : undefined}>
          <SkeletonLine height={pendingHeight} radius={12} />
        </span>
      ) : (
        children
      )}
      {description ? (
        <Text role="caption" color="var(--agents-fg-light)" translate={false} className={styles.description}>
          {escapePlainTextForText(description)}
        </Text>
      ) : null}
    </div>
  );
};
