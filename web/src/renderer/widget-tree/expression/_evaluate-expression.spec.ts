import { describe, expect, it } from "vitest";
import { composedNodeSchema } from "../composed-node-schema";
import { compileExpression } from "./parse-expression";
import {
  computeComposedViewValues,
  evaluateExpression,
  ExpressionBudget,
  ExpressionValue,
} from "./evaluate-expression";

const budgets = composedNodeSchema.budgets;
const evaluate = (source: string, names: Record<string, ExpressionValue> = {}) =>
  evaluateExpression(
    source,
    (name) => (Object.prototype.hasOwnProperty.call(names, name) ? names[name] : undefined),
    new ExpressionBudget(budgets),
  );
const valueOf = (source: string, names: Record<string, ExpressionValue> = {}) => {
  const result = evaluate(source, names);
  return result.ok ? result.value : `ERROR:${result.errorCode}`;
};

const rows = [
  { x: "Mo", organic: 500, paid: 300, rate: 3.5 },
  { x: "Di", organic: 580, paid: 340, rate: 4.0 },
  { x: "Mi", organic: 520, paid: 320, rate: 3.8 },
];

describe("the ComposedView expression language — closed, budgeted, never eval", () => {
  it("computes arithmetic, comparisons, logic and the ternary", () => {
    expect(valueOf("1 + 2 * 3")).toBe(7);
    expect(valueOf("(1 + 2) * 3")).toBe(9);
    expect(valueOf("10 % 4")).toBe(2);
    expect(valueOf("-scale + 5", { scale: 2 })).toBe(3);
    expect(valueOf('period == "7d" ? "week" : "month"', { period: "7d" })).toBe("week");
    expect(valueOf("a > 1 && b < 5", { a: 2, b: 4 })).toBe(true);
    expect(valueOf("!flag", { flag: false })).toBe(true);
    expect(valueOf('"Visits: " + 12')).toBe("Visits: 12");
  });

  it("gives empty for a missing value, a division by zero and a non-finite result", () => {
    expect(valueOf("nope + 1")).toBeUndefined();
    expect(valueOf("1 / 0")).toBeUndefined();
    expect(valueOf("5 % 0")).toBeUndefined();
    expect(valueOf("a * b", { a: 1e308, b: 1e308 })).toBeUndefined();
    expect(valueOf("a < 1", { a: "x" })).toBeUndefined();
    expect(valueOf("row.missing", { row: { a: 1 } })).toBeUndefined();
  });

  it("reads own properties of JSON values only", () => {
    expect(valueOf("row.organic", { row: rows[0] })).toBe(500);
    expect(valueOf('row["paid"]', { row: rows[0] })).toBe(300);
    expect(valueOf("list[1]", { list: [10, 20] })).toBe(20);
    expect(valueOf("list.length", { list: [10, 20] })).toBe(2);
    expect(valueOf("row.constructor", { row: {} })).toBeUndefined();
    expect(valueOf('row["__proto__"]', { row: {} })).toBeUndefined();
    expect(valueOf("row.toString", { row: {} })).toBeUndefined();
    expect(valueOf("text.length", { text: "abc" })).toBeUndefined();
  });

  it("runs the closed functions with lambdas", () => {
    expect(valueOf("sum(rows, r => r.organic + r.paid)", { rows })).toBe(2560);
    expect(valueOf("round(avg(rows, r => r.rate), 2)", { rows })).toBe(3.77);
    expect(valueOf("count(rows, r => r.rate > 3.7)", { rows })).toBe(2);
    expect(valueOf("map(rows, r => r.x)", { rows })).toEqual(["Mo", "Di", "Mi"]);
    expect(valueOf("map(filter(rows, r => r.organic > 510), (r, i) => {x: r.x, i: i})", { rows })).toEqual([
      { x: "Di", i: 0 },
      { x: "Mi", i: 1 },
    ]);
    expect(valueOf('map(sortBy(rows, r => r.rate, "desc"), r => r.x)', { rows })).toEqual(["Di", "Mi", "Mo"]);
    expect(valueOf("max(rows, r => r.paid)", { rows })).toBe(340);
    expect(valueOf("min(4, 2, 9)")).toBe(2);
    expect(valueOf("percent(1, 3, 1)")).toBe(33.3);
    expect(valueOf("fixed(3.14159, 2)")).toBe("3.14");
    expect(valueOf('join(["a", "b"], " · ")')).toBe("a · b");
    expect(valueOf("len(concat([1], [2, 3]))")).toBe(3);
  });

  it("refuses everything that is not the closed language", () => {
    for (const source of [
      "alert(1)",
      "rows.map(r => r)",
      "a = 1",
      "x => x",
      "fetch('x')",
      "a; b",
      "`tpl`",
      "new Date()",
    ]) {
      expect(compileExpression(source, budgets.maxExpressionAstNodes).ok && valueOf(source) !== undefined, source).toBe(
        false,
      );
    }
    expect(compileExpression("unknownFn(1)", budgets.maxExpressionAstNodes).ok).toBe(false);
  });

  it("caps the AST, the operations of one update and the collection size", () => {
    expect(compileExpression(Array(300).fill("1").join(" + "), budgets.maxExpressionAstNodes).ok).toBe(false);
    const big = Array.from({ length: 900 }, (_, index) => index);
    expect(valueOf("sum(map(big, a => sum(map(big, b => a * b))))", { big })).toBe("ERROR:BUDGET_EXCEEDED");
    expect(valueOf("count(huge)", { huge: Array.from({ length: budgets.maxCollectionItems + 1 }, () => 1) })).toBe(
      "ERROR:BUDGET_EXCEEDED",
    );
  });
});

describe("computeComposedViewValues — dependency order, cycles, one shared budget", () => {
  it("evaluates computed values over state, datasets and each other", () => {
    const result = computeComposedViewValues(
      {
        total: "sum(plotted, p => p.visits)",
        plotted: "map(rows, r => {x: r.x, visits: round(r.organic * scale / 100)})",
      },
      { scale: 50 },
      { rows },
      budgets,
    );
    expect(result.errors).toEqual({});
    expect(result.values.total).toBe(800);
    expect(result.values.plotted).toEqual([
      { x: "Mo", visits: 250 },
      { x: "Di", visits: 290 },
      { x: "Mi", visits: 260 },
    ]);
  });

  it("leaves a cycle and an invalid expression empty with INVALID_EXPRESSION and keeps the rest", () => {
    const result = computeComposedViewValues({ a: "b + 1", b: "a + 1", ok: "2 * 2", broken: "1 +" }, {}, {}, budgets);
    expect(result.values.ok).toBe(4);
    expect(result.errors.a?.errorCode).toBe("INVALID_EXPRESSION");
    expect(result.errors.b?.errorCode).toBe("INVALID_EXPRESSION");
    expect(result.errors.broken?.errorCode).toBe("INVALID_EXPRESSION");
  });
});
