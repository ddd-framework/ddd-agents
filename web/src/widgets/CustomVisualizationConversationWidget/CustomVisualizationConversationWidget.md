---
name: CustomVisualization
emitter: agent
summary: A visualization the model programs itself — its own HTML, CSS and JavaScript with the data in the block, run in a sandboxed frame. ONLY for what no ComposedView node can express (a canvas animation, a custom projection, a simulation).
description: Runs the block's `html`, `css` and `script` in an iframe with `sandbox="allow-scripts"` (an opaque origin — no cookies, no storage, no access to the chat) under the policy `default-src 'none'` (no network: no fetch, no external image, script or font). The data travels in the block (`data`, read by the script as `window.AGENTS_VISUALIZATION.data`); libraries come only from the allow-list (`d3`). The frame tells the chat its height (clamped 80–1200 px) and beats every second; a frame that stops beating (an endless loop) or never gets ready is removed. In the e-mail it renders as a static preview without the frame.
---

# CustomVisualizationConversationWidget

## Description
ChatGPT's custom visualization (`AppBlock`) in our design: one elevated
card with the model's title and caption, and under it the model's own visualization in a sandboxed frame — a canvas
animation with sliders, a drag-to-rotate projection, a small simulation. The chat's colours are handed to the frame as
CSS custom properties (`--agents-fg`, `--agents-fg-light`, `--agents-bg`, `--agents-bg-var`, `--agents-accent`, `--agents-divider`,
`--agents-font-family`) and `window.AGENTS_VISUALIZATION.colorScheme` (`light` | `dark`), so a visualization can follow the
theme.
An app whose stored scripts read other names declares them once (`AgentsEnvironment.sandboxLegacyAliases`:
`visualizationGlobalNames`, `themeVariablePrefixes`); the frame then defines the same frozen global under each declared
name and sets every theme variable under each declared prefix as well. Without a declaration only the names above exist.

What the sandbox allows and forbids:
- the iframe carries `sandbox="allow-scripts"` only — never `allow-same-origin`, `allow-top-navigation`,
  `allow-popups` or `allow-forms`;
- the frame page's policy: `default-src 'none'; script-src <the frame's own bootstrap by hash> blob:;
  style-src 'unsafe-inline'; img-src data: blob:; font-src data:; form-action 'none'; base-uri 'none'` — the block's
  script and the allow-listed libraries run as `blob:` scripts the bootstrap creates; nothing loads from a URL;
- inline event attributes (`onclick="…"`) do not run — use `addEventListener` in the script;
- an image is a `data:` URL or drawn by the script; a `fetch`, an external image or font is blocked and reported;
- honest limit: a script can still navigate its own frame (and so put data into a URL). The frame holds only the
  block's own data, which the customer already sees, and no credentials.

## Data structure — DATA-COMPLETE
```ts
interface CustomVisualizationPayload {
  title?: string;        // the card title (the owner's language); not repeated inside the html
  description?: string;  // one caption line under the title
  html: string;          // the markup inside the frame's root (no <script> — it would not run)
  css?: string;          // the styles; the frame's body is transparent, the visualization paints its own background
  script?: string;       // the JavaScript; reads window.AGENTS_VISUALIZATION.data; html or script is required
  libraries?: string[];  // from the allow-list only: "d3" (v7.6.1, global `d3`)
  data?: unknown;        // any JSON the script draws — every value with its source in the prose
  height?: number;       // the frame's height until it reports its own (80–1200, default 360)
}
```
At most 65,536 bytes of `html` + `css` + `script` + the JSON of `data` (UTF-8); a larger block is not run. A block
with neither `html` nor `script`, or with a library outside the allow-list, is not run either.

Concrete payload example:
```widget
{"type": "CustomVisualization", "title": "Solar System Explorer", "description": "Interactive 3D projection · illustrative scales", "html": "<canvas id=\"orbits\"></canvas><input id=\"speed\" type=\"range\" min=\"0\" max=\"3\" step=\"0.1\" value=\"1\">", "css": "canvas{display:block;width:100%;aspect-ratio:16/10;background:#0b1020}", "script": "var bodies = window.AGENTS_VISUALIZATION.data.bodies; /* draw the orbits on the canvas, animate with requestAnimationFrame */", "data": {"bodies": [{"name": "Earth", "orbitRadius": 94, "size": 6.5, "color": "#4c9dff", "speed": 1}]}, "height": 420}
```

## When to use
- Only when no ComposedView node can express the picture: an animation on a canvas, a custom projection or a small simulation the owner explores with sliders or the mouse. A table, a chart, KPI tiles, a map, a list or a form are ComposedView nodes, never this widget. One short line first, then the widget; the prose carries the key statement, because a visualization that fails to load shows nothing. Never for live data the script would fetch (it cannot), never for demo numbers in a customer answer.

## States
- loading: a skeleton in the frame's shape until the script ran, reported `pending`;
- ready: the frame at the height it reports, reported `mounted`;
- a block that is not run (no content, too large, a library outside the allow-list): one muted line, reported `failed`
  (`INVALID_PROP` / `BUDGET_EXCEEDED`);
- a script error: what rendered stays, one muted line under it, reported `failed` (`RENDER_FAILED` with the error line);
- a frame that stops beating for 5 s (an endless loop) or is not ready after 15 s: the frame is removed, one muted line,
  reported `failed` (`RENDER_FAILED`);
- e-mail: a static preview (title, caption, a tile saying the visualization is interactive) — no frame, no script.

## Where the frame page is served
`sandbox-frame/custom-visualization-frame.html` is a self-contained static page our build emits
(`app-build/assets/custom-visualization-frame-<hash>.html`). `shared/custom-visualization-sandbox-config.ts`
(`frameUrl`) points the widget at a copy of the same file on a host of another registrable domain — then every browser
with site isolation runs the frame in a process of its own, and an endless loop cannot freeze the chat before the
watchdog removes the frame.

## ASCII layout
```
┌──────────────────────────────────────────────┐
│ Solar System Explorer                        │
│ Interactive 3D projection · illustrative …   │
│ ┌──────────────────────────────────────────┐ │
│ │      (the model's own canvas, sliders,   │ │
│ │       buttons — inside the sandbox)      │ │
│ └──────────────────────────────────────────┘ │
└──────────────────────────────────────────────┘
```
