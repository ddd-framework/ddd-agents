import { resolve } from "node:path";
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
};

/** A specifier that is not a file of the package: a peer, or an app alias the check reports. */
const isExternalSpecifier = (specifier) =>
  !specifier.startsWith(".") && !specifier.startsWith("/") && !specifier.startsWith("\0") && !/^[A-Za-z]:/.test(specifier);

export default defineConfig({
  root: packageDir,
  publicDir: false,
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
