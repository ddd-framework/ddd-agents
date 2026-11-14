import type { HLJSApi, LanguageFn } from "highlight.js";
import type KatexModule from "katex";
import type { Mermaid } from "mermaid";
import type { DOMPurify } from "dompurify";

/**
 * The renderer libraries of the rich kit components, each behind a dynamic `import()`: the kit's
 * component files are imported eagerly by the folder registry, so a static import here would put KaTeX, highlight.js,
 * Mermaid and DOMPurify into the chat bundle. Each library loads once per page, on the first node that needs it, as
 * its own chunk; a failed load is forgotten so the next node retries.
 */

type Katex = typeof KatexModule;

let katexPromise: Promise<Katex> | undefined;
let highlighterPromise: Promise<HLJSApi> | undefined;
let mermaidPromise: Promise<Mermaid> | undefined;
let domPurifyPromise: Promise<DOMPurify> | undefined;
const highlightLanguagePromises: Record<string, Promise<boolean>> = {};

/** KaTeX with its stylesheet (the stylesheet loads the fonts it needs on demand). */
export const loadKatex = (): Promise<Katex> => {
  katexPromise ??= Promise.all([import("katex"), import("katex/dist/katex.min.css")])
    .then(([katexModule]) => katexModule.default)
    .catch((error: unknown) => {
      katexPromise = undefined;
      throw error;
    });
  return katexPromise;
};

/** Mermaid (its diagram-type chunks load inside it, per diagram type). */
export const loadMermaid = (): Promise<Mermaid> => {
  mermaidPromise ??= import("mermaid")
    .then((mermaidModule) => mermaidModule.default)
    .catch((error: unknown) => {
      mermaidPromise = undefined;
      throw error;
    });
  return mermaidPromise;
};

/** DOMPurify as a factory: each caller builds its own instance, so hooks never leak from one caller to another. */
export const loadDomPurify = (): Promise<DOMPurify> => {
  domPurifyPromise ??= import("dompurify")
    .then((domPurifyModule) => domPurifyModule.default)
    .catch((error: unknown) => {
      domPurifyPromise = undefined;
      throw error;
    });
  return domPurifyPromise;
};

/**
 * The highlight.js grammars the `Code` node can load, by grammar name. A closed list of static imports: each grammar
 * is its own chunk, and no name outside the list can be requested.
 */
const HIGHLIGHT_LANGUAGE_LOADERS: Record<string, () => Promise<{ default: LanguageFn }>> = {
  bash: () => import("highlight.js/lib/languages/bash"),
  c: () => import("highlight.js/lib/languages/c"),
  cpp: () => import("highlight.js/lib/languages/cpp"),
  csharp: () => import("highlight.js/lib/languages/csharp"),
  css: () => import("highlight.js/lib/languages/css"),
  diff: () => import("highlight.js/lib/languages/diff"),
  dockerfile: () => import("highlight.js/lib/languages/dockerfile"),
  go: () => import("highlight.js/lib/languages/go"),
  graphql: () => import("highlight.js/lib/languages/graphql"),
  ini: () => import("highlight.js/lib/languages/ini"),
  java: () => import("highlight.js/lib/languages/java"),
  javascript: () => import("highlight.js/lib/languages/javascript"),
  json: () => import("highlight.js/lib/languages/json"),
  kotlin: () => import("highlight.js/lib/languages/kotlin"),
  markdown: () => import("highlight.js/lib/languages/markdown"),
  php: () => import("highlight.js/lib/languages/php"),
  plaintext: () => import("highlight.js/lib/languages/plaintext"),
  python: () => import("highlight.js/lib/languages/python"),
  ruby: () => import("highlight.js/lib/languages/ruby"),
  rust: () => import("highlight.js/lib/languages/rust"),
  scss: () => import("highlight.js/lib/languages/scss"),
  shell: () => import("highlight.js/lib/languages/shell"),
  sql: () => import("highlight.js/lib/languages/sql"),
  swift: () => import("highlight.js/lib/languages/swift"),
  typescript: () => import("highlight.js/lib/languages/typescript"),
  xml: () => import("highlight.js/lib/languages/xml"),
  yaml: () => import("highlight.js/lib/languages/yaml"),
};

/** The names a model writes for a language, mapped to the grammar name. */
const HIGHLIGHT_LANGUAGE_ALIASES: Record<string, string> = {
  "c++": "cpp",
  "c#": "csharp",
  cs: "csharp",
  docker: "dockerfile",
  html: "xml",
  js: "javascript",
  jsx: "javascript",
  md: "markdown",
  py: "python",
  rb: "ruby",
  rs: "rust",
  sh: "bash",
  svg: "xml",
  text: "plaintext",
  toml: "ini",
  ts: "typescript",
  tsx: "typescript",
  txt: "plaintext",
  yml: "yaml",
  zsh: "bash",
};

/** The grammar name for what the model wrote (`"PHP"`, `"ts"`, `"html"`), or undefined when no grammar fits. */
export const resolveHighlightLanguage = (language: unknown): string | undefined => {
  if (typeof language !== "string") return undefined;
  const lowercasedLanguage = language.trim().toLowerCase();
  const grammarName = Object.prototype.hasOwnProperty.call(HIGHLIGHT_LANGUAGE_ALIASES, lowercasedLanguage)
    ? HIGHLIGHT_LANGUAGE_ALIASES[lowercasedLanguage]
    : lowercasedLanguage;
  return Object.prototype.hasOwnProperty.call(HIGHLIGHT_LANGUAGE_LOADERS, grammarName) ? grammarName : undefined;
};

/** The highlight.js core, without any grammar. */
const loadHighlighter = (): Promise<HLJSApi> => {
  highlighterPromise ??= import("highlight.js/lib/core")
    .then((highlighterModule) => highlighterModule.default)
    .catch((error: unknown) => {
      highlighterPromise = undefined;
      throw error;
    });
  return highlighterPromise;
};

/** The highlight.js core with the grammar `grammarName` registered (a name {@link resolveHighlightLanguage} returned). */
export const loadHighlighterWithLanguage = async (grammarName: string): Promise<HLJSApi> => {
  const highlighter = await loadHighlighter();
  if (!Object.prototype.hasOwnProperty.call(highlightLanguagePromises, grammarName)) {
    highlightLanguagePromises[grammarName] = HIGHLIGHT_LANGUAGE_LOADERS[grammarName]()
      .then((grammarModule) => {
        highlighter.registerLanguage(grammarName, grammarModule.default);
        return true;
      })
      .catch((error: unknown) => {
        delete highlightLanguagePromises[grammarName];
        throw error;
      });
  }
  await highlightLanguagePromises[grammarName];
  return highlighter;
};
