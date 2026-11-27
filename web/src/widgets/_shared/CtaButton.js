import { Button } from "../../provider/components";
import React from "react";
import { ConversationWidgetActions } from "../../renderer/ConversationWidgetActions/ConversationWidgetActions";
export const CtaButton = ({ label, action, onAction, testId, }) => (<ConversationWidgetActions>
    <Button variant="muted" icon="arrowRight" iconPosition="right" onClick={action && onAction ? () => onAction(action) : undefined} testId={testId}>
      {label}
    </Button>
  </ConversationWidgetActions>);
