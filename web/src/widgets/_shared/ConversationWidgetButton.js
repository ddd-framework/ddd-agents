import { Button } from "../../provider/components";
import { ConversationWidgetActions } from "../../renderer/ConversationWidgetActions/ConversationWidgetActions";
/** An action button inside a widget; screenshots retain the content surrounding the action. */
export const ConversationWidgetButton = (props) => (<ConversationWidgetActions>
    <Button {...props}/>
  </ConversationWidgetActions>);
