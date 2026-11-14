/**
 * The parser of the `ComposedView` expression language — our own tokenizer and recursive-descent
 * parser, never `eval` or `new Function`. The language is closed:
 *
 *   literals      12, 3.5, "text", 'text', true, false, null, [a, b], {name: expr, "key": expr}
 *   names         a state, dataset or computed name; a lambda parameter
 *   member        a.b, a["b"], a[0]            (own properties of JSON values only — checked when evaluating)
 *   operators     + - * / %   == != < <= > >=   && || !   unary -   cond ? a : b
 *   calls         one of the closed functions (`EXPRESSION_FUNCTION_NAMES`), e.g. sum(rows, r => r.visits)
 *   lambdas       x => expr, (x) => expr, (x, i) => expr — only as an argument of a call
 *
 * There are no statements, assignments, loops or method calls; a call names a function of the closed list, never a
 * value. The AST node count is capped (`maxExpressionAstNodes`); an expression over it does not compile.
 */

export type ExpressionNode =
  | { kind: "literal"; value: string | number | boolean | null }
  | { kind: "name"; name: string }
  | { kind: "array"; elements: ExpressionNode[] }
  | { kind: "object"; entries: Array<{ key: string; value: ExpressionNode }> }
  | { kind: "member"; object: ExpressionNode; property: ExpressionNode }
  | { kind: "unary"; operator: "!" | "-"; operand: ExpressionNode }
  | { kind: "binary"; operator: string; left: ExpressionNode; right: ExpressionNode }
  | { kind: "conditional"; test: ExpressionNode; consequent: ExpressionNode; alternate: ExpressionNode }
  | { kind: "call"; functionName: string; args: ExpressionNode[] }
  | { kind: "lambda"; params: string[]; body: ExpressionNode };

/** The closed function list (the evaluator implements exactly these). */
export const EXPRESSION_FUNCTION_NAMES = [
  "round",
  "floor",
  "ceil",
  "abs",
  "min",
  "max",
  "sum",
  "avg",
  "count",
  "len",
  "filter",
  "map",
  "sortBy",
  "first",
  "last",
  "concat",
  "join",
  "string",
  "number",
  "fixed",
  "percent",
] as const;

export type ExpressionFunctionName = (typeof EXPRESSION_FUNCTION_NAMES)[number];

export type ExpressionCompileResult =
  | { ok: true; ast: ExpressionNode; nodeCount: number }
  | { ok: false; error: string };

type Token =
  | { type: "number"; value: number }
  | { type: "string"; value: string }
  | { type: "name"; value: string }
  | { type: "punct"; value: string }
  | { type: "end" };

const PUNCTUATORS = [
  "=>",
  "==",
  "!=",
  "<=",
  ">=",
  "&&",
  "||",
  "+",
  "-",
  "*",
  "/",
  "%",
  "<",
  ">",
  "!",
  "?",
  ":",
  ".",
  ",",
  "(",
  ")",
  "[",
  "]",
  "{",
  "}",
];

class ExpressionSyntaxError extends Error {}

const tokenize = (source: string): Token[] => {
  const tokens: Token[] = [];
  let position = 0;
  while (position < source.length) {
    const character = source[position];
    if (/\s/.test(character)) {
      position++;
      continue;
    }
    if (/[0-9]/.test(character) || (character === "." && /[0-9]/.test(source[position + 1] ?? ""))) {
      const numberMatch = /^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?/.exec(source.slice(position));
      if (!numberMatch) throw new ExpressionSyntaxError(`bad number at ${position}`);
      tokens.push({ type: "number", value: Number(numberMatch[0]) });
      position += numberMatch[0].length;
      continue;
    }
    if (character === '"' || character === "'") {
      let text = "";
      let cursor = position + 1;
      while (cursor < source.length && source[cursor] !== character) {
        if (source[cursor] === "\\" && cursor + 1 < source.length) {
          const escaped = source[cursor + 1];
          text += escaped === "n" ? "\n" : escaped === "t" ? "\t" : escaped;
          cursor += 2;
          continue;
        }
        text += source[cursor];
        cursor++;
      }
      if (cursor >= source.length) throw new ExpressionSyntaxError("unclosed string");
      tokens.push({ type: "string", value: text });
      position = cursor + 1;
      continue;
    }
    if (/[A-Za-z_$]/.test(character)) {
      const nameMatch = /^[A-Za-z_$][A-Za-z0-9_$]*/.exec(source.slice(position));
      tokens.push({ type: "name", value: nameMatch![0] });
      position += nameMatch![0].length;
      continue;
    }
    const punctuator = PUNCTUATORS.find((candidate) => source.startsWith(candidate, position));
    if (!punctuator) throw new ExpressionSyntaxError(`unexpected "${character}" at ${position}`);
    tokens.push({ type: "punct", value: punctuator });
    position += punctuator.length;
  }
  tokens.push({ type: "end" });
  return tokens;
};

