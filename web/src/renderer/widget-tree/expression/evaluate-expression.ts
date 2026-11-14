import type { ComposedViewBudgets } from "../composed-node-schema";
import { collectExpressionNames, compileExpression, ExpressionNode } from "./parse-expression";

/**
 * The evaluator of the `ComposedView` expression language. Values are JSON values; one value
 * means "empty": `undefined` — a missing name or member, a division by zero, a non-finite result, an operation on an
 * empty or wrongly typed operand all give it, and it renders as nothing. Member access reads OWN properties of arrays and
 * plain objects only (never `__proto__`, `constructor`, `prototype`, never a method). Every evaluated node and every
 * element a function walks is charged against ONE operation budget per state update (`maxOperationsPerUpdate`), so a
 * nested `map` / `filter` / `sortBy` cannot run away; collections are capped (`maxCollectionItems`), the elements
 * allocated for intermediate collections are charged by their size as a tree (`maxAllocatedItemsPerEvaluation`), and a
 * text result may not pass `maxTextLength`.
 */

export type ExpressionValue =
  | string
  | number
  | boolean
  | null
  | undefined
  | ExpressionValue[]
  | { [key: string]: ExpressionValue };

/** Why an expression gave no value: it did not compile, it ran over a budget, or it read itself (a cycle). */
export type ExpressionErrorCode = "INVALID_EXPRESSION" | "BUDGET_EXCEEDED";

export class ExpressionBudgetExceededError extends Error {}

const BLOCKED_MEMBER_NAMES = new Set(["__proto__", "constructor", "prototype"]);

interface LambdaValue {
  readonly isLambda: true;
  readonly params: string[];
  readonly body: ExpressionNode;
  readonly scope: ReadonlyMap<string, ExpressionValue>;
}

type RuntimeValue = ExpressionValue | LambdaValue;

const isLambda = (value: unknown): value is LambdaValue =>
  typeof value === "object" && value !== null && (value as { isLambda?: unknown }).isLambda === true;
const isPlainObject = (value: unknown): value is Record<string, ExpressionValue> =>
  typeof value === "object" && value !== null && !Array.isArray(value) && !isLambda(value);
const isFiniteNumber = (value: unknown): value is number => typeof value === "number" && Number.isFinite(value);
const toFiniteOrEmpty = (value: number): number | undefined => (Number.isFinite(value) ? value : undefined);
const isTruthy = (value: RuntimeValue): boolean => Boolean(value);

/** The budget counters of one state update, shared by every expression it evaluates. */
export class ExpressionBudget {
  operationsLeft: number;
  allocatedItemsLeft: number;

  constructor(readonly budgets: ComposedViewBudgets) {
    this.operationsLeft = budgets.maxOperationsPerUpdate;
    this.allocatedItemsLeft = budgets.maxAllocatedItemsPerEvaluation;
  }

  charge(operations = 1): void {
    this.operationsLeft -= operations;
    if (this.operationsLeft < 0) throw new ExpressionBudgetExceededError("operation budget exceeded");
  }

  allocate(items: number): void {
    this.allocatedItemsLeft -= items;
    if (this.allocatedItemsLeft < 0) throw new ExpressionBudgetExceededError("allocation budget exceeded");
  }

  checkCollection(items: ExpressionValue[]): void {
    if (items.length > this.budgets.maxCollectionItems) throw new ExpressionBudgetExceededError("collection too large");
  }

  /** A text result may not pass `maxTextLength` — checked on the lengths BEFORE the text is built. */
  checkTextLength(textLength: number): void {
    if (textLength > this.budgets.maxTextLength) throw new ExpressionBudgetExceededError("text too long");
  }

  /** The containers this update's expressions built, with their size as a tree (a shared one counted per use). */
  protected readonly createdValueSizes = new WeakMap<object, number>();

