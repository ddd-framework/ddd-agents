import { Text } from "../../provider/components";
import { useMemo } from "react";
import type { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useIsEmailWidgetRender } from "../../renderer/ConversationWidgetRenderContext";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { useWidgetImageRender } from "../../image-render/WidgetImageRenderContext";
import { formatCaptureMoment, isKnownTimeZone, readCityOfTimeZone } from "./shared/clock-time";
import { parseClockPayload, type ClockPayloadEntry } from "./shared/clock-widget-payload";
import { useClockNow, useClockSettings } from "./shared/use-clock-settings";
import { ClockWidget } from "./ClockWidget/ClockWidget";
import type { ClockWidgetEntry } from "./ClockWidget/ClockRow";
import styles from "./ClockConversationWidget.module.scss";

type ClockConversationWidgetProps = PayloadConversationWidgetProps;

/** The clocks the browser can show, and the zone ids it cannot (dropped and reported). */
interface ResolvedClocks {
  clocks: ClockWidgetEntry[];
  unknownTimeZones: string[];
}

const resolveClocks = (payloadClocks: ClockPayloadEntry[]): ResolvedClocks => {
  const clocks: ClockWidgetEntry[] = [];
  const unknownTimeZones: string[] = [];
  for (const payloadClock of payloadClocks) {
    if (!isKnownTimeZone(payloadClock.timeZone)) {
      unknownTimeZones.push(payloadClock.timeZone);
      continue;
    }
    clocks.push({
      timeZone: payloadClock.timeZone,
      label: payloadClock.label ?? readCityOfTimeZone(payloadClock.timeZone),
      ...(payloadClock.abbreviation ? { abbreviation: payloadClock.abbreviation } : {}),
    });
  }
  return { clocks, unknownTimeZones };
};

/**
 * The `Clock` widget: the live time of one place or a world clock of several, from the
 * IANA zone ids and labels the model passes. DATA-COMPLETE without data: the time is computed in the browser every
 * second (`Intl`), nothing is fetched. A zone id the browser does not know is dropped and reported `failed`
 * (`INVALID_PROP`, the dropped ids in the detail) while the other clocks render; a payload without clocks reports
 * `empty`. In the e-mail capture the clocks freeze at the capture moment and an "As of" line names it. Inline only.
 */
export const ClockConversationWidget = (props: ClockConversationWidgetProps) => {
  const { payload } = props;
  const testId = "clock-widget";
  const isEmailRender = useIsEmailWidgetRender();
  const isCapture = useWidgetImageRender() !== null;
  const isFrozen = isEmailRender || isCapture;
  const {
    title,
    timeFormat: payloadTimeFormat,
    clocks: payloadClocks,
  } = useMemo(() => parseClockPayload(payload), [payload]);
  const { clocks, unknownTimeZones } = useMemo(() => resolveClocks(payloadClocks), [payloadClocks]);
  const { ownerTimeZone, language, dateLocale, ownerTimeFormat } = useClockSettings();
  const now = useClockNow(!isFrozen);
  const timeFormat = payloadTimeFormat ?? ownerTimeFormat;

  const unknownTimeZonesDetail =
    unknownTimeZones.length > 0 ? `Unknown time zone ids dropped: ${unknownTimeZones.join(", ")}.` : undefined;
  useReportWidgetRenderStatus(
    payloadClocks.length === 0 ? "empty" : unknownTimeZones.length > 0 ? "failed" : "mounted",
    payloadClocks.length === 0 ? "NO_DATA" : unknownTimeZones.length > 0 ? "INVALID_PROP" : undefined,
    payloadClocks.length === 0 ? "The Clock widget carries no time zone." : unknownTimeZonesDetail,
  );

  if (payloadClocks.length === 0) {
    return (
      <Text
        role="help"
        color="var(--agents-fg-light)"
        className={styles.notice}
        testId={`${testId}-empty`}
      >
        {"No time zone to show."}
      </Text>
    );
  }
  if (clocks.length === 0) {
    return (
      <Text
        role="help"
        color="var(--agents-fg-light)"
        className={styles.notice}
        testId={`${testId}-error`}
      >
        {"The time zones of this clock are unknown."}
      </Text>
    );
  }
  return (
    <ClockWidget
      clocks={clocks}
      title={title}
      now={now}
      ownerTimeZone={ownerTimeZone}
      timeFormat={timeFormat}
      language={language}
      dateLocale={dateLocale}
      testId={testId}
      captureMoment={isFrozen ? formatCaptureMoment(now, ownerTimeZone, dateLocale, timeFormat) : undefined}
    />
  );
};
