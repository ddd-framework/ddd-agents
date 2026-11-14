import { useMemo } from "react";
import type { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { useAgentsOwnerSettings } from "../../provider/AgentsProvider";
import { parseCurrencyConverterPayload } from "./shared/currency-converter-widget-payload";
import { CurrencyConverterWidget } from "./CurrencyConverterWidget/CurrencyConverterWidget";

/**
 * `CurrencyConverter`: a currency conversion at the dated rate `getCurrencyExchangeRate` returned.
 * DATA-COMPLETE — the block carries the rate and its day; the widget never fetches. A block without a usable currency
 * pair or rate renders nothing and reports `failed`, so the model answers in prose instead of pointing at a widget
 * that is not there.
 */
export const CurrencyConverterConversationWidget = (props: PayloadConversationWidgetProps) => {
  const { payload } = props;
  const conversion = useMemo(() => parseCurrencyConverterPayload(payload), [payload]);
  const languageCode = useAgentsOwnerSettings().languageCode ?? "";
  const isEmailRender = useIsEmailWidgetRender();
  useReportWidgetRenderStatus(
    conversion ? "mounted" : "failed",
    conversion ? undefined : "INVALID_PROP",
    conversion ? undefined : "The CurrencyConverter block has no valid fromCurrency, toCurrency and rate.",
  );
  if (!conversion) return null;
  return (
    <CurrencyConverterWidget
      conversion={conversion}
      languageCode={languageCode}
      isStatic={isEmailRender}
      testId="currency-converter-widget"
    />
  );
};
