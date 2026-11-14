import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, test } from "vitest";
import { CUSTOM_VISUALIZATION_LIBRARIES } from "../shared/custom-visualization-libraries";

/**
 * The sandbox frame page and the library bundles are security-relevant static files: the policy must stay
 * exactly the one the frame declares, its script hash must be the hash of the page's one inline script (an edit of the bootstrap
 * without a new hash would leave the frame without a working script), the page must reference nothing outside itself,
 * and every allow-listed bundle must be the file whose SHA-256 is pinned.
 */
const SANDBOX_FRAME_FOLDER = resolve(__dirname);
const frameHtml = readFileSync(resolve(SANDBOX_FRAME_FOLDER, "custom-visualization-frame.html"), "utf8");

const readContentSecurityPolicy = (html: string): string | undefined =>
  /<meta http-equiv="Content-Security-Policy" content="([^"]+)">/.exec(html)?.[1];

describe("custom-visualization-frame.html", () => {
  test("carries the sandbox policy: nothing from the network, scripts only by the bootstrap's hash and blob:", () => {
    const contentSecurityPolicy = readContentSecurityPolicy(frameHtml);
    expect(contentSecurityPolicy).toMatch(
      /^default-src 'none'; script-src 'sha256-[A-Za-z0-9+/=]+' blob:; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; form-action 'none'; base-uri 'none'$/,
    );
  });

  test("the policy's hash is the hash of the one inline script", () => {
    const inlineScripts = [...frameHtml.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((match) => match[1]);
    expect(inlineScripts).toHaveLength(1);
    const bootstrapHash = createHash("sha256").update(inlineScripts[0], "utf8").digest("base64");
    expect(readContentSecurityPolicy(frameHtml)).toContain(`'sha256-${bootstrapHash}'`);
  });

  test("the policy is the first thing in the head and the page references no other file", () => {
    expect(frameHtml.indexOf("Content-Security-Policy")).toBeLessThan(frameHtml.indexOf("<script>"));
    expect(frameHtml).not.toMatch(/\s(src|href)=/);
  });

  test("is larger than the build's inline limit, so the build emits it as a file and never as a data: URL", () => {
    expect(Buffer.byteLength(frameHtml, "utf8")).toBeGreaterThan(4096);
  });
});

describe("the library allow-list", () => {
  test.each(CUSTOM_VISUALIZATION_LIBRARIES.map((library) => [library.name, library] as const))(
    "%s is the pinned file",
    (libraryName, library) => {
      const bundle = readFileSync(resolve(SANDBOX_FRAME_FOLDER, "libraries", library.fileName));
      expect(createHash("sha256").update(new Uint8Array(bundle)).digest("hex")).toBe(library.sha256);
    },
  );
});
