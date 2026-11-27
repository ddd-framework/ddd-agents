import { cleanup, render } from "@testing-library/react";
import { afterEach, describe, expect, test } from "vitest";
import { Text } from "../../../provider/components";
import { WidgetSurfaceSkeleton } from "./WidgetSurfaceSkeleton";
afterEach(() => {
    cleanup();
});
describe("WidgetSurfaceSkeleton title", () => {
    test("a plain text title gets the title paragraph", () => {
        const { container } = render(<WidgetSurfaceSkeleton title="Files" rowCount={1}/>);
        expect(container.querySelectorAll("p").length).toBeGreaterThan(0);
        expect(container.textContent).toContain("Files");
    });
    test("an element title renders as it is: no paragraph inside a paragraph", () => {
        const { container } = render(<WidgetSurfaceSkeleton title={<Text role="intro" weight="bold" translate={false}>
            Two files
          </Text>} rowCount={1}/>);
        expect(container.querySelector("p p")).toBeNull();
        expect(container.textContent).toContain("Two files");
    });
});
