import { classNames } from "../../provider/class-names";
import React from "react";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import styles from "./CardFooter.module.scss";

export const CardFooter = (props: { children: React.ReactNode; actionOnly?: boolean; className?: string }) => {
  const { children, actionOnly = true, className } = props;
  const isEmailRender = useIsEmailWidgetRender();
  if (isEmailRender && actionOnly) return null;
  return <div className={classNames(styles.cardFooter, className)}>{children}</div>;
};
