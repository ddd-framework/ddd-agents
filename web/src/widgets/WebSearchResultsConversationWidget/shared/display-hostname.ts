/**
 * The hostname as a reader writes it: an internationalised domain arrives from `URL.hostname` in its ASCII form
 * (`xn--bckerhandwerk-bfb.de`), which reads as noise on a source chip — each `xn--` label is decoded back to Unicode
 * (`bäckerhandwerk.de`) with the Punycode algorithm of RFC 3492. A label that does not decode stays as it was. The
 * favicon lookup keeps the ASCII form; this is for display only.
 */

const BASE = 36;
const T_MIN = 1;
const T_MAX = 26;
const SKEW = 38;
const DAMP = 700;
const INITIAL_BIAS = 72;
const INITIAL_N = 128;
const MAX_CODE_POINT = 0x10ffff;

const adaptBias = (delta: number, numPoints: number, isFirstTime: boolean): number => {
  let scaledDelta = isFirstTime ? Math.floor(delta / DAMP) : delta >> 1;
  scaledDelta += Math.floor(scaledDelta / numPoints);
  let k = 0;
  while (scaledDelta > ((BASE - T_MIN) * T_MAX) >> 1) {
    scaledDelta = Math.floor(scaledDelta / (BASE - T_MIN));
    k += BASE;
  }
  return k + Math.floor(((BASE - T_MIN + 1) * scaledDelta) / (scaledDelta + SKEW));
};

const digitOf = (codeUnit: number): number => {
  if (codeUnit >= 0x30 && codeUnit <= 0x39) return codeUnit - 22; // 0-9 → 26-35
  if (codeUnit >= 0x41 && codeUnit <= 0x5a) return codeUnit - 0x41; // A-Z → 0-25
  if (codeUnit >= 0x61 && codeUnit <= 0x7a) return codeUnit - 0x61; // a-z → 0-25
  return BASE;
};

/** Decodes the part of a label after `xn--`; undefined when it is not valid Punycode. */
export const decodePunycodeLabel = (encodedLabel: string): string | undefined => {
  const delimiterIndex = encodedLabel.lastIndexOf("-");
  const basicPart = delimiterIndex > 0 ? encodedLabel.slice(0, delimiterIndex) : "";
  if ([...basicPart].some((character) => character.charCodeAt(0) >= 0x80)) return undefined;
  const codePoints = [...basicPart].map((character) => character.charCodeAt(0));
  let n = INITIAL_N;
  let bias = INITIAL_BIAS;
  let i = 0;
  let position = delimiterIndex > 0 ? delimiterIndex + 1 : 0;
  while (position < encodedLabel.length) {
    const previousI = i;
    let weight = 1;
    for (let k = BASE; ; k += BASE) {
      if (position >= encodedLabel.length) return undefined;
      const digit = digitOf(encodedLabel.charCodeAt(position++));
      if (digit >= BASE) return undefined;
      i += digit * weight;
      if (i > Number.MAX_SAFE_INTEGER) return undefined;
      const threshold = k <= bias ? T_MIN : k >= bias + T_MAX ? T_MAX : k - bias;
      if (digit < threshold) break;
      weight *= BASE - threshold;
    }
    bias = adaptBias(i - previousI, codePoints.length + 1, previousI === 0);
    n += Math.floor(i / (codePoints.length + 1));
    if (n > MAX_CODE_POINT) return undefined;
    i %= codePoints.length + 1;
    codePoints.splice(i, 0, n);
    i++;
  }
  return String.fromCodePoint(...codePoints);
};

export const toDisplayHostname = (hostname: string): string =>
  hostname
    .split(".")
    .map((label) => {
      if (!label.toLowerCase().startsWith("xn--")) return label;
      const decodedLabel = decodePunycodeLabel(label.slice(4));
      return decodedLabel ? decodedLabel : label;
    })
    .join(".");
