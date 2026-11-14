import { useAgentsOwnerSettings } from "../../../provider/AgentsProvider";

const readBrowserLanguage = (): string => (typeof navigator === "undefined" ? "en" : navigator.language || "en");

/**
 * The owner's language for the numbers an explainer formats, from the app (`useOwnerSettings`). Read defensively — the
 * widget renders in hosts that know no language (the e-mail capture, the preview, a spec), and there the browser's
 * language stands in.
 */
export const useConceptExplainerLanguage = (): string => useAgentsOwnerSettings().languageCode || readBrowserLanguage();

/** A number in the owner's language with a fixed count of decimals (`4,3` in German, `4.3` in English). */
export const formatConceptExplainerNumber = (value: number, decimals: number, languageCode: string): string => {
  try {
    return new Intl.NumberFormat(languageCode, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(value);
  } catch {
    return value.toFixed(decimals);
  }
};
