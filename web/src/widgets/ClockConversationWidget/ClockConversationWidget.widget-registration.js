import { ClockConversationWidget } from "./ClockConversationWidget";
/**
 * The live clock / world clock — deliberately UNGATED: it shows no business data and needs no
 * capability (the time is computed in the browser).
 */
export const conversationWidgetRegistration = {
    name: "Clock",
    component: ClockConversationWidget,
};
