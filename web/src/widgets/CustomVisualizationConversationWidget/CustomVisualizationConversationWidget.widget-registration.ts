import type { ConversationWidgetEntry } from "../../renderer/conversationWidgetRegistry";
import { CustomVisualizationConversationWidget } from "./CustomVisualizationConversationWidget";

/**
 * The sandboxed custom visualization — deliberately UNGATED: it shows no product data of one
 * domain, only what the block itself carries. Opens no canvas.
 */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: "CustomVisualization",
  component: CustomVisualizationConversationWidget,
};