  /**
   * Charges a container an expression just built by its size as a TREE — a value built from earlier results counts
   * them again wherever it repeats them, so `{l: o, r: o}` over `o` doubles and a chain of such values cannot grow
   * past the allocation budget. A value the expression did not build (a dataset) counts 1.
   */
  allocateCreatedValue(createdValue: ExpressionValue[] | Record<string, ExpressionValue>): void {
    let treeSize = 1;
    for (const element of Object.values(createdValue)) {
      treeSize += typeof element === "object" && element !== null ? (this.createdValueSizes.get(element) ?? 1) : 1;
    }
    this.allocate(treeSize);
    this.createdValueSizes.set(createdValue, treeSize);
  }
}

/** Where a name comes from: lambda parameters first, then the tree's names (state, datasets, computed). */
export type ExpressionNameResolver = (name: string) => ExpressionValue;

class ExpressionEvaluator {
  constructor(
    protected readonly budget: ExpressionBudget,
    protected readonly resolveName: ExpressionNameResolver,
  ) {}

  evaluate(expressionNode: ExpressionNode, scope: ReadonlyMap<string, ExpressionValue>): RuntimeValue {
    this.budget.charge();
    switch (expressionNode.kind) {
      case "literal":
        return expressionNode.value;
      case "name":
        return scope.has(expressionNode.name) ? scope.get(expressionNode.name) : this.resolveName(expressionNode.name);
      case "array": {
        const arrayValue = expressionNode.elements.map((element) => this.evaluateValue(element, scope));
        this.budget.allocateCreatedValue(arrayValue);
        return arrayValue;
      }
      case "object": {
        const objectValue: Record<string, ExpressionValue> = {};
        for (const entry of expressionNode.entries) {
          if (BLOCKED_MEMBER_NAMES.has(entry.key)) continue;
          objectValue[entry.key] = this.evaluateValue(entry.value, scope);
        }
        this.budget.allocateCreatedValue(objectValue);
        return objectValue;
      }
      case "member":
        return this.readMember(
          this.evaluateValue(expressionNode.object, scope),
          this.evaluateValue(expressionNode.property, scope),
        );
      case "unary": {
        const operand = this.evaluateValue(expressionNode.operand, scope);
        if (expressionNode.operator === "!") return !isTruthy(operand);
        return isFiniteNumber(operand) ? -operand : undefined;
      }
      case "binary":
        return this.evaluateBinary(expressionNode.operator, expressionNode.left, expressionNode.right, scope);
      case "conditional":
        return isTruthy(this.evaluateValue(expressionNode.test, scope))
          ? this.evaluateValue(expressionNode.consequent, scope)
          : this.evaluateValue(expressionNode.alternate, scope);
      case "lambda":
        return { isLambda: true, params: expressionNode.params, body: expressionNode.body, scope };
      case "call":
        return this.callFunction(
          expressionNode.functionName,
          expressionNode.args.map((arg) => this.evaluate(arg, scope)),
        );
      default:
        return undefined;
    }
  }

  /** A value that is never a lambda (a lambda outside a call argument is empty). */
  evaluateValue(expressionNode: ExpressionNode, scope: ReadonlyMap<string, ExpressionValue>): ExpressionValue {
    const value = this.evaluate(expressionNode, scope);
    return isLambda(value) ? undefined : value;
  }

  protected readMember(objectValue: ExpressionValue, propertyValue: ExpressionValue): ExpressionValue {
    if (typeof propertyValue !== "string" && typeof propertyValue !== "number") return undefined;
    const propertyName = String(propertyValue);
    if (BLOCKED_MEMBER_NAMES.has(propertyName)) return undefined;
    if (Array.isArray(objectValue)) {
      if (propertyName === "length") return objectValue.length;
      return Object.prototype.hasOwnProperty.call(objectValue, propertyName)
        ? objectValue[Number(propertyName)]
        : undefined;
    }
    if (isPlainObject(objectValue) && Object.prototype.hasOwnProperty.call(objectValue, propertyName)) {
      return objectValue[propertyName];
    }
    return undefined;
  }

