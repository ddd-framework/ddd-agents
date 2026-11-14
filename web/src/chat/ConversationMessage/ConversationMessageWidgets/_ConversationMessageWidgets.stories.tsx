import { StoryObj } from "@storybook/react";
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

/**
 * We create the story type referencing the wrapper component
 */
type Story = StoryObj<typeof ConversationMessageWidgets>;

export const ConversationMessageWidgetsStory: Story = {
  args: {
    message: `Use the Keyword Strategist
to create strategies, helping you identify the best keywords to attract new customers.`
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement);
  },
};
