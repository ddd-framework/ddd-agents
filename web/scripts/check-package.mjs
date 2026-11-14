#!/usr/bin/env node
// Checks a package directory (this directory after `npm run build`, or an extracted tarball's `package/`):
//   1. every `exports` target exists
//   2. every relative import of a built file (JavaScript or declaration) stays inside the package
//   3. every other import of a built file names a package the manifest declares (dependencies, peerDependencies) or a
//      built-in module of the runtime — an application's path alias or private library is neither, so it fails here
//   4. the stylesheet imports nothing and points no `url()` at a file outside the package
//   5. every JavaScript file starts with the 'use client' directive
//   6. no spec, story or test file is in `dist`
//   7. every custom property a stylesheet reads (`var(--x…)`) is a package variable (`--agents-…`) or declared in the
//      same stylesheet — no rule depends on an app's theme variable
//
// Usage: node scripts/check-package.mjs <packageDir>
import * as fs from "node:fs";
import { builtinModules } from "node:module";
import * as path from "node:path";

const packageDir = path.resolve(process.argv[2] ?? ".");
const manifest = JSON.parse(fs.readFileSync(path.join(packageDir, "package.json"), "utf-8"));
const distDir = path.join(packageDir, "dist");
const problems = [];

const report = (isOk, label, problem) => {
  console.log(`${isOk ? "ok     " : "FAILED "} ${label}`);
  if (!isOk) problems.push(problem ?? label);
};

/** Every string leaf of an `exports` value (conditions and subpaths flattened). */
const readExportTargets = (value) =>
  typeof value === "string"
    ? [value]
    : value && typeof value === "object"
      ? Object.values(value).flatMap(readExportTargets)
      : [];

for (const target of readExportTargets(manifest.exports ?? {})) {
  const exists = fs.existsSync(path.join(packageDir, target));
  report(exists, `exports ${target}`, `missing exports target ${target}`);
}

if (!fs.existsSync(distDir)) {
  report(false, "dist exists", "no dist folder — run the build first");
} else {
  const distFiles = fs
    .readdirSync(distDir, { recursive: true })
    .map(String)
    .filter((file) => fs.statSync(path.join(distDir, file)).isFile());
  const codeFiles = distFiles.filter((file) => /\.(m?js|d\.ts)$/.test(file));
  const javaScriptFiles = distFiles.filter((file) => /\.m?js$/.test(file));

  // Static imports, re-exports, side-effect imports, dynamic imports and declaration type queries.
  const IMPORT_PATTERNS = [
    /(?:^|[\s;])(?:import|export)\s*(?:type\s+)?(?:[^'"()]*?\sfrom\s*)?['"]([^'"\n]+)['"]/g,
    /\bimport\(\s*['"]([^'"\n]+)['"]\s*\)/g,
  ];
  const readSpecifiers = (file) => {
    const text = fs.readFileSync(path.join(distDir, file), "utf-8");
    return IMPORT_PATTERNS.flatMap((pattern) => [...text.matchAll(pattern)].map((match) => match[1]));
  };
  const specifiersByFile = new Map(codeFiles.map((file) => [file, readSpecifiers(file)]));

  const declaredPackages = new Set([
    ...Object.keys(manifest.peerDependencies ?? {}),
    ...Object.keys(manifest.dependencies ?? {}),
  ]);
  const runtimeBuiltIns = new Set(builtinModules);
  /** The package a bare specifier names: `@scope/name` or `name`, without the subpath. */
  const readPackageName = (specifier) =>
    specifier.startsWith("@") ? specifier.split("/").slice(0, 2).join("/") : specifier.split("/")[0];
  const leavingImports = [];
  const undeclaredImports = [];
  const importedPackages = new Set();
  for (const [file, specifiers] of specifiersByFile) {
    for (const specifier of specifiers) {
      if (specifier.startsWith("./") || specifier.startsWith("../")) {
        const target = path.resolve(path.dirname(path.join(distDir, file)), specifier);
        if (!target.startsWith(packageDir + path.sep)) leavingImports.push(`${file} → ${specifier}`);
      } else if (specifier.startsWith("node:") || runtimeBuiltIns.has(specifier)) {
        continue;
      } else if (declaredPackages.has(readPackageName(specifier))) {
        importedPackages.add(readPackageName(specifier));
      } else {
        undeclaredImports.push(`${file} → ${specifier}`);
      }
    }
  }
  report(leavingImports.length === 0, "every relative import stays in the package", `imports leaving the package: ${leavingImports.join(", ")}`);
  report(
    undeclaredImports.length === 0,
    `every other import is a declared package or a runtime built-in (${[...importedPackages].sort().join(", ")})`,
    `imports of undeclared packages or paths: ${undeclaredImports.join(", ")}`,
  );

  const stylesheets = distFiles.filter((file) => file.endsWith(".css"));
  for (const stylesheet of stylesheets) {
    const css = fs.readFileSync(path.join(distDir, stylesheet), "utf-8");
    const cssImports = [...css.matchAll(/@import\s+[^;]+;/g)].map((match) => match[0]);
    const outsideUrls = [...css.matchAll(/url\(\s*['"]?([^'")]+)['"]?\s*\)/g)]
      .map((match) => match[1])
      .filter((url) => !url.startsWith("data:") && !url.startsWith("#") && !url.startsWith("./") && !/^[a-z]+:/.test(url));
    report(cssImports.length === 0, `${stylesheet} imports nothing`, `${stylesheet} imports: ${cssImports.join(" ")}`);
    report(outsideUrls.length === 0, `${stylesheet} points no url() outside the package`, `${stylesheet} urls: ${outsideUrls.join(", ")}`);
    const declaredProperties = new Set([...css.matchAll(/(?:^|[;{\s])(--[A-Za-z0-9_-]+)\s*:/g)].map((match) => match[1]));
    const foreignProperties = [
      ...new Set(
        [...css.matchAll(/var\(\s*(--[A-Za-z0-9_-]+)/g)]
          .map((match) => match[1])
          .filter((name) => !name.startsWith("--agents-") && !declaredProperties.has(name)),
      ),
    ].sort();
    report(
      foreignProperties.length === 0,
      `${stylesheet} reads only package variables or its own properties`,
      `${stylesheet} reads custom properties that are neither --agents-… nor declared in it: ${foreignProperties.join(", ")}`,
    );
  }

  const withoutDirective = javaScriptFiles.filter(
    (file) => !/^['"]use client['"];/.test(fs.readFileSync(path.join(distDir, file), "utf-8")),
  );
  report(withoutDirective.length === 0, "every .js file starts with 'use client'", `no 'use client' in ${withoutDirective.join(", ")}`);

  const testFiles = distFiles.filter((file) => /\.(?:spec|test|stories|story)\.|spec-helper|story-helper/i.test(file));
  report(testFiles.length === 0, "no spec, story or test file in dist", `test files in dist: ${testFiles.join(", ")}`);
}

if (problems.length > 0) {
  for (const problem of problems) console.error(`${packageDir}: ${problem}`);
  process.exit(1);
}