  protected evaluateBinary(
    operator: string,
    leftNode: ExpressionNode,
    rightNode: ExpressionNode,
    scope: ReadonlyMap<string, ExpressionValue>,
  ): ExpressionValue {
    if (operator === "&&" || operator === "||") {
      const left = this.evaluateValue(leftNode, scope);
      if (operator === "&&") return isTruthy(left) ? this.evaluateValue(rightNode, scope) : left;
      return isTruthy(left) ? left : this.evaluateValue(rightNode, scope);
    }
    const left = this.evaluateValue(leftNode, scope);
    const right = this.evaluateValue(rightNode, scope);
    switch (operator) {
      case "==":
        return left === right;
      case "!=":
        return left !== right;
      case "<":
      case "<=":
      case ">":
      case ">=": {
        const comparable =
          (isFiniteNumber(left) && isFiniteNumber(right)) || (typeof left === "string" && typeof right === "string");
        if (!comparable) return undefined;
        if (operator === "<") return (left as number) < (right as number);
        if (operator === "<=") return (left as number) <= (right as number);
        if (operator === ">") return (left as number) > (right as number);
        return (left as number) >= (right as number);
      }
      case "+":
        if (isFiniteNumber(left) && isFiniteNumber(right)) return toFiniteOrEmpty(left + right);
        if (
          (typeof left === "string" || isFiniteNumber(left)) &&
          (typeof right === "string" || isFiniteNumber(right))
        ) {
          const leftText = String(left);
          const rightText = String(right);
          this.budget.checkTextLength(leftText.length + rightText.length);
          return leftText + rightText;
        }
        return undefined;
      case "-":
        return isFiniteNumber(left) && isFiniteNumber(right) ? toFiniteOrEmpty(left - right) : undefined;
      case "*":
        return isFiniteNumber(left) && isFiniteNumber(right) ? toFiniteOrEmpty(left * right) : undefined;
      case "/":
        return isFiniteNumber(left) && isFiniteNumber(right) && right !== 0 ? toFiniteOrEmpty(left / right) : undefined;
      case "%":
        return isFiniteNumber(left) && isFiniteNumber(right) && right !== 0 ? toFiniteOrEmpty(left % right) : undefined;
      default:
        return undefined;
    }
  }

  protected invokeLambda(lambda: RuntimeValue, args: ExpressionValue[]): ExpressionValue {
    if (!isLambda(lambda)) return undefined;
    const lambdaScope = new Map(lambda.scope);
    lambda.params.forEach((param, index) => lambdaScope.set(param, args[index]));
    return this.evaluateValue(lambda.body, lambdaScope);
  }

  /** The list argument of a collection function, capped and charged; undefined when it is no list. */
  protected readList(value: RuntimeValue): ExpressionValue[] | undefined {
    if (!Array.isArray(value)) return undefined;
    this.budget.checkCollection(value);
    this.budget.charge(value.length);
    return value;
  }

  /** The numbers of a list, through an optional lambda; non-numbers are skipped. */
  protected readNumbers(listValue: RuntimeValue, lambda?: RuntimeValue): number[] | undefined {
    const list = this.readList(listValue);
    if (list === undefined) return undefined;
    const values =
      lambda === undefined ? list : list.map((element, index) => this.invokeLambda(lambda, [element, index]));
    return values.filter(isFiniteNumber);
  }

