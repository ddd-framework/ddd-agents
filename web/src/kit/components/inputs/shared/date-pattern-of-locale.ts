/** The date pattern per locale — the same table the app's date picker reads (`DD.MM.YYYY` for `de_DE`). */
const DATE_PATTERNS_BY_LOCALE: Record<string, string> = {
  en_US: "MM/DD/YYYY",
  en_GB: "DD/MM/YYYY",
  de_DE: "DD.MM.YYYY",
  de_AT: "DD.MM.YYYY",
  fr_FR: "DD/MM/YYYY",
  nl_NL: "DD-MM-YYYY",
  es_ES: "DD/MM/YYYY",
  it_IT: "DD/MM/YYYY",
  pl_PL: "YYYY-MM-DD",
  pt_PT: "DD/MM/YYYY",
  ja_JP: "YYYY/MM/DD",
  id_ID: "DD/MM/YYYY",
  fi_FI: "DD.MM.YYYY",
  sk_SK: "DD.MM.YYYY",
  sv_SE: "YYYY-MM-DD",
  cs_CZ: "DD.MM.YYYY",
  el_GR: "DD/MM/YYYY",
};

/** The date pattern of a locale written with an underscore (`de_DE`); an unknown locale reads `DD.MM.YYYY`. */
export const getDatePatternFromLocale = (locale: string): string => {
  const datePattern = DATE_PATTERNS_BY_LOCALE[locale];
  if (!datePattern) {
    console.error("ERROR | getDatePatternFromLocale | Details: locale not found ", locale);
    return "DD.MM.YYYY";
  }
  return datePattern;
};
