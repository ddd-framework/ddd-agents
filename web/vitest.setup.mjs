// The spec setup of the package (`vitest.config.mjs`). jsdom lacks the layout and graphics APIs the components call on
// mount; these neutral stand-ins let a spec render them. A spec that tests a measurement installs its own values.
import "@testing-library/jest-dom/vitest";
import { vi } from "vitest";

globalThis.IS_REACT_ACT_ENVIRONMENT = true;

// Node's own localStorage global can be unavailable; the specs use the jsdom window's storage.
Object.defineProperty(globalThis, "localStorage", {
  configurable: true,
  get: () => window.localStorage,
});

/** A 2D context whose every method is a mock: charts and canvases draw into nothing. */
const canvasContext = new Proxy(
  {},
  {
    get: (target, property) => {
      if (!(property in target)) target[property] = vi.fn();
      return target[property];
    },
    set: (target, property, value) => {
      target[property] = value;
      return true;
    },
  },
);

Object.defineProperty(HTMLCanvasElement.prototype, "getContext", {
  configurable: true,
  value: vi.fn(() => canvasContext),
});

Object.defineProperty(window, "matchMedia", {
  configurable: true,
  writable: true,
  value: vi.fn((query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
});

Object.defineProperty(window, "scrollTo", {
  configurable: true,
  writable: true,
  value: vi.fn(),
});

const createSvgMatrix = () => ({
  a: 1,
  b: 0,
  c: 0,
  d: 1,
  e: 0,
  f: 0,
  multiply: vi.fn(() => createSvgMatrix()),
  inverse: vi.fn(() => createSvgMatrix()),
  translate: vi.fn(() => createSvgMatrix()),
  scale: vi.fn(() => createSvgMatrix()),
  rotate: vi.fn(() => createSvgMatrix()),
  skewX: vi.fn(() => createSvgMatrix()),
  skewY: vi.fn(() => createSvgMatrix()),
});

const createSvgPoint = () => ({
  x: 0,
  y: 0,
  matrixTransform: vi.fn(() => ({ x: 0, y: 0 })),
});

const SVG_ELEMENT_STAND_INS = {
  getScreenCTM: vi.fn(createSvgMatrix),
  getBBox: vi.fn(() => ({ x: 0, y: 0, width: 0, height: 0 })),
  getComputedTextLength: vi.fn(() => 0),
  getTotalLength: vi.fn(() => 0),
  getPointAtLength: vi.fn(() => ({ x: 0, y: 0 })),
  createSVGMatrix: vi.fn(createSvgMatrix),
  createSVGPoint: vi.fn(createSvgPoint),
};

for (const [name, value] of Object.entries(SVG_ELEMENT_STAND_INS)) {
  Object.defineProperty(SVGElement.prototype, name, { configurable: true, value });
}

class ResizeObserverStandIn {
  observe = vi.fn();
  unobserve = vi.fn();
  disconnect = vi.fn();
}

class IntersectionObserverStandIn {
  root = null;
  rootMargin = "";
  thresholds = [];
  observe = vi.fn();
  unobserve = vi.fn();
  disconnect = vi.fn();
  takeRecords = vi.fn(() => []);
}

globalThis.ResizeObserver = ResizeObserverStandIn;
globalThis.IntersectionObserver = IntersectionObserverStandIn;
