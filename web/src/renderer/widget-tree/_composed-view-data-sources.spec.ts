import { describe, expect, test } from "vitest";
import {
  collectComposedViewSourceBindings,
  readRowsAtPath,
  resolveComposedViewSourceParams,
} from "./composed-view-data-sources";
import { readComposedViewTree } from "./composed-view-tree";
import type { ComposedNodeSchema } from "./composed-node-schema";
import parityFixture from "./composed-view-parity.json";

/** The `$source` plumbing: rows at a path, the distinct bindings of a tree, state-bound params. */

const stubSchema = parityFixture.stubSchema as unknown as ComposedNodeSchema;

describe("composed-view data sources", () => {
  test("reads the rows at the schema rowsPath and refuses a path without a list", () => {
    expect(readRowsAtPath({ rankings: [{ position: 1 }] }, "$.rankings")).toEqual([{ position: 1 }]);
    expect(readRowsAtPath([{ position: 2 }], "$")).toEqual([{ position: 2 }]);
    expect(readRowsAtPath({ a: { b: [1] } }, "$.a.b")).toEqual([1]);
    expect(readRowsAtPath({ rankings: { position: 1 } }, "$.rankings")).toBeUndefined();
    expect(readRowsAtPath({ constructor: [1] }, "$.toString")).toBeUndefined();
  });

  test("collects each distinct binding once, from datasets and node props", () => {
    const tree = readComposedViewTree(
      {
        state: { period: "7d" },
        data: {
          a: { $source: { name: "rankings", params: { days: 30 } } },
          b: { $source: { name: "rankings", params: { days: 30 } } },
          c: { $source: { name: "rankings", params: { period: { $state: "period" } } } },
        },
        view: { type: "StubText", text: { $source: { name: "rankings" } } },
      },
      stubSchema,
    );
    const bindings = collectComposedViewSourceBindings(tree);
    expect(bindings.map((binding) => binding.bindingKey)).toEqual([
      'rankings|{"days":30}',
      'rankings|{"period":{"$state":"period"}}',
      "rankings|{}",
    ]);
  });

  test("resolves state-bound parameters against the instance state", () => {
    expect(resolveComposedViewSourceParams({ days: 30, period: { $state: "period" } }, { period: "30d" })).toEqual({
      days: 30,
      period: "30d",
    });
    expect(resolveComposedViewSourceParams({ period: { $state: "missing" } }, {})).toEqual({ period: undefined });
  });
});
