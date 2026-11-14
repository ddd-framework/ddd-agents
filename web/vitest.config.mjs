import { defineConfig } from "vitest/config";

// The specs format dates, times and numbers with the runtime's defaults: one fixed time zone and locale make the run
// independent of the machine. Set before the test workers start, which inherit the environment.
process.env.TZ = "Europe/Berlin";
process.env.LANG = "en_US.UTF-8";
process.env.LC_ALL = "en_US.UTF-8";

export default defineConfig({
  // The automatic JSX runtime and the class-name convention of the SCSS modules, as `vite.config.mjs` builds them.
  esbuild: { jsx: "automatic" },
  css: { modules: { localsConvention: "camelCase" } },
  test: {
    globals: true,
    environment: "jsdom",
    setupFiles: ["./vitest.setup.mjs"],
    include: ["src/**/_*.{spec,test}.{ts,tsx}"],
  },
});
