import { Icon, Text } from "../../../../provider/components";
import { classNames } from "../../../../provider/class-names";
import { escapePlainTextForText } from "../../../../provider/environment";
import type { ReactNode } from "react";
import type { ListContextValue } from "../data-containers";
import styles from "./ListEntry.module.scss";

export interface ListEntryProps {
  marker: ListContextValue["marker"];
  title?: string;
  description?: string;
  meta?: string;
  value?: string;
  /** Nodes under the title (a ListItem's slot). */
  children?: ReactNode;
  /** Nodes at the end of the entry (a ListItem's `trailing` slot). */
  trailing?: ReactNode;
  /** Render a `<div>` instead of an `<li>` — an entry outside a list. */
  isStandalone?: boolean;
  testId?: string;
}

/**
 * One list entry: marker | title over description
 * and muted meta, then the nodes under them | value and trailing nodes. All text is the model's (no translation).
 */
export const ListEntry = (props: ListEntryProps) => {
  const { marker, title, description, meta, value, children, trailing, isStandalone = false, testId } = props;
  const EntryElement = isStandalone ? "div" : "li";
  return (
    <EntryElement className={classNames(styles.entry, marker !== "none" && styles.withMarker)} data-testid={testId}>
      {marker === "bullet" ? <span className={styles.bullet} aria-hidden="true" /> : null}
      {marker === "number" ? <span className={styles.number} aria-hidden="true" /> : null}
      {marker === "check" ? (
        <span className={styles.check} aria-hidden="true">
          <Icon pixelSize={16} color="var(--agents-success-500)" name="check" />
        </span>
      ) : null}
      <span className={styles.text}>
        {title ? (
          <Text role="body" weight="bold" translate={false}>
            {escapePlainTextForText(title)}
          </Text>
        ) : null}
        {description ? (
          <Text role="help" translate={false}>
            {escapePlainTextForText(description)}
          </Text>
        ) : null}
        {meta ? (
          <Text role="caption" color="var(--agents-fg-light)" translate={false}>
            {escapePlainTextForText(meta)}
          </Text>
        ) : null}
        {children}
      </span>
      {value || trailing ? (
        <span className={styles.trailing}>
          {value ? (
            <Text role="body" weight="medium" className={styles.value} translate={false}>
              {escapePlainTextForText(value)}
            </Text>
          ) : null}
          {trailing}
        </span>
      ) : null}
    </EntryElement>
  );
};
