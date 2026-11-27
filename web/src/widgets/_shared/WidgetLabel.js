import { Text } from "../../provider/components";
import React from "react";
import styles from "./WidgetLabel.module.scss";
export const WidgetLabel = (props) => {
    const { children, replacements } = props;
    return (<Text className={styles.widgetLabel} role="help" weight="medium" color={"--agents-fg-light"} replacements={replacements}>
      {children}
    </Text>);
};