  protected callFunction(functionName: string, args: RuntimeValue[]): ExpressionValue {
    const [first, second, third] = args;
    switch (functionName) {
      case "round": {
        if (!isFiniteNumber(first)) return undefined;
        const digits = isFiniteNumber(second) ? Math.max(0, Math.min(10, Math.trunc(second))) : 0;
        const factor = 10 ** digits;
        return toFiniteOrEmpty(Math.round(first * factor) / factor);
      }
      case "floor":
        return isFiniteNumber(first) ? Math.floor(first) : undefined;
      case "ceil":
        return isFiniteNumber(first) ? Math.ceil(first) : undefined;
      case "abs":
        return isFiniteNumber(first) ? Math.abs(first) : undefined;
      case "min":
      case "max": {
        const numbers = Array.isArray(first) ? this.readNumbers(first, second) : args.filter(isFiniteNumber);
        if (numbers === undefined || numbers.length === 0) return undefined;
        return functionName === "min" ? Math.min(...numbers) : Math.max(...numbers);
      }
      case "sum": {
        const numbers = this.readNumbers(first, second);
        return numbers === undefined ? undefined : toFiniteOrEmpty(numbers.reduce((total, value) => total + value, 0));
      }
      case "avg": {
        const numbers = this.readNumbers(first, second);
        if (numbers === undefined || numbers.length === 0) return undefined;
        return toFiniteOrEmpty(numbers.reduce((total, value) => total + value, 0) / numbers.length);
      }
      case "count": {
        const list = this.readList(first);
        if (list === undefined) return undefined;
        if (second === undefined) return list.length;
        return list.filter((element, index) => isTruthy(this.invokeLambda(second, [element, index]))).length;
      }
      case "len":
        if (typeof first === "string") return [...first].length;
        return Array.isArray(first) ? first.length : undefined;
      case "filter": {
        const list = this.readList(first);
        if (list === undefined || !isLambda(second)) return undefined;
        const kept = list.filter((element, index) => isTruthy(this.invokeLambda(second, [element, index])));
        this.budget.allocateCreatedValue(kept);
        return kept;
      }
      case "map": {
        const list = this.readList(first);
        if (list === undefined || !isLambda(second)) return undefined;
        const mapped = list.map((element, index) => this.invokeLambda(second, [element, index]));
        this.budget.allocateCreatedValue(mapped);
        return mapped;
      }
      case "sortBy": {
        const list = this.readList(first);
        if (list === undefined || !isLambda(second)) return undefined;
        this.budget.charge(Math.ceil(list.length * Math.log2(list.length + 1)));
        const direction = third === "desc" ? -1 : 1;
        const keyed = list.map((element, index) => ({
          element,
          key: this.invokeLambda(second, [element, index]),
          index,
        }));
        keyed.sort((a, b) => {
          const comparison =
            isFiniteNumber(a.key) && isFiniteNumber(b.key)
              ? a.key - b.key
              : typeof a.key === "string" && typeof b.key === "string"
                ? a.key.localeCompare(b.key)
                : a.key === undefined
                  ? 1
                  : b.key === undefined
                    ? -1
                    : 0;
          return comparison !== 0 ? comparison * direction : a.index - b.index;
        });
        const sorted = keyed.map((entry) => entry.element);
        this.budget.allocateCreatedValue(sorted);
        return sorted;
      }
      case "first": {
        const list = this.readList(first);
        return list === undefined ? undefined : list[0];
      }
      case "last": {
        const list = this.readList(first);
        return list === undefined ? undefined : list[list.length - 1];
      }
      case "concat": {
        const lists = args.map((arg) => this.readList(arg));
        if (lists.some((list) => list === undefined)) return undefined;
        const joined = (lists as ExpressionValue[][]).flat(1);
        this.budget.checkCollection(joined);
        this.budget.allocateCreatedValue(joined);
        return joined;
      }
      case "join": {
        const list = this.readList(first);
        if (list === undefined) return undefined;
        const separator = typeof second === "string" ? second : ", ";
        const joinedParts = list
          .filter((element) => typeof element === "string" || isFiniteNumber(element))
          .map((element) => String(element));
        this.budget.checkTextLength(
          joinedParts.reduce((textLength, part) => textLength + part.length, 0) +
            separator.length * Math.max(0, joinedParts.length - 1),
        );
        return joinedParts.join(separator);
      }
      case "string": {
        if (typeof first !== "string" && !isFiniteNumber(first) && typeof first !== "boolean") return undefined;
        const text = String(first);
        this.budget.checkTextLength(text.length);
        return text;
      }
      case "number": {
        if (isFiniteNumber(first)) return first;
        if (typeof first !== "string" || first.trim() === "") return undefined;
        return toFiniteOrEmpty(Number(first));
      }
      case "fixed": {
        if (!isFiniteNumber(first)) return undefined;
        const digits = isFiniteNumber(second) ? Math.max(0, Math.min(10, Math.trunc(second))) : 0;
        return first.toFixed(digits);
      }
      case "percent": {
        if (!isFiniteNumber(first) || !isFiniteNumber(second) || second === 0) return undefined;
        const digits = isFiniteNumber(third) ? Math.max(0, Math.min(10, Math.trunc(third))) : 0;
        const factor = 10 ** digits;
        return toFiniteOrEmpty(Math.round((first / second) * 100 * factor) / factor);
      }
      default:
        return undefined;
    }
  }
}

