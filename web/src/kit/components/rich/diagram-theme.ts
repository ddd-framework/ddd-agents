import { useEffect, useState } from "react";

/**
 * The colours a Mermaid diagram is drawn in: the theme's functional tokens, resolved to concrete
 * colours on the diagram's own element at draw time — Mermaid computes shades from them, so it needs real colours, not
 * `var(…)` references — and the revision that changes when the colour scheme or theme switches, so the diagram is
 * redrawn in the new colours.
 */

const resolveTokenColor = (probeParent: HTMLElement, cssColorValue: string): string => {
  const colorProbe = document.createElement("span");
  colorProbe.style.color = cssColorValue;
  colorProbe.style.display = "none";
  probeParent.appendChild(colorProbe);
  const resolvedColor = getComputedStyle(colorProbe).color;
  colorProbe.remove();
  return resolvedColor;
};

/** Relative luminance (0 dark … 1 light) of an `rgb(…)` colour, or 1 when it cannot be read. */
const readLuminance = (rgbColor: string): number => {
  const channelMatch = rgbColor.match(/\d+(\.\d+)?/g);
  if (!channelMatch || channelMatch.length < 3) return 1;
  const [red, green, blue] = channelMatch.slice(0, 3).map((channel) => Number(channel) / 255);
  return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
};

/** Mermaid's `base` theme variables from the theme tokens around `probeParent`. */
export const readDiagramThemeVariables = (probeParent: HTMLElement): Record<string, string | boolean> => {
  const surfaceColor = resolveTokenColor(probeParent, "var(--agents-bg-surface)");
  const nodeColor = resolveTokenColor(probeParent, "var(--agents-bg-var)");
  const textColor = resolveTokenColor(probeParent, "var(--agents-fg)");
  const lineColor = resolveTokenColor(probeParent, "var(--agents-fg-lightest)");
  const borderColor = resolveTokenColor(probeParent, "var(--agents-divider-gl)");
  const fontFamily = getComputedStyle(probeParent).fontFamily;
  return {
    darkMode: readLuminance(surfaceColor) < 0.5,
    background: surfaceColor,
    fontFamily,
    primaryColor: nodeColor,
    primaryTextColor: textColor,
    primaryBorderColor: borderColor,
    secondaryColor: nodeColor,
    secondaryTextColor: textColor,
    secondaryBorderColor: borderColor,
    tertiaryColor: surfaceColor,
    tertiaryTextColor: textColor,
    tertiaryBorderColor: borderColor,
    mainBkg: nodeColor,
    nodeBorder: borderColor,
    clusterBkg: surfaceColor,
    clusterBorder: borderColor,
    lineColor,
    textColor,
    titleColor: textColor,
    edgeLabelBackground: nodeColor,
    noteBkgColor: nodeColor,
    noteTextColor: textColor,
    noteBorderColor: borderColor,
    actorBkg: nodeColor,
    actorBorder: borderColor,
    actorTextColor: textColor,
    signalColor: lineColor,
    signalTextColor: textColor,
  };
};

const COLOR_SCHEME_ATTRIBUTES = ["data-color-scheme", "data-theme", "class", "style"];

/** A number that grows whenever the page's colour scheme or theme may have changed. */
export const useColorSchemeRevision = (): number => {
  const [colorSchemeRevision, setColorSchemeRevision] = useState(0);
  useEffect(() => {
    const bumpRevision = () => setColorSchemeRevision((revision) => revision + 1);
    const attributeObserver = new MutationObserver(bumpRevision);
    attributeObserver.observe(document.documentElement, { attributes: true, attributeFilter: COLOR_SCHEME_ATTRIBUTES });
    const darkSchemeQuery = window.matchMedia?.("(prefers-color-scheme: dark)");
    darkSchemeQuery?.addEventListener?.("change", bumpRevision);
    return () => {
      attributeObserver.disconnect();
      darkSchemeQuery?.removeEventListener?.("change", bumpRevision);
    };
  }, []);
  return colorSchemeRevision;
};
