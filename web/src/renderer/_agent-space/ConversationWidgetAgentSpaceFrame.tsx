import { classNames } from "../../provider/class-names";
import React from "react";
import { ConversationWidgetAgentSpaceHeader } from "./ConversationWidgetAgentSpaceHeader";
import layout from "../conversation-widget-layout.module.scss";
import styles from "./ConversationWidgetAgentSpaceFrame.module.scss";

interface ConversationWidgetAgentSpaceFrameProps {
  /** The key of another agent space than the active one: the frame shows its header chip. Undefined → no header. */
  anotherAgentSpaceKey?: number;
  /** Apply the shared wide layout — the frame becomes the wide bounds the chip anchors to. */
  wide?: boolean;
  /** Extra class on the frame — e.g. a widget that needs the frame to fill the drawer height. */
  className?: string;
  children: React.ReactNode;
}

export const ConversationWidgetAgentSpaceFrame = (props: ConversationWidgetAgentSpaceFrameProps) => {
  const { anotherAgentSpaceKey, wide = false, className, children } = props;

  return (
    <div className={classNames(styles.frame, wide && layout.wide, className)}>
      {anotherAgentSpaceKey !== undefined && <ConversationWidgetAgentSpaceHeader agentSpaceKey={anotherAgentSpaceKey} />}
      {children}
    </div>
  );
};
