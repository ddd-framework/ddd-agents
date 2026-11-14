import { Button } from "../../provider/components";
import type { ComponentProps } from "react";
import { ConversationWidgetActions } from "../../renderer/ConversationWidgetActions/ConversationWidgetActions";

/** An action button inside a widget; screenshots retain the content surrounding the action. */
export const ConversationWidgetButton = (props: ComponentProps<typeof Button>) => (
  <ConversationWidgetActions>
    <Button {...props} />
  </ConversationWidgetActions>
);