const BINARY_PRECEDENCE: Array<string[]> = [
  ["||"],
  ["&&"],
  ["==", "!="],
  ["<", "<=", ">", ">="],
  ["+", "-"],
  ["*", "/", "%"],
];

class ExpressionParser {
  protected position = 0;
  nodeCount = 0;

  constructor(
    protected readonly tokens: Token[],
    protected readonly maxNodes: number,
  ) {}

  protected peek(): Token {
    return this.tokens[this.position];
  }

  protected next(): Token {
    return this.tokens[this.position++];
  }

  protected isPunct(value: string, offset = 0): boolean {
    const token = this.tokens[this.position + offset];
    return token !== undefined && token.type === "punct" && token.value === value;
  }

  protected expectPunct(value: string): void {
    if (!this.isPunct(value)) throw new ExpressionSyntaxError(`"${value}" expected`);
    this.position++;
  }

  protected node<T extends ExpressionNode>(expressionNode: T): T {
    if (++this.nodeCount > this.maxNodes)
      throw new ExpressionSyntaxError(`more than ${this.maxNodes} expression nodes`);
    return expressionNode;
  }

  parseExpression(): ExpressionNode {
    const test = this.parseBinary(0);
    if (!this.isPunct("?")) return test;
    this.position++;
    const consequent = this.parseExpression();
    this.expectPunct(":");
    const alternate = this.parseExpression();
    return this.node({ kind: "conditional", test, consequent, alternate });
  }

  protected parseBinary(level: number): ExpressionNode {
    if (level >= BINARY_PRECEDENCE.length) return this.parseUnary();
    let left = this.parseBinary(level + 1);
    for (;;) {
      const token = this.peek();
      if (token.type !== "punct" || !BINARY_PRECEDENCE[level].includes(token.value)) return left;
      this.position++;
      const right = this.parseBinary(level + 1);
      left = this.node({ kind: "binary", operator: token.value, left, right });
    }
  }

  protected parseUnary(): ExpressionNode {
    if (this.isPunct("!") || this.isPunct("-")) {
      const operator = (this.next() as { value: "!" | "-" }).value;
      return this.node({ kind: "unary", operator, operand: this.parseUnary() });
    }
    return this.parsePostfix(this.parsePrimary());
  }

  protected parsePostfix(target: ExpressionNode): ExpressionNode {
    let expressionNode = target;
    for (;;) {
      if (this.isPunct(".")) {
        this.position++;
        const propertyToken = this.next();
        if (propertyToken.type !== "name") throw new ExpressionSyntaxError('a property name expected after "."');
        expressionNode = this.node({
          kind: "member",
          object: expressionNode,
          property: this.node({ kind: "literal", value: propertyToken.value }),
        });
        continue;
      }
      if (this.isPunct("[")) {
        this.position++;
        const property = this.parseExpression();
        this.expectPunct("]");
        expressionNode = this.node({ kind: "member", object: expressionNode, property });
        continue;
      }
      if (this.isPunct("(")) throw new ExpressionSyntaxError("only the closed functions can be called");
      return expressionNode;
    }
  }

  protected parseArguments(): ExpressionNode[] {
    const args: ExpressionNode[] = [];
    this.expectPunct("(");
    while (!this.isPunct(")")) {
      args.push(this.parseLambdaOrExpression());
      if (!this.isPunct(",")) break;
      this.position++;
    }
    this.expectPunct(")");
    return args;
  }

  /** A lambda (`x => …`, `(x, i) => …`) where a call argument may hold one, else an expression. */
  protected parseLambdaOrExpression(): ExpressionNode {
    const token = this.peek();
    if (token.type === "name" && this.isPunct("=>", 1)) {
      this.position += 2;
      return this.node({ kind: "lambda", params: [token.value], body: this.parseExpression() });
    }
    if (this.isPunct("(")) {
      let cursor = this.position + 1;
      const params: string[] = [];
      while (this.tokens[cursor]?.type === "name") {
        params.push((this.tokens[cursor] as { value: string }).value);
        cursor++;
        const separator = this.tokens[cursor];
        if (separator?.type === "punct" && separator.value === ",") {
          cursor++;
          continue;
        }
        break;
      }
      const closing = this.tokens[cursor];
      const arrow = this.tokens[cursor + 1];
      if (closing?.type === "punct" && closing.value === ")" && arrow?.type === "punct" && arrow.value === "=>") {
        this.position = cursor + 2;
        return this.node({ kind: "lambda", params, body: this.parseExpression() });
      }
    }
    return this.parseExpression();
  }

