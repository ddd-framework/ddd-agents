import type { ConversationWidgetEntry } from "../../renderer/conversationWidgetRegistry";
import { ExtendConnectionPermissionConversationWidget } from "./ExtendConnectionPermissionConversationWidget";
import { EXTEND_CONNECTION_PERMISSION_WIDGET_TYPE } from "./extend-connection-permission-payload";

/** The offer to extend a connection's permission — ungated: the backend writes it only for the connection's owner. */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: EXTEND_CONNECTION_PERMISSION_WIDGET_TYPE,
  component: ExtendConnectionPermissionConversationWidget,
};
