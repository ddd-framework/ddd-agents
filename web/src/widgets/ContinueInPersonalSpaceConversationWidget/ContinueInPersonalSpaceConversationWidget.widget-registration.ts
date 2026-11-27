import type { ConversationWidgetEntry } from "../../renderer/conversationWidgetRegistry";
import { ContinueInPersonalSpaceConversationWidget } from "./ContinueInPersonalSpaceConversationWidget";
import { CONTINUE_IN_PERSONAL_SPACE_WIDGET_TYPE } from "./continue-in-personal-space-payload";

/** The offer to continue a request in the personal space — ungated: the backend writes it only for the owner. */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: CONTINUE_IN_PERSONAL_SPACE_WIDGET_TYPE,
  component: ContinueInPersonalSpaceConversationWidget,
};