export type ExpressionEvaluationResult =
  | { ok: true; value: ExpressionValue }
  | { ok: false; errorCode: ExpressionErrorCode; message: string };

/** Compiles (cached) and evaluates one expression; never throws. */
export const evaluateExpression = (
  source: string,
  resolveName: ExpressionNameResolver,
  budget: ExpressionBudget,
): ExpressionEvaluationResult => {
  const compiled = compileExpression(source, budget.budgets.maxExpressionAstNodes);
  if (!compiled.ok) return { ok: false, errorCode: "INVALID_EXPRESSION", message: compiled.error };
  try {
    return { ok: true, value: new ExpressionEvaluator(budget, resolveName).evaluateValue(compiled.ast, new Map()) };
  } catch (error) {
    if (error instanceof ExpressionBudgetExceededError)
      return { ok: false, errorCode: "BUDGET_EXCEEDED", message: error.message };
    return {
      ok: false,
      errorCode: "INVALID_EXPRESSION",
      message: error instanceof Error ? error.message : "evaluation failed",
    };
  }
};

export interface ComputedValuesResult {
  values: Record<string, ExpressionValue>;
  errors: Record<string, { errorCode: ExpressionErrorCode; message: string }>;
}

/**
 * Every computed value of a tree for one state: each expression evaluated once, in dependency order (a computed value
 * may read state, datasets and other computed values), under ONE budget. A cycle or a dependency chain deeper than
 * `maxComputedDependencyDepth` leaves the values involved empty with `INVALID_EXPRESSION`.
 */
export const computeComposedViewValues = (
  computed: Record<string, string>,
  state: Record<string, ExpressionValue>,
  datasets: Record<string, ExpressionValue>,
  budgets: ComposedViewBudgets,
): ComputedValuesResult => {
  const budget = new ExpressionBudget(budgets);
  const result: ComputedValuesResult = { values: {}, errors: {} };
  const evaluating: string[] = [];
  const done = new Set<string>();

  const resolveTreeName = (name: string): ExpressionValue => {
    if (Object.prototype.hasOwnProperty.call(state, name)) return state[name];
    if (Object.prototype.hasOwnProperty.call(datasets, name)) return datasets[name];
    if (Object.prototype.hasOwnProperty.call(computed, name)) return computeOne(name);
    return undefined;
  };

  const computeOne = (name: string): ExpressionValue => {
    if (done.has(name)) return result.values[name];
    if (evaluating.includes(name)) {
      for (const cycleName of evaluating.slice(evaluating.indexOf(name))) {
        result.errors[cycleName] = {
          errorCode: "INVALID_EXPRESSION",
          message: `a cycle: ${[...evaluating, name].join(" → ")}`,
        };
      }
      return undefined;
    }
    if (evaluating.length >= budgets.maxComputedDependencyDepth) {
      result.errors[name] = {
        errorCode: "INVALID_EXPRESSION",
        message: `deeper than ${budgets.maxComputedDependencyDepth} computed values`,
      };
      return undefined;
    }
    evaluating.push(name);
    const evaluation = evaluateExpression(computed[name], resolveTreeName, budget);
    evaluating.pop();
    done.add(name);
    if (result.errors[name]) {
      result.values[name] = undefined;
      return undefined;
    }
    if (!evaluation.ok) {
      result.errors[name] = { errorCode: evaluation.errorCode, message: evaluation.message };
      result.values[name] = undefined;
      return undefined;
    }
    result.values[name] = evaluation.value;
    return evaluation.value;
  };

  for (const name of Object.keys(computed)) computeOne(name);
  return result;
};

/** The tree names a computed expression reads, for its dependency list (empty when it does not compile). */
export const findExpressionDependencies = (source: string, maxAstNodes: number): string[] => {
  const compiled = compileExpression(source, maxAstNodes);
  return compiled.ok ? [...collectExpressionNames(compiled.ast)] : [];
};