  protected parsePrimary(): ExpressionNode {
    const token = this.next();
    if (token.type === "number" || token.type === "string") return this.node({ kind: "literal", value: token.value });
    if (token.type === "name") {
      if (token.value === "true" || token.value === "false")
        return this.node({ kind: "literal", value: token.value === "true" });
      if (token.value === "null") return this.node({ kind: "literal", value: null });
      if (this.isPunct("(")) {
        if (!(EXPRESSION_FUNCTION_NAMES as readonly string[]).includes(token.value)) {
          throw new ExpressionSyntaxError(`"${token.value}" is not a function of the closed list`);
        }
        return this.node({ kind: "call", functionName: token.value, args: this.parseArguments() });
      }
      return this.node({ kind: "name", name: token.value });
    }
    if (token.type === "punct" && token.value === "(") {
      const inner = this.parseExpression();
      this.expectPunct(")");
      return inner;
    }
    if (token.type === "punct" && token.value === "[") {
      const elements: ExpressionNode[] = [];
      while (!this.isPunct("]")) {
        elements.push(this.parseExpression());
        if (!this.isPunct(",")) break;
        this.position++;
      }
      this.expectPunct("]");
      return this.node({ kind: "array", elements });
    }
    if (token.type === "punct" && token.value === "{") {
      const entries: Array<{ key: string; value: ExpressionNode }> = [];
      while (!this.isPunct("}")) {
        const keyToken = this.next();
        if (keyToken.type !== "name" && keyToken.type !== "string")
          throw new ExpressionSyntaxError("an object key expected");
        this.expectPunct(":");
        entries.push({ key: String(keyToken.value), value: this.parseExpression() });
        if (!this.isPunct(",")) break;
        this.position++;
      }
      this.expectPunct("}");
      return this.node({ kind: "object", entries });
    }
    throw new ExpressionSyntaxError(token.type === "end" ? "unexpected end of expression" : "unexpected token");
  }

  parseAll(): ExpressionNode {
    const ast = this.parseExpression();
    if (this.peek().type !== "end") throw new ExpressionSyntaxError("unexpected text after the expression");
    return ast;
  }
}

const compiledExpressions = new Map<string, ExpressionCompileResult>();

/** Compiles an expression once (cached by source and node budget); never throws. */
export const compileExpression = (source: string, maxAstNodes: number): ExpressionCompileResult => {
  const cacheKey = `${maxAstNodes}\u0000${source}`;
  const cached = compiledExpressions.get(cacheKey);
  if (cached) return cached;
  let compileResult: ExpressionCompileResult;
  try {
    const parser = new ExpressionParser(tokenize(source), maxAstNodes);
    const ast = parser.parseAll();
    compileResult = { ok: true, ast, nodeCount: parser.nodeCount };
  } catch (error) {
    compileResult = { ok: false, error: error instanceof Error ? error.message : "invalid expression" };
  }
  if (compiledExpressions.size > 2000) compiledExpressions.clear();
  compiledExpressions.set(cacheKey, compileResult);
  return compileResult;
};

/** The names an expression reads (lambda parameters excluded) — the computed dependency graph. */
export const collectExpressionNames = (
  ast: ExpressionNode,
  boundNames: ReadonlySet<string> = new Set(),
): Set<string> => {
  const names = new Set<string>();
  const visitNode = (expressionNode: ExpressionNode, scope: ReadonlySet<string>): void => {
    switch (expressionNode.kind) {
      case "name":
        if (!scope.has(expressionNode.name)) names.add(expressionNode.name);
        return;
      case "array":
        expressionNode.elements.forEach((element) => visitNode(element, scope));
        return;
      case "object":
        expressionNode.entries.forEach((entry) => visitNode(entry.value, scope));
        return;
      case "member":
        visitNode(expressionNode.object, scope);
        visitNode(expressionNode.property, scope);
        return;
      case "unary":
        visitNode(expressionNode.operand, scope);
        return;
      case "binary":
        visitNode(expressionNode.left, scope);
        visitNode(expressionNode.right, scope);
        return;
      case "conditional":
        visitNode(expressionNode.test, scope);
        visitNode(expressionNode.consequent, scope);
        visitNode(expressionNode.alternate, scope);
        return;
      case "call":
        expressionNode.args.forEach((arg) => visitNode(arg, scope));
        return;
      case "lambda":
        visitNode(expressionNode.body, new Set([...scope, ...expressionNode.params]));
        return;
      default:
        return;
    }
  };
  visitNode(ast, boundNames);
  return names;
};
