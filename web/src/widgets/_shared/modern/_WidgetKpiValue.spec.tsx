import { cleanup, render } from "@testing-library/react";
import { afterEach, describe, expect, test } from "vitest";
import { WidgetKpiValue } from "./WidgetKpiValue";

// The numeric fitting belongs to the environment's `HeroValue`; the package default does not measure.

afterEach(() => {
  cleanup();
});

describe("WidgetKpiValue optional slots", () => {
  test("without accent and meter the DOM keeps its former shape", () => {
    const { container, getByTestId } = render(<WidgetKpiValue label="Rank" value="12" testId="kpi" />);
    expect(getByTestId("kpi-label").parentElement).toBe(container.firstElementChild);
    expect(getByTestId("kpi-value-block").nextElementSibling).toBeNull();
  });

  test("renders the accent beside the label and the meter under the value", () => {
    const { getByTestId } = render(
      <WidgetKpiValue
        label="Score"
        value="61,4"
        testId="kpi"
        accent={<span data-testid="accent" />}
        meter={<span data-testid="meter" />}
      />,
    );
    expect(getByTestId("accent").previousElementSibling).toBe(getByTestId("kpi-label"));
    expect(getByTestId("kpi-value-block").nextElementSibling).toBe(getByTestId("meter"));
  });

  test("hides the meter while loading", () => {
    const { queryByTestId } = render(
      <WidgetKpiValue label="Score" value="61,4" loading meter={<span data-testid="meter" />} />,
    );
    expect(queryByTestId("meter")).toBeNull();
  });
});
