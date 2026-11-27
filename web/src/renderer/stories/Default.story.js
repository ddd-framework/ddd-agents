import { expect, within } from "storybook/test";
export default {};
export const Default = {
    args: {
        markdown: "This is a simple response with **bold** text.",
    },
    play: async ({ canvasElement }) => {
        const canvas = within(canvasElement);
        await expect(canvas.getByText("bold")).toBeInTheDocument();
        await expect(canvas.getByRole("strong")).toBeInTheDocument();
    },
};
