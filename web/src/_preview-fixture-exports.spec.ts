import * as fs from "node:fs";
import * as path from "node:path";
import { describe, expect, test } from "vitest";

/**
 * Every preview fixture file of the package's widgets and kit components is published: `package.json` lists it under
 * `exports`, pointing at its copy below `dist/files/`. A host that reads the shipped files (a widget inventory, a
 * comparison manifest) sees only what is exported; the preview bundle finds the files by glob and would not notice.
 */

const packageDir = path.resolve(__dirname, "..");
const sourceDir = path.join(packageDir, "src");
const fixtureFileSuffix = ".preview-fixtures.json";

const findFixtureFilesBelow = (directory: string): string[] => {
  const fixtureFiles: string[] = [];
  for (const directoryEntry of fs.readdirSync(directory, { withFileTypes: true })) {
    const entryPath = path.join(directory, directoryEntry.name);
    if (directoryEntry.isDirectory()) {
      fixtureFiles.push(...findFixtureFilesBelow(entryPath));
    } else if (directoryEntry.name.endsWith(fixtureFileSuffix)) {
      fixtureFiles.push(path.relative(sourceDir, entryPath).split(path.sep).join("/"));
    }
  }
  return fixtureFiles;
};

describe("the preview fixtures of the package", () => {
  const packageExports = JSON.parse(fs.readFileSync(path.join(packageDir, "package.json"), "utf-8")).exports as Record<string, unknown>;

  test.each(["widgets", "kit"])("every fixture file below src/%s is exported to its dist/files copy", (folder) => {
    const unexportedFixtureFiles = findFixtureFilesBelow(path.join(sourceDir, folder)).filter(
      (fixtureFile) => packageExports[`./${fixtureFile}`] !== `./dist/files/${fixtureFile}`,
    );
    expect(unexportedFixtureFiles).toEqual([]);
  });
});
