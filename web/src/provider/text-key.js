/**
 * Marks a text key the package translates later, where the translate call does not see it as a literal (a table of
 * labels, a helper's argument): returns the key unchanged. `scripts/i18n-keys.mjs` lists every marked key in
 * `i18n-keys.json`, so a host that translates the package's texts finds it there.
 */
export const textKey = (key) => key;
