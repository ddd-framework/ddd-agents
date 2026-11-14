import { useAgentsOwnerSettings } from "../../../../provider/AgentsProvider";

/** The date format when no owner date locale is loaded (a preview, a story, a spec): day/month/year. */
const FALLBACK_DATE_LOCALE = "en-GB";

const readBrowserLanguage = (): string => (typeof navigator === "undefined" ? "en" : navigator.language || "en");

/**
 * The owner's language for number formatting, from the app (`useOwnerSettings`). Read defensively: the kit renders in
 * hosts that know no language (the widget preview, a spec, the e-mail capture) — there the browser's language formats
 * the numbers.
 */
export const useComposedInputLanguage = (): string => useAgentsOwnerSettings().languageCode || readBrowserLanguage();

/** The owner's date locale, read as defensively as the language. */
export const useComposedInputDateLocale = (): string => useAgentsOwnerSettings().dateLocale || FALLBACK_DATE_LOCALE;
