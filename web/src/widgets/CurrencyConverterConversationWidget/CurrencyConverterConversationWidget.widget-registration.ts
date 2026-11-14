import type { ConversationWidgetEntry } from "../../renderer/conversationWidgetRegistry";
import { CurrencyConverterConversationWidget } from "./CurrencyConverterConversationWidget";

/** The currency converter: ungated — currency conversion belongs to no feature domain. */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: "CurrencyConverter",
  component: CurrencyConverterConversationWidget,
};
