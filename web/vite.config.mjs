import { existsSync, readFileSync } from "node:fs";
import { join, posix, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { defineConfig } from "vite";

// The package build. Vite library mode, not tsup: tsup is not installed in the app's node_modules,
// and Vite is the one tool here that compiles the SCSS modules (Sass + the CSS-modules transform), emits their class
// maps into the JavaScript, writes ONE stylesheet, and resolves `import.meta.glob`, `?raw` and `?url` at build time.
// Every entry is built in one pass, so what two subpaths share (the provider's context, the registry) lands in a shared
// chunk and exists once. Every import that is not a file of the package stays external; `scripts/check-package.mjs`
// fails the build when one of them is an app alias.

const packageDir = fileURLToPath(new URL(".", import.meta.url));
const sourceDir = resolve(packageDir, "src");

/** Published subpath entry name → source file. `scripts/check-package.mjs` checks `exports` against the output. */
const ENTRIES = {
  index: "entries/index.ts",
  renderer: "entries/renderer.ts",
  kit: "entries/kit.ts",
  widgets: "entries/widgets.ts",
  contract: "entries/contract.ts",
  client: "entries/client.ts",
  "client-testing": "entries/client-testing.ts",
  chat: "entries/chat.ts",
  preview: "entries/preview.ts",
  "image-render": "entries/image-render.ts",
  provider: "entries/provider.ts",
};

/**
 * The non-code files an app reads (widget documentation, preview fixtures, contracts, the node schema, the sandbox page,
 * SCSS tokens and modules): each is one subpath of `exports` whose target is `./dist/files/<path below src>`. The
 * manifest is the one list; the build copies each named file (and the SCSS partials a copied stylesheet imports, so
 * its relative imports resolve in the published folder) and nothing else.
 */
const FILES_TARGET_PREFIX = "./dist/files/";
const manifest = JSON.parse(readFileSync(resolve(packageDir, "package.json"), "utf-8"));

/** Every source path below `src` that `exports` names under `./dist/files/`. */
const readExportedFilePaths = () =>
  Object.values(manifest.exports)
    .filter((target) => typeof target === "string" && target.startsWith(FILES_TARGET_PREFIX))
    .map((target) => target.slice(FILES_TARGET_PREFIX.length));

/** The partial a Sass `@import` / `@use` / `@forward` of `specifier` names, relative to `fromPath` (both below `src`). */
const resolveScssPartialPath = (fromPath, specifier) => {
  const joined = posix.join(posix.dirname(fromPath), specifier);
  const candidates = [joined, `${joined}.scss`, posix.join(posix.dirname(joined), `_${posix.basename(joined)}.scss`)];
  return candidates.find((candidate) => candidate.endsWith(".scss") && existsSync(join(sourceDir, candidate)));
};

/**
 * The relative stylesheets one stylesheet reads: the partials of its `@import` / `@use` / `@forward` (built-in `sass:`
 * modules and package imports are not files here) and the module of a CSS-modules `composes: … from "…"`.
 */
const readScssPartialPaths = (scssPath) => {
  const text = readFileSync(join(sourceDir, scssPath), "utf-8");
  const specifiers = [
    ...[...text.matchAll(/@(?:import|use|forward)\s+["']([^"']+)["']/g)].map((match) => match[1]),
    ...[...text.matchAll(/composes\s*:[^;]*?\sfrom\s+["']([^"']+)["']/g)].map((match) => match[1]),
  ];
  return specifiers
    .filter((specifier) => specifier.startsWith("."))
    .map((specifier) => resolveScssPartialPath(scssPath, specifier))
    .filter((partialPath) => partialPath !== undefined);
};

/** The exported files plus, transitively, every partial an exported stylesheet imports. */
const collectPublishedFilePaths = () => {
  const paths = new Set(readExportedFilePaths());
  const pendingStylesheets = [...paths].filter((path) => path.endsWith(".scss"));
  while (pendingStylesheets.length > 0) {
    for (const partialPath of readScssPartialPaths(pendingStylesheets.pop())) {
      if (paths.has(partialPath)) continue;
      paths.add(partialPath);
      pendingStylesheets.push(partialPath);
    }
  }
  return [...paths].sort();
};

/** Emits the published files into `dist/files/` at their path below `src`. */
const publishedFilesPlugin = () => ({
  name: "agents-published-files",
  generateBundle() {
    for (const filePath of collectPublishedFilePaths()) {
      const sourcePath = join(sourceDir, filePath);
      if (!existsSync(sourcePath)) this.error(`exports names ${FILES_TARGET_PREFIX}${filePath}, but src/${filePath} does not exist`);
      this.emitFile({ type: "asset", fileName: `files/${filePath}`, source: readFileSync(sourcePath) });
    }
  },
});

/** A specifier that is not a file of the package: a peer, or an app alias the check reports. */
const isExternalSpecifier = (specifier) =>
  !specifier.startsWith(".") && !specifier.startsWith("/") && !specifier.startsWith("\0") && !/^[A-Za-z]:/.test(specifier);

export default defineConfig({
  root: packageDir,
  publicDir: false,
  plugins: [publishedFilesPlugin()],
  // The automatic JSX runtime, as the app compiles it (`jsx: "react-jsx"`); no Babel, no React compiler.
  esbuild: { jsx: "automatic" },
  css: {
    modules: { localsConvention: "camelCase", generateScopedName: "agents_[local]_[hash:base64:6]" },
    preprocessorOptions: { scss: { api: "modern-compiler" } },
  },
  build: {
    outDir: resolve(packageDir, "dist"),
    emptyOutDir: true,
    target: "es2022",
    minify: false,
    sourcemap: true,
    // One stylesheet for every entry: an app imports `@mgamadeus/ddd-agents/styles.css` once.
    cssCodeSplit: false,
    lib: {
      entry: Object.fromEntries(Object.entries(ENTRIES).map(([name, file]) => [name, resolve(sourceDir, file)])),
      formats: ["es"],
    },
    rollupOptions: {
      external: isExternalSpecifier,
      output: {
        entryFileNames: "[name].js",
        chunkFileNames: "chunks/[name]-[hash].js",
        assetFileNames: (assetInfo) => (assetInfo.name?.endsWith(".css") ? "styles.css" : "assets/[name]-[hash][extname]"),
        // Package code is client code (context, hooks, DOM); bundling drops a source's own directive.
        banner: "'use client';",
      },
    },
  },
});
