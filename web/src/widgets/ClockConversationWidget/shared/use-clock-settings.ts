import { useEffect, useState } from "react";
import { useAgentsOwnerSettings } from "../../../provider/AgentsProvider";
import { isKnownTimeZone } from "./clock-time";
import type { ClockTimeFormat } from "./clock-widget-payload";

const FALLBACK_DATE_LOCALE = "en-GB";

const readBrowserLanguage = (): string => (typeof navigator === "undefined" ? "en" : navigator.language || "en");

const readBrowserTimeZone = (): string => {
  try {
    const browserTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    return browserTimeZone && isKnownTimeZone(browserTimeZone) ? browserTimeZone : "UTC";
  } catch {
    return "UTC";
  }
};

export interface ClockSettings {
  /** The owner's own zone: the one of the owner settings, else the browser's, else UTC. */
  ownerTimeZone: string;
  /** The owner's language — zone names and the 12 h day period. */
  language: string;
  /** The owner's date locale (`de-DE`) — the date lines. */
  dateLocale: string;
  /** `12h` when the owner settings use AM/PM. */
  ownerTimeFormat: ClockTimeFormat;
}

/** The owner's settings come from the app, read defensively: the widget renders in hosts that know only some of them. */
export const useClockSettings = (): ClockSettings => {
  const ownerSettings = useAgentsOwnerSettings();
  const ownerSettingsTimeZone = ownerSettings.timeZone;
  const useAmPm = ownerSettings.usesAmPm;
  const language = ownerSettings.languageCode || readBrowserLanguage();
  const dateLocale = ownerSettings.dateLocale || FALLBACK_DATE_LOCALE;
  const ownerTimeZone =
    ownerSettingsTimeZone && isKnownTimeZone(ownerSettingsTimeZone) ? ownerSettingsTimeZone : readBrowserTimeZone();
  return { ownerTimeZone, language, dateLocale, ownerTimeFormat: useAmPm ? "12h" : "24h" };
};

/**
 * The current instant, re-read at every full second while `isTicking` — the one timer of the widget, cleared on unmount.
 * Not ticking (the e-mail capture) keeps the instant of the first render: the capture time the "As of" line names.
 */
export const useClockNow = (isTicking: boolean): Date => {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    if (!isTicking) return undefined;
    let tickTimer: ReturnType<typeof setTimeout> | undefined;
    const scheduleNextTick = () => {
      // Aligned to the next full second, so the second hand and the digital minute flip together.
      tickTimer = setTimeout(
        () => {
          setNow(new Date());
          scheduleNextTick();
        },
        1000 - (Date.now() % 1000),
      );
    };
    setNow(new Date());
    scheduleNextTick();
    return () => {
      if (tickTimer !== undefined) clearTimeout(tickTimer);
    };
  }, [isTicking]);
  return now;
};
