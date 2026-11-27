import { classNames } from "../../provider/class-names";
import React from "react";
import { ConversationWidgetAgentSpaceHeader } from "./ConversationWidgetAgentSpaceHeader";
import layout from "../conversation-widget-layout.module.scss";
import styles from "./ConversationWidgetAgentSpaceFrame.module.scss";
export const ConversationWidgetAgentSpaceFrame = (props) => {
    const { anotherAgentSpaceKey, wide = false, className, children } = props;
    return (<div className={classNames(styles.frame, wide && layout.wide, className)}>
      {anotherAgentSpaceKey !== undefined && <ConversationWidgetAgentSpaceHeader agentSpaceKey={anotherAgentSpaceKey}/>}
      {children}
    </div>);
};
