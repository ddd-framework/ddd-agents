import { within } from 'storybook/test';
import { ConversationMessageWidgets } from "./ConversationMessageWidgets";
export default {
    title: "AI Conversations/Conversation/ConversationMessageWidgets",
    component: ConversationMessageWidgets,
    parameters: {
        controls: {
            exclude: /.*/g,
        },
    },
};
export const ConversationMessageWidgetsStory = {
    args: {
        message: `Use the Keyword Strategist
to create strategies, helping you identify the best keywords to attract new customers.`
    },
    play: async ({ canvasElement }) => {
        const canvas = within(canvasElement);
    },
};
