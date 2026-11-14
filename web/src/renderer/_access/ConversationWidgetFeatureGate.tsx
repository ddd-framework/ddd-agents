import React from "react";
import { WidgetFeatureGate } from "../../provider/components";

/** Shared prop contract for any widget upgrade offer (a widget's own `upsellTrigger`); the flag is the app's object. */
export interface ConversationWidgetUpsellProps {
  flag: unknown;
}

interface ConversationWidgetFeatureGateProps {
  /** The requirement the widget declares in the registry (an app feature flag, opaque to the package). */
  featureFlag: string;
  /** Optional per-widget upgrade offer; falls back to the app gate's own. */
  UpsellTrigger?: React.ComponentType<ConversationWidgetUpsellProps>;
  /** The widget's raw agent space key attribute (renders for another agent space). */
  agentSpaceKeyAttribute?: string | number;
  children: React.ReactNode;
}

/**
 * Gates a widget on the requirement its registry entry declares, through the app's `WidgetFeatureGate` component (the
 * environment's): the widget, a placeholder while the requirements load, or an upgrade offer. Without an app gate
 * every widget renders.
 */
export const ConversationWidgetFeatureGate = (props: ConversationWidgetFeatureGateProps) => {
  const { featureFlag, UpsellTrigger, agentSpaceKeyAttribute, children } = props;
  return (
    <WidgetFeatureGate featureFlag={featureFlag} UpsellTrigger={UpsellTrigger} agentSpaceKeyAttribute={agentSpaceKeyAttribute}>
      {children}
    </WidgetFeatureGate>
  );
};
