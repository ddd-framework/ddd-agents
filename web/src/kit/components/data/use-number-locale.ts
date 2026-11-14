import { useAgentsOwnerSettings } from "../../../provider/AgentsProvider";
import { toNumberLocale } from "./data-value-format";

/**
 * The locale the data nodes write numbers in — the owner's UI language from the app (`useOwnerSettings`), read
 * defensively: a tree renders in hosts without it too (the e-mail capture, a spec, the preview), and then numbers are `en`.
 */
export const useNumberLocale = (): string => {
  const { languageCode } = useAgentsOwnerSettings();
  return toNumberLocale(languageCode ?? undefined);
};
