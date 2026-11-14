import { expect, within } from "storybook/test";
import { Story } from "./_ConversationMarkdown.default";

export default {};
export const Default: Story = {
  args: {
    markdown: "This is a simple response with **bold** text.",
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement);
    await expect(canvas.getByText("bold")).toBeInTheDocument();
    await expect(canvas.getByRole("strong")).toBeInTheDocument();
  },
};
