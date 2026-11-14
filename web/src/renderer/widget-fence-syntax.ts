/**
 * WIRE SYNTAX — the ```widget fence and bare `widget { … }` forms, as import-free constants.
 *
 * Deliberately a leaf module (no imports): `widget-name-resolver.ts` reads the registry at module scope, and the
 * registry pulls in every widget → AgentChat → the unrendered-widget detector, which builds a RegExp from
 * WIDGET_FENCE_LANG at ITS module scope. When a story loads a widget before anything has evaluated the resolver, that
 * chain reaches the detector while the resolver is still in its temporal dead zone ("Cannot access 'WIDGET_FENCE_LANG'
 * before initialization"). Keeping the syntax here, outside the registry cycle, makes evaluation order irrelevant.
 */

/**
 * The ```widget fence's info-string language — the ONE definition every fence regex in the pipeline (the render
 * plugin's `node.lang` check, the malformed-fence repairer, the drawer-reset scan, the unrendered-widget detector)
 * is built from, so "``` widget" (a space before the lang — remark accepts it, the model sometimes writes it) is
 * recognized identically everywhere.
 */
export const WIDGET_FENCE_LANG = "widget";

/**
 * A bare `widget { …json… }` line (no ```widget fence) that IS the whole paragraph — the fallback form the model
 * emits when it drops the backticks. Capture group 1 is the JSON object. Anchored to the whole paragraph text so a
 * prose mention ("the widget shows…") never matches. Shared by the render plugin (rewrites it) so there is one
 * definition of the bare form. The detector uses {@link BARE_WIDGET_LINE_GLOBAL} to scan a raw message the same way.
 */
export const BARE_WIDGET_PARAGRAPH = /^\s*widget\s*(\{[\s\S]*\})\s*$/;

/** The bare `widget { … }` form scanned line-by-line across a raw message string (detector side of {@link BARE_WIDGET_PARAGRAPH}). */
export const BARE_WIDGET_LINE_GLOBAL = /^[ \t]*widget[ \t]*(\{.*\})[ \t]*$/gm;
