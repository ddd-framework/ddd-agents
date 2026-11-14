// @vitest-environment jsdom
import { fireEvent, render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import {
  WidgetRenderReportContext,
  type WidgetRenderReportEntry,
} from "../../renderer/render-report/widget-render-report";
import { ConversationWidgetRenderContext } from "../../renderer/ConversationWidgetRenderContext";
import { CurrencyConverterConversationWidget } from "./CurrencyConverterConversationWidget";
import { AgentsProvider } from "../../provider/AgentsProvider";
import type { AgentsEnvironment } from "../../provider/environment";

const languageState = { current: "de" };
/** The owner's language reaches the widget through the environment (`useOwnerSettings`). */
const ownerLanguageEnvironment: AgentsEnvironment = {
  useOwnerSettings: () => ({ languageCode: languageState.current }),
};

const REFERENCE_PAYLOAD = JSON.stringify({
  fromCurrency: "EUR",
  toCurrency: "USD",
  amount: 100,
  rate: 1.12023,
  rateDate: "2026-10-25",
});

const renderWidget = (payload: string, renderContext?: "email") => {
  const reports: WidgetRenderReportEntry[] = [];
  const widget = (
    <WidgetRenderReportContext.Provider value={{ report: (entry) => reports.push(entry) }}>
      <CurrencyConverterConversationWidget payload={payload} interactive />
    </WidgetRenderReportContext.Provider>
  );
  // The component library's Input dispatches into a store; the widget itself reads none.
  const store = configureStore({ reducer: { widgetSpec: (state: null = null) => state } });
  const view = render(
    <Provider store={store}>
      <AgentsProvider environment={ownerLanguageEnvironment}>
        {renderContext ? (
          <ConversationWidgetRenderContext.Provider value={renderContext}>
            {widget}
          </ConversationWidgetRenderContext.Provider>
        ) : (
          widget
        )}
      </AgentsProvider>
    </Provider>,
  );
  return { ...view, reports };
};

const readResult = () =>
  within(screen.getByTestId("currency-converter-result"))
    .getAllByText(/./)
    .map((node) => node.textContent);

const readAmountInput = (): HTMLInputElement => {
  const amountInput = screen.getByTestId("currency-converter-amount").querySelector("input");
  if (!amountInput) throw new Error("no amount input");
  return amountInput;
};

describe("CurrencyConverterConversationWidget", () => {
  it("shows the ChatGPT reference conversion with the day of the rate, never as live", () => {
    const { container, reports } = renderWidget(REFERENCE_PAYLOAD);

    expect(screen.getByText("Euro")).toBeTruthy();
    expect(screen.getByText("US-Dollar")).toBeTruthy();
    expect(readResult()).toContain("112,02");
    expect(screen.getByText("1 EUR = 1,12023 USD")).toBeTruthy();
    expect(container.textContent).toContain("25.10.2026");
    expect(container.textContent?.toLowerCase()).not.toContain("live");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  it("recomputes the converted value from the delivered rate when the owner types an amount", () => {
    renderWidget(REFERENCE_PAYLOAD);

    fireEvent.change(readAmountInput(), { target: { value: "250,5" } });

    expect(readResult()).toContain("280,62");
  });

  it("reads an amount grouped in the owner's language and groups the one at rest", () => {
    renderWidget(REFERENCE_PAYLOAD);

    fireEvent.change(readAmountInput(), { target: { value: "1.250.000" } });
    expect(readResult()).toContain("1.400.287,50");

    fireEvent.blur(readAmountInput());
    expect(readAmountInput().value).toBe("1.250.000");
  });

  it("marks an amount it cannot read and says what to type", () => {
    renderWidget(REFERENCE_PAYLOAD);
    expect(screen.queryByTestId("currency-converter-widget-amount-hint")).toBeNull();

    fireEvent.change(readAmountInput(), { target: { value: "abc" } });

    expect(screen.getByTestId("currency-converter-widget-amount-hint")).toBeTruthy();
    expect(readAmountInput().getAttribute("aria-invalid")).toBe("true");
  });

  it("shows the two currencies as plain labels, not pickers", () => {
    renderWidget(REFERENCE_PAYLOAD);

    expect(screen.getByTestId("currency-converter-widget-from-currency").textContent).toContain("EUR");
    expect(screen.getByTestId("currency-converter-widget-to-currency").textContent).toContain("USD");
    expect(screen.queryByRole("combobox")).toBeNull();
    expect(screen.queryByRole("listbox")).toBeNull();
  });

  it("shows a dash for an amount it cannot read", () => {
    renderWidget(REFERENCE_PAYLOAD);

    fireEvent.change(readAmountInput(), { target: { value: "abc" } });

    expect(readResult()).toContain("–");
  });

  it("swaps the currencies and inverts the rate without a request", () => {
    renderWidget(REFERENCE_PAYLOAD);

    fireEvent.click(screen.getByTestId("currency-converter-swap"));

    expect(screen.getByText("1 USD = 0,892674 EUR")).toBeTruthy();
    expect(readResult()).toContain("89,27");
  });

  it("renders the amount as text and no swap button in the e-mail capture", () => {
    renderWidget(REFERENCE_PAYLOAD, "email");

    expect(screen.queryByTestId("currency-converter-amount")).toBeNull();
    expect(screen.queryByTestId("currency-converter-swap")).toBeNull();
    expect(screen.getByText("100,00")).toBeTruthy();
  });

  it("renders nothing and reports failed for a block without a rate", () => {
    const { container, reports } = renderWidget(
      JSON.stringify({ fromCurrency: "EUR", toCurrency: "USD", amount: 100 }),
    );

    expect(container.textContent).toBe("");
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });
});
