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
//   8. every `exports` target lies inside what `files` publishes, and every top-level built entry (`dist/<name>.js`)
//      is the target of a declared subpath — nothing is built as an entry that no subpath exports
//   9. every code subpath (one with an `import` condition) has its declared names in `public-api.json`, and every
//      declared name is exported by the built entry: a value by the JavaScript entry AND its declaration, a type by the
//      declaration. A name an entry exports beyond the declared ones is listed, not failed; a declared subpath that
//      `exports` no longer has fails.
//
// Usage: node scripts/check-package.mjs <packageDir>
//        node scripts/check-package.mjs <packageDir> --write-public-api   (rewrites public-api.json from the build)
import * as fs from "node:fs";
import { builtinModules } from "node:module";
import * as path from "node:path";

const packageDir = path.resolve(process.argv[2] ?? ".");
const writesPublicApi = process.argv.includes("--write-public-api");
const PUBLIC_API_FILE = "public-api.json";
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

// 8. `files` covers every target; every top-level built entry is a declared target.
const publishedRoots = (manifest.files ?? []).map((entry) => entry.replace(/^\.\//, "").replace(/\/$/, ""));
const isPublished = (target) => {
  const relativeTarget = target.replace(/^\.\//, "");
  return (
    relativeTarget === "package.json" ||
    publishedRoots.some((root) => relativeTarget === root || relativeTarget.startsWith(`${root}/`))
  );
};
const unpublishedTargets = readExportTargets(manifest.exports ?? {}).filter((target) => !isPublished(target));
report(unpublishedTargets.length === 0, "every exports target is inside `files`", `exports targets outside \`files\`: ${unpublishedTargets.join(", ")}`);
if (fs.existsSync(distDir)) {
  const declaredTargets = new Set(readExportTargets(manifest.exports ?? {}).map((target) => path.join(packageDir, target)));
  const undeclaredEntries = fs
    .readdirSync(distDir)
    .filter((file) => file.endsWith(".js") && !declaredTargets.has(path.join(distDir, file)));
  report(undeclaredEntries.length === 0, "every built entry is a declared subpath", `built entries no subpath exports: ${undeclaredEntries.join(", ")}`);
}

// 9. The declared names of every code subpath against the built entry.
/** The names one `export { a, b as c } [from "…"]` list of a built file exports. */
const readExportListNames = (list) =>
  list
    .split(",")
    .map((part) => part.trim().replace(/^type\s+/, ""))
    .filter((part) => part.length > 0)
    .map((part) => (part.split(/\s+as\s+/)[1] ?? part.split(/\s+as\s+/)[0]).trim());

/** The names a built JavaScript entry exports (Rollup writes export lists and re-export lists). */
const readJavaScriptExportNames = (file) => {
  const text = fs.readFileSync(file, "utf-8");
  return new Set([...text.matchAll(/(?:^|[;\n])\s*export\s*\{([^}]*)\}/g)].flatMap((match) => readExportListNames(match[1])));
};

/** The declaration file a relative specifier of a declaration names. */
const resolveDeclarationFile = (fromFile, specifier) => {
  const base = path.resolve(path.dirname(fromFile), specifier.replace(/\.js$/, ""));
  return [`${base}.d.ts`, path.join(base, "index.d.ts")].find((candidate) => fs.existsSync(candidate));
};

/** The names a declaration file exports, following `export * from` (declarations, export lists, re-exports). */
const readDeclarationExportNames = (file, visitedFiles = new Set()) => {
  if (!file || visitedFiles.has(file)) return new Set();
  visitedFiles.add(file);
  const text = fs.readFileSync(file, "utf-8");
  const names = new Set();
  const declarationPattern =
    /export\s+(?:declare\s+)?(?:default\s+)?(?:abstract\s+)?(?:const|let|var|function|class|type|interface|enum|namespace)\s+([A-Za-z0-9_$]+)/g;
  for (const match of text.matchAll(declarationPattern)) names.add(match[1]);
  for (const match of text.matchAll(/export\s+(?:type\s+)?\{([^}]*)\}/g)) for (const name of readExportListNames(match[1])) names.add(name);
  for (const match of text.matchAll(/export\s+\*\s+from\s+["']([^"']+)["']/g)) {
    for (const name of readDeclarationExportNames(resolveDeclarationFile(file, match[1]), visitedFiles)) names.add(name);
  }
  return names;
};

const codeSubpaths = Object.entries(manifest.exports ?? {}).filter(
  ([, target]) => target && typeof target === "object" && typeof target.import === "string",
);
const builtSurface = Object.fromEntries(
  codeSubpaths
    .filter(([, target]) => fs.existsSync(path.join(packageDir, target.import)) && fs.existsSync(path.join(packageDir, target.types)))
    .map(([subpath, target]) => {
      const valueNames = readJavaScriptExportNames(path.join(packageDir, target.import));
      const declaredNames = readDeclarationExportNames(path.join(packageDir, target.types));
      return [subpath, { values: [...valueNames].sort(), types: [...declaredNames].filter((name) => !valueNames.has(name)).sort(), declaredNames }];
    }),
);
const publicApiPath = path.join(packageDir, PUBLIC_API_FILE);
if (writesPublicApi) {
  const publicApi = Object.fromEntries(Object.entries(builtSurface).map(([subpath, { values, types }]) => [subpath, { values, types }]));
  fs.writeFileSync(publicApiPath, `${JSON.stringify(publicApi, null, 2)}\n`);
  console.log(`wrote ${PUBLIC_API_FILE} (${Object.keys(publicApi).length} code subpaths)`);
}
if (!fs.existsSync(publicApiPath)) {
  report(false, `${PUBLIC_API_FILE} exists`, `no ${PUBLIC_API_FILE}: run the check with --write-public-api after a deliberate API change`);
} else {
  const publicApi = JSON.parse(fs.readFileSync(publicApiPath, "utf-8"));
  for (const [subpath] of codeSubpaths) {
    const declared = publicApi[subpath];
    const built = builtSurface[subpath];
    if (!declared) {
      report(false, `${subpath} declares its names`, `${subpath} has no entry in ${PUBLIC_API_FILE}`);
      continue;
    }
    if (!built) continue; // the missing target is reported by check 1
    const builtValues = new Set(built.values);
    const missingValues = declared.values.filter((name) => !builtValues.has(name) || !built.declaredNames.has(name));
    const missingTypes = declared.types.filter((name) => !built.declaredNames.has(name));
    const missingNames = [...missingValues, ...missingTypes];
    report(
      missingNames.length === 0,
      `${subpath} exports its ${declared.values.length} declared values and ${declared.types.length} declared types`,
      `${subpath} lacks declared names: ${missingNames.join(", ")}`,
    );
    const declaredNameSet = new Set([...declared.values, ...declared.types]);
    const extraNames = [...built.values, ...built.types].filter((name) => !declaredNameSet.has(name));
    if (extraNames.length > 0) console.log(`note    ${subpath} exports names ${PUBLIC_API_FILE} does not declare: ${extraNames.join(", ")}`);
  }
  const staleSubpaths = Object.keys(publicApi).filter((subpath) => !codeSubpaths.some(([codeSubpath]) => codeSubpath === subpath));
  report(staleSubpaths.length === 0, `every subpath of ${PUBLIC_API_FILE} is exported`, `${PUBLIC_API_FILE} declares subpaths exports lacks: ${staleSubpaths.join(", ")}`);
}

if (problems.length > 0) {
  for (const problem of problems) console.error(`${packageDir}: ${problem}`);
  process.exit(1);
}
