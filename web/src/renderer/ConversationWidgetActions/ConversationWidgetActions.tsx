import type { ReactNode } from "react";
import { useIsEmailWidgetRender } from "../ConversationWidgetRenderContext";

interface ConversationWidgetActionsProps {
  children: ReactNode;
}

/** Wrap the entire action area, including its footer/divider, to omit it from email images. */
export const ConversationWidgetActions = (props: ConversationWidgetActionsProps) => {
  const { children } = props;
  const isEmailRender = useIsEmailWidgetRender();
  return isEmailRender ? null : <>{children}</>;
};
