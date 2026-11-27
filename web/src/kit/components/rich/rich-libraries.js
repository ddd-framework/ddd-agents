let katexPromise;
let highlighterPromise;
let mermaidPromise;
let domPurifyPromise;
const highlightLanguagePromises = {};
/** KaTeX with its stylesheet (the stylesheet loads the fonts it needs on demand). */
export const loadKatex = () => {
    katexPromise ??= Promise.all([import("katex"), import("katex/dist/katex.min.css")])
        .then(([katexModule]) => katexModule.default)
        .catch((error) => {
        katexPromise = undefined;
        throw error;
    });
    return katexPromise;
};
/** Mermaid (its diagram-type chunks load inside it, per diagram type). */
export const loadMermaid = () => {
    mermaidPromise ??= import("mermaid")
        .then((mermaidModule) => mermaidModule.default)
        .catch((error) => {
        mermaidPromise = undefined;
        throw error;
    });
    return mermaidPromise;
};
/** DOMPurify as a factory: each caller builds its own instance, so hooks never leak from one caller to another. */
export const loadDomPurify = () => {
    domPurifyPromise ??= import("dompurify")
        .then((domPurifyModule) => domPurifyModule.default)
        .catch((error) => {
        domPurifyPromise = undefined;
        throw error;
    });
    return domPurifyPromise;
};
/**
 * The highlight.js grammars the `Code` node can load, by grammar name. A closed list of static imports: each grammar
 * is its own chunk, and no name outside the list can be requested.
 */
const HIGHLIGHT_LANGUAGE_LOADERS = {
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
const HIGHLIGHT_LANGUAGE_ALIASES = {
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
export const resolveHighlightLanguage = (language) => {
    if (typeof language !== "string")
        return undefined;
    const lowercasedLanguage = language.trim().toLowerCase();
    const grammarName = Object.prototype.hasOwnProperty.call(HIGHLIGHT_LANGUAGE_ALIASES, lowercasedLanguage)
        ? HIGHLIGHT_LANGUAGE_ALIASES[lowercasedLanguage]
        : lowercasedLanguage;
    return Object.prototype.hasOwnProperty.call(HIGHLIGHT_LANGUAGE_LOADERS, grammarName) ? grammarName : undefined;
};
/** The highlight.js core, without any grammar. */
const loadHighlighter = () => {
    highlighterPromise ??= import("highlight.js/lib/core")
        .then((highlighterModule) => highlighterModule.default)
        .catch((error) => {
        highlighterPromise = undefined;
        throw error;
    });
    return highlighterPromise;
};
/** The highlight.js core with the grammar `grammarName` registered (a name {@link resolveHighlightLanguage} returned). */
export const loadHighlighterWithLanguage = async (grammarName) => {
    const highlighter = await loadHighlighter();
    if (!Object.prototype.hasOwnProperty.call(highlightLanguagePromises, grammarName)) {
        highlightLanguagePromises[grammarName] = HIGHLIGHT_LANGUAGE_LOADERS[grammarName]()
            .then((grammarModule) => {
            highlighter.registerLanguage(grammarName, grammarModule.default);
            return true;
        })
            .catch((error) => {
            delete highlightLanguagePromises[grammarName];
            throw error;
        });
    }
    await highlightLanguagePromises[grammarName];
    return highlighter;
};
