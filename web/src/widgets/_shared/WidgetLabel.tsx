import { Text, type AgentsTextReplacements } from "../../provider/components";
import React from "react";
import styles from "./WidgetLabel.module.scss";

interface WidgetLabelProps {
  children: React.ReactNode;
  replacements?: AgentsTextReplacements;
}

export const WidgetLabel = (props: WidgetLabelProps) => {
  const { children, replacements } = props;
  return (
    <Text
      className={styles.widgetLabel}
      role="help"
      weight="medium"
      color={"--agents-fg-light"}
      replacements={replacements}
    >
      {children}
    </Text>
  );
};
