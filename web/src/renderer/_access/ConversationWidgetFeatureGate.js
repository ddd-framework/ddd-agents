import React from "react";
import { WidgetFeatureGate } from "../../provider/components";
/**
 * Gates a widget on the requirement its registry entry declares, through the app's `WidgetFeatureGate` component (the
 * environment's): the widget, a placeholder while the requirements load, or an upgrade offer. Without an app gate
 * every widget renders.
 */
export const ConversationWidgetFeatureGate = (props) => {
    const { featureFlag, UpsellTrigger, agentSpaceKeyAttribute, children } = props;
    return (<WidgetFeatureGate featureFlag={featureFlag} UpsellTrigger={UpsellTrigger} agentSpaceKeyAttribute={agentSpaceKeyAttribute}>
      {children}
    </WidgetFeatureGate>);
};
