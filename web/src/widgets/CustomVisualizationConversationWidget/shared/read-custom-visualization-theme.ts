/**
 * The chat's colours as plain values for the sandbox frame (the visualization may follow the theme). The
 * frame cannot read the chat's CSS, and a token's declared value is a `var()` / `light-dark()` expression that means
 * nothing there — so each token is RESOLVED once on a hidden probe inside the widget (its theme scope) and handed over as
 * a colour. The frame sets them as `--agents-*` custom properties (and under every prefix the app declares); the model's
 * CSS may use `var(--agents-fg)` and friends. The keys are the frame's variables, the values the chat's tokens.
 */
const THEME_TOKENS: Readonly<Record<string, string>> = {
  "--agents-fg": "--agents-fg",
  "--agents-fg-light": "--agents-fg-light",
  "--agents-bg": "--agents-bg-surface",
  "--agents-bg-var": "--agents-bg-var",
  "--agents-accent": "--agents-fg-cta",
  "--agents-divider": "--agents-divider-gl",
};

export interface CustomVisualizationTheme {
  variables: Record<string, string>;
  colorScheme: "light" | "dark";
}

/** `rgb(r, g, b)` / `rgba(…)` → relative luminance 0…1; null for any other form. */
const readLuminance = (resolvedColor: string): number | null => {
  const channels = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/.exec(resolvedColor);
  if (!channels) return null;
  const [red, green, blue] = channels.slice(1, 4).map((channel) => Number(channel) / 255);
  return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
};

export const readCustomVisualizationTheme = (themeScopeElement: HTMLElement | null): CustomVisualizationTheme => {
  const variables: Record<string, string> = {};
  if (!themeScopeElement || typeof window.getComputedStyle !== "function") {
    return { variables, colorScheme: "light" };
  }
  const probeElement = document.createElement("span");
  probeElement.setAttribute("aria-hidden", "true");
  probeElement.hidden = true;
  themeScopeElement.appendChild(probeElement);
  try {
    for (const [frameVariable, chatToken] of Object.entries(THEME_TOKENS)) {
      probeElement.style.color = `var(${chatToken})`;
      const resolvedColor = window.getComputedStyle(probeElement).color;
      if (resolvedColor) variables[frameVariable] = resolvedColor;
    }
    const fontFamily = window.getComputedStyle(themeScopeElement).fontFamily;
    if (fontFamily) variables["--agents-font-family"] = fontFamily;
  } finally {
    probeElement.remove();
  }
  const surfaceLuminance = readLuminance(variables["--agents-bg"] ?? "");
  return { variables, colorScheme: surfaceLuminance !== null && surfaceLuminance < 0.5 ? "dark" : "light" };
};
