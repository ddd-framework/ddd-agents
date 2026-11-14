import {
  getComposedNodeSchema,
  ComposedNodePropSchema,
  ComposedNodeSchema,
  ComposedNodeSlotSchema,
  ComposedSizeClass,
  resolveComposedSlotForNodeProps,
  sizeClassFitsSlot,
} from "./composed-node-schema";
import { resolveCanonicalWidgetName } from "../widget-name-resolver";
import { exceedsJsonContainerDepth, MAX_JSON_CONTAINER_DEPTH, normalizeToPhpArrays } from "./bounded-json";

/**
 * The `ComposedView` tree as typed objects: the frontend twin of the backend reader
 * `ComposedViewTreeReading.php`. The fence plugin hands the widget its `payload` string; this module turns it into
 * {@link ComposedViewTree} — state, datasets, computed expressions, actions and the node tree — and applies THE SAME
 * rules as the backend, read from the generated schema: unknown types, slot mismatches, undeclared props, bad bindings,
 * budgets and three backticks are dropped where they stand and reported as findings; the rest is kept. A `set` on an
 * undeclared name declares it with null; a choice state outside its choices, a set state nothing reads and an `$item`
 * field missing from resolved `$from` rows are kept with a note (`noteFindingCodes` of the schema). The shared
 * fixture `composed-view-parity.json` holds both readers to the same kept tree and the same finding codes.
 *
 * Never throws, never evaluates anything (expressions are compiled by `expression/`).
 */

export type ComposedViewFindingCode =
  | "INVALID_NODE"
  | "INVALID_PROP"
  | "UNKNOWN_BINDING"
  | "INVALID_EXPRESSION"
  | "INVALID_STATE"
  | "INVALID_ACTION"
  | "BUDGET_EXCEEDED"
  | "INLINE_VALUE_WITHOUT_SOURCE"
  | "INVALID_INITIAL_CHOICE"
  | "UNREAD_STATE"
  | "UNKNOWN_ITEM_KEY"
  | "INVALID_SLOT_NODE_COUNT"
  | "WIDGET_LIFTED";

export interface ComposedViewFinding {
  code: ComposedViewFindingCode;
  path: string;
  message: string;
}

export type ComposedViewBindingKind = "state" | "computed" | "data" | "item" | "from" | "source";

/** A bound value (`{"$state":"x"}` …), see `ComposedViewBinding.php`. */
export interface ComposedViewBinding {
  kind: ComposedViewBindingKind | string;
  target: string;
  path: string | null;
  sourceParams: Record<string, unknown>;
  isWellFormed: boolean;
  /** The wire value itself (a `$from` carries its resolution state and snapshot inside, written when the row is stored). */
  wireValue: Record<string, unknown>;
}

export type JsonValue = string | number | boolean | null | JsonValue[] | { [key: string]: JsonValue };

export interface ComposedViewNode {
  type: string;
  id: string | null;
  /** The kept props: literals and bindings as JSON values. */
  props: Record<string, JsonValue>;
  /** The kept nodes per slot name (`children` = the default slot), in wire order. */
  nodesBySlotName: Record<string, ComposedViewNode[]>;
}

export interface ComposedViewAction {
  type: string;
  [field: string]: JsonValue;
}

/**
 * A `Widget` node the reader LIFTED out of the tree (`ComposedViewTreeReading::$liftedWidgetNodesByPath`):
 * its wire path, the widget's canonical name and its fields — shown as its own widget after the view.
 */
export interface ComposedViewLiftedWidget {
  path: string;
  widget: string;
  fields: Record<string, JsonValue>;
}

export interface ComposedViewTree {
  title: string | null;
  state: Record<string, string | number | boolean | null>;
  data: Record<string, JsonValue>;
  computed: Record<string, string>;
  actions: Record<string, ComposedViewAction>;
  view: ComposedViewNode | null;
  findings: ComposedViewFinding[];
  /** The widgets lifted out of the tree, reading order — never part of `view`. */
  liftedWidgets: ComposedViewLiftedWidget[];
}

const RESERVED_WIRE_KEYS = ["type", "id", "children", "slots"];
const DEFAULT_SLOT_NAME = "children";
const NAME_PATTERN = /^[A-Za-z_][A-Za-z0-9_]{0,63}$/;
/** Names the pattern admits that would reach an object's prototype — refused for state, data, computed and actions. */
const RESERVED_NAMES = ["__proto__", "constructor", "prototype"];
/** `ComposedViewTreeReading::isAllowedName()`. */
const isAllowedName = (name: string): boolean => NAME_PATTERN.test(name) && !RESERVED_NAMES.includes(name);
const BINDING_KINDS = ["state", "computed", "data", "item", "from", "source"];
/** `ComposedViewTreeReading::FIGURE_TEXT_PATTERN` — a text that reads as a figure ("4,32 %", "-3", "+2"). */
const FIGURE_TEXT_PATTERN = /^[-+\u2212]?[0-9]/u;
const ACTION_TYPES = ["copy", "resetState", "openEntity", "openUrl"];
/** `ComposedViewTreeReading::readChoiceValues()` — the keys a choice object may be picked by. */
const CHOICE_VALUE_KEYS = ["id", "value", "key", "label", "title", "name", "text"];
/** `ComposedViewBindingResolution::STATE_RESOLVED` — a `$from` snapshot that carries its rows. */
const RESOLVED_FROM_STATE = "resolved";
/** `ComposedViewBindablePathListing::MAX_LISTED_ROW_KEYS` / `MAX_ROW_LEAF_DEPTH` — the field list of an `$item` finding. */
const MAX_LISTED_ROW_KEYS = 12;
const MAX_ROW_LEAF_DEPTH = 2;

type PlainObject = Record<string, unknown>;

/** Own key only — never a prototype key (`toString`, `__proto__`). */
const hasOwn = (object: object, key: string): boolean => Object.prototype.hasOwnProperty.call(object, key);

/** A JSON object (not an array) — PHP's associative array; an EMPTY array counts as both, as `json_decode(…, true)` makes `{}` and `[]` one. */
const isPlainObject = (value: unknown): value is PlainObject =>
  typeof value === "object" && value !== null && !Array.isArray(value);
const isEmptyContainer = (value: unknown): boolean =>
  (Array.isArray(value) && value.length === 0) || (isPlainObject(value) && Object.keys(value).length === 0);
/** PHP `is_array`. */
const isPhpArray = (value: unknown): value is unknown[] | PlainObject => Array.isArray(value) || isPlainObject(value);
/** PHP `array_is_list` on a decoded value. */
const isPhpList = (value: unknown): boolean => Array.isArray(value) || isEmptyContainer(value);
/** PHP `mb_strlen` — code points, not UTF-16 units. */
const codePointLength = (text: string): number => [...text].length;
const entriesOf = (value: unknown[] | PlainObject): Array<[string, unknown]> =>
  Array.isArray(value) ? value.map((entry, index) => [String(index), entry]) : Object.entries(value);

/** The binding a wire value stands for, or null for a literal — `ComposedViewBinding::fromWireValue()`. */
export const readComposedViewBinding = (wireValue: unknown): ComposedViewBinding | null => {
  if (!isPlainObject(wireValue)) return null;
  const keys = Object.keys(wireValue);
  if (keys.length !== 1 || !keys[0].startsWith("$")) return null;
  const kind = keys[0].slice(1);
  const bindingValue = wireValue[keys[0]];
  const binding: ComposedViewBinding = {
    kind,
    target: "",
    path: null,
    sourceParams: {},
    isWellFormed: true,
    wireValue,
  };
  if (!BINDING_KINDS.includes(kind)) {
    binding.isWellFormed = false;
    return binding;
  }
  if (kind === "state" || kind === "computed" || kind === "data" || kind === "item") {
    binding.isWellFormed = typeof bindingValue === "string" && bindingValue !== "";
    binding.target = typeof bindingValue === "string" ? bindingValue : "";
    return binding;
  }
  const bindingObject = isPhpArray(bindingValue) ? (bindingValue as PlainObject) : null;
  if (kind === "from") {
    binding.target = bindingObject && typeof bindingObject.toolCall === "string" ? bindingObject.toolCall : "";
    binding.path = bindingObject && typeof bindingObject.path === "string" ? bindingObject.path : null;
    binding.isWellFormed = binding.target !== "" && binding.path !== null && binding.path !== "";
    return binding;
  }
  binding.target = bindingObject && typeof bindingObject.name === "string" ? bindingObject.name : "";
  const sourceParams = bindingObject ? (bindingObject.params ?? []) : null;
  binding.sourceParams = isPhpArray(sourceParams) ? Object.fromEntries(entriesOf(sourceParams)) : {};
  binding.isWellFormed = binding.target !== "" && isPhpArray(sourceParams);
  return binding;
};

/**
 * The field an `$item` binding names in one row — `ComposedViewBinding::readRowField()`: the key as written, else for a
 * dotted name (`author.name`) the path through the row's nested objects. `found` tells a null field from a missing one.
 */
export const readComposedViewRowField = (row: unknown, fieldName: string): { found: boolean; value: unknown } => {
  if (!isPlainObject(row)) return { found: false, value: undefined };
  if (hasOwn(row, fieldName)) return { found: true, value: row[fieldName] };
  if (!fieldName.includes(".")) return { found: false, value: undefined };
  let fieldValue: unknown = row;
  for (const fieldNamePart of fieldName.split(".")) {
    if (!isPlainObject(fieldValue) || !hasOwn(fieldValue, fieldNamePart)) return { found: false, value: undefined };
    fieldValue = fieldValue[fieldNamePart];
  }
  return { found: true, value: fieldValue };
};

/** `ComposedViewBindablePathListing::buildListedRowKeys()` — scalar and list keys first, then dotted object leaves. */
const collectRowKeys = (row: PlainObject, keyPrefix: string, leafDepth: number): string[] => {
  const ownKeys: string[] = [];
  const leafKeys: string[] = [];
  for (const [rowKey, rowValue] of Object.entries(row)) {
    if (rowKey === "objectType") continue;
    const dottedKey = `${keyPrefix}${rowKey}`;
    if (isPlainObject(rowValue) && !isEmptyContainer(rowValue) && leafDepth < MAX_ROW_LEAF_DEPTH) {
      leafKeys.push(...collectRowKeys(rowValue, `${dottedKey}.`, leafDepth + 1));
      continue;
    }
    ownKeys.push(dottedKey);
  }
  return [...ownKeys, ...leafKeys];
};

const buildListedRowKeys = (row: PlainObject): string[] => {
  const rowKeys = collectRowKeys(row, "", 0);
  const listedRowKeys = rowKeys.slice(0, MAX_LISTED_ROW_KEYS);
  return rowKeys.length > listedRowKeys.length ? [...listedRowKeys, "…"] : listedRowKeys;
};

/** `ComposedViewTreeReading::readChoiceValues()` — the values a choice may be picked by, as text. */
const readChoiceValues = (choices: unknown[]): string[] => {
  const choiceValues = new Set<string>();
  for (const choice of choices) {
    const choiceFields = isPhpArray(choice)
      ? CHOICE_VALUE_KEYS.filter((choiceKey) => hasOwn(choice, choiceKey)).map(
          (choiceKey) => (choice as PlainObject)[choiceKey],
        )
      : [choice];
    for (const choiceField of choiceFields) {
      if (typeof choiceField === "string" || typeof choiceField === "number") choiceValues.add(String(choiceField));
    }
  }
  return [...choiceValues];
};

/** `ComposedViewTreeReading::findIdentifiersOfExpression()` — identifiers outside string literals, not after a `.`. */
export const findComposedViewExpressionIdentifiers = (expressionSource: string): string[] => {
  const sourceWithoutStrings = expressionSource.replace(/'(?:[^'\\]|\\[^])*'|"(?:[^"\\]|\\[^])*"/g, " ");
  const identifierMatches = sourceWithoutStrings.matchAll(/(^|[^A-Za-z0-9_$.])([A-Za-z_][A-Za-z0-9_]*)/g);
  return [...new Set([...identifierMatches].map((identifierMatch) => identifierMatch[2]))];
};

/** `ComposedViewTreeReading::collectStateBindingTargets()` — every `$state` target inside a value, `$source` params included. */
const collectStateBindingTargets = (value: unknown, stateNames: Set<string>): void => {
  if (!isPhpArray(value)) return;
  const binding = readComposedViewBinding(value);
  if (binding !== null) {
    if (binding.kind === "state" && binding.isWellFormed) stateNames.add(binding.target);
    if (binding.kind === "source") collectStateBindingTargets(binding.sourceParams, stateNames);
    return;
  }
  for (const [, nestedValue] of entriesOf(value)) collectStateBindingTargets(nestedValue, stateNames);
};

/** `ComposedViewNode::collectBindings()` — the bindings of a node's props by prop path (`data`, `source.url`). */
const collectBindingsByPropPath = (
  propPath: string,
  propValue: unknown,
  bindingsByPropPath: Array<[string, ComposedViewBinding]>,
): void => {
  const binding = readComposedViewBinding(propValue);
  if (binding !== null) {
    bindingsByPropPath.push([propPath, binding]);
    return;
  }
  if (!isPhpArray(propValue)) return;
  for (const [nestedKey, nestedValue] of entriesOf(propValue))
    collectBindingsByPropPath(`${propPath}.${nestedKey}`, nestedValue, bindingsByPropPath);
};

/**
 * Whether an `openEntity` `ref` names the current row's reference — a well-formed `{"$item": "<key>"}` binding
 * (`ComposedViewBindingResolution::isRowBoundReference()`): the entity is the row's own, never the action's.
 */
const isRowBoundEntityReference = (reference: unknown): boolean => {
  const binding = readComposedViewBinding(reference);
  return binding !== null && binding.kind === "item" && binding.isWellFormed;
};

/** `ComposedViewNodeProp::acceptsLiteral()`. */
const propAcceptsLiteral = (prop: ComposedNodePropSchema, value: unknown): boolean => {
  if (prop.allowedValues !== null && !prop.allowedValues.some((allowedValue) => allowedValue === value)) return false;
  return prop.types.some((type) => {
    switch (type) {
      case "string":
        return typeof value === "string";
      case "number":
        return typeof value === "number";
      case "boolean":
        return typeof value === "boolean";
      case "array":
        return isPhpArray(value) && isPhpList(value);
      case "object":
        return isPhpArray(value) && (isEmptyContainer(value) || !isPhpList(value));
      default:
        return false;
    }
  });
};

const containsTripleBackticks = (value: unknown): boolean => {
  if (typeof value === "string") return value.includes("```");
  if (!isPhpArray(value)) return false;
  return entriesOf(value).some(([key, nested]) => key.includes("```") || containsTripleBackticks(nested));
};

/** The reader — one instance per tree, like the PHP `ComposedViewTreeReading`. */
class ComposedViewTreeReader {
  readonly tree: ComposedViewTree = {
    title: null,
    state: {},
    data: {},
    computed: {},
    actions: {},
    view: null,
    findings: [],
    liftedWidgets: [],
  };
  protected readNodeCount = 0;
  protected inlineCellCount = 0;

  constructor(protected readonly schema: ComposedNodeSchema) {}

  get budgets() {
    return this.schema.budgets;
  }

  addFinding(code: ComposedViewFindingCode, path: string, message: string) {
    this.tree.findings.push({ code, path, message });
  }

  read(widgetFields: PlainObject) {
    this.readTitle(widgetFields.title);
    this.readState(widgetFields.state ?? []);
    this.readData(widgetFields.data ?? []);
    this.readComputed(widgetFields.computed ?? []);
    this.readActions(widgetFields.actions ?? []);
    this.declareStateNamesSetByEvents(widgetFields.view);
    if (widgetFields.view !== undefined && widgetFields.view !== null) {
      this.tree.view = this.readRootNode(widgetFields.view);
    }
    this.flagInlineFiguresWithoutSource();
    this.flagInitialStatesOutsideTheirChoices();
    this.flagStateNamesNothingReads();
    this.flagItemFieldsMissingFromTheirRows();
    this.dedupeFindings();
  }

  /**
   * `ComposedViewTreeReading::declareStateNamesSetByEvents()` — every `{"set": <name>}` event of a node of a known type
   * declares an undeclared, allowed name that is no dataset or computed value with null (within the state budget).
   */
  declareStateNamesSetByEvents(wireNode: unknown) {
    if (!isPlainObject(wireNode) || typeof wireNode.type !== "string" || !hasOwn(this.schema.nodeTypes, wireNode.type))
      return;
    for (const prop of this.schema.nodeTypes[wireNode.type].props) {
      const eventValue = hasOwn(wireNode, prop.name) ? wireNode[prop.name] : undefined;
      if (
        prop.types.includes("event") &&
        isPlainObject(eventValue) &&
        Object.keys(eventValue).length === 1 &&
        typeof eventValue.set === "string"
      ) {
        this.declareStateNameSetByEvent(eventValue.set);
      }
    }
    const slotWireNodeLists: unknown[] = [wireNode.children];
    if (isPhpArray(wireNode.slots)) {
      for (const [slotName, slotWireNodes] of entriesOf(wireNode.slots)) {
        if (slotName !== DEFAULT_SLOT_NAME || !hasOwn(wireNode, "children")) slotWireNodeLists.push(slotWireNodes);
      }
    }
    for (const slotWireNodes of slotWireNodeLists) {
      if (!isPhpArray(slotWireNodes) || !isPhpList(slotWireNodes)) continue;
      for (const [, slotWireNode] of entriesOf(slotWireNodes)) this.declareStateNamesSetByEvents(slotWireNode);
    }
  }

  declareStateNameSetByEvent(stateName: string) {
    if (
      !isAllowedName(stateName) ||
      hasOwn(this.tree.state, stateName) ||
      hasOwn(this.tree.data, stateName) ||
      hasOwn(this.tree.computed, stateName) ||
      Object.keys(this.tree.state).length >= this.budgets.maxStateKeys
    ) {
      return;
    }
    this.tree.state[stateName] = null;
  }

  /** `ComposedViewNode::getStateNamesSetByEvents()`. */
  stateNamesSetByEvents(node: ComposedViewNode): string[] {
    const stateNames: string[] = [];
    for (const prop of this.schema.nodeTypes[node.type]?.props ?? []) {
      const eventValue = hasOwn(node.props, prop.name) ? node.props[prop.name] : undefined;
      if (prop.types.includes("event") && isPlainObject(eventValue) && typeof eventValue.set === "string")
        stateNames.push(eventValue.set);
    }
    return stateNames;
  }

  /** `ComposedViewTreeReading::flagInitialStatesOutsideTheirChoices()`. */
  flagInitialStatesOutsideTheirChoices() {
    if (this.tree.view === null) return;
    for (const [nodePath, node] of getComposedViewSubtreeNodesByPath(this.tree.view, "view")) {
      const optionsProp = this.schema.nodeTypes[node.type]?.optionsProp;
      const choices =
        optionsProp !== undefined && hasOwn(node.props, optionsProp) ? node.props[optionsProp] : undefined;
      if (!Array.isArray(choices)) continue;
      const choiceValues = readChoiceValues(choices);
      for (const stateName of new Set(this.stateNamesSetByEvents(node))) {
        const initialValue = hasOwn(this.tree.state, stateName) ? this.tree.state[stateName] : null;
        if (initialValue === null || initialValue === "") continue;
        if (
          (typeof initialValue === "string" || typeof initialValue === "number") &&
          choiceValues.includes(String(initialValue))
        )
          continue;
        this.addFinding(
          "INVALID_INITIAL_CHOICE",
          nodePath,
          `the state "${stateName}" starts as ${JSON.stringify(initialValue)}, none of the choices (${choiceValues.join(", ")}) — start it with a choice's id`,
        );
      }
    }
  }

  /** `ComposedViewTreeReading::flagStateNamesNothingReads()` — a state a node sets that nothing else reads. */
  flagStateNamesNothingReads() {
    if (Object.keys(this.tree.state).length === 0) return;
    const readStateNames = new Set<string>();
    const setStateNames = new Set<string>();
    for (const expressionSource of Object.values(this.tree.computed)) {
      for (const identifier of findComposedViewExpressionIdentifiers(expressionSource)) readStateNames.add(identifier);
    }
    collectStateBindingTargets(this.tree.data, readStateNames);
    collectStateBindingTargets(this.tree.actions, readStateNames);
    for (const node of this.tree.view === null ? [] : getComposedViewSubtreeNodes(this.tree.view)) {
      const ownSetStateNames = this.stateNamesSetByEvents(node);
      ownSetStateNames.forEach((setStateName) => setStateNames.add(setStateName));
      const nodeReadStateNames = new Set<string>();
      collectStateBindingTargets(node.props, nodeReadStateNames);
      nodeReadStateNames.forEach((readStateName) => {
        if (!ownSetStateNames.includes(readStateName)) readStateNames.add(readStateName);
      });
      if (this.schema.nodeTypes[node.type]?.submitsInputs !== true) continue;
      for (const formSubtreeNode of getComposedViewSubtreeNodes(node)) {
        collectStateBindingTargets(formSubtreeNode.props, readStateNames);
        this.stateNamesSetByEvents(formSubtreeNode).forEach((submittedStateName) =>
          readStateNames.add(submittedStateName),
        );
      }
    }
    for (const stateName of Object.keys(this.tree.state)) {
      if (setStateNames.has(stateName) && !readStateNames.has(stateName)) {
        this.addFinding(
          "UNREAD_STATE",
          `state.${stateName}`,
          `nothing reads the state "${stateName}" — a Tab or input changes only what reads its state: bind a node to {"$state":"${stateName}"} or name it in a computed value`,
        );
      }
    }
  }

  /** `ComposedViewTreeReading::flagItemFieldsMissingFromTheirRows()`. */
  flagItemFieldsMissingFromTheirRows() {
    if (this.tree.view !== null) this.flagItemFieldsOfSubtree(this.tree.view, "view", null);
  }

  flagItemFieldsOfSubtree(node: ComposedViewNode, path: string, itemRows: PlainObject[] | null) {
    if (itemRows !== null) {
      const bindingsByPropPath: Array<[string, ComposedViewBinding]> = [];
      for (const [propName, propValue] of Object.entries(node.props))
        collectBindingsByPropPath(propName, propValue, bindingsByPropPath);
      for (const [propPath, binding] of bindingsByPropPath) {
        if (binding.kind !== "item" || !binding.isWellFormed) continue;
        if (itemRows.some((row) => readComposedViewRowField(row, binding.target).found)) continue;
        this.addFinding(
          "UNKNOWN_ITEM_KEY",
          `${path}.${propPath}`,
          `no row of the bound rows has the field "${binding.target}" — it renders empty; the rows have: ${buildListedRowKeys(itemRows[0]).join(", ")}`,
        );
      }
    }
    const itemsProp = this.schema.nodeTypes[node.type]?.itemsProp;
    const childItemRows = this.schema.nodeTypes[node.type]?.providesItemScope
      ? this.findResolvedRowsOfItems(
          itemsProp !== undefined && hasOwn(node.props, itemsProp) ? node.props[itemsProp] : undefined,
        )
      : itemRows;
    for (const [slotName, slotNodes] of Object.entries(node.nodesBySlotName)) {
      const slotPath = slotName === DEFAULT_SLOT_NAME ? `${path}.children` : `${path}.slots.${slotName}`;
      slotNodes.forEach((slotNode, childPosition) =>
        this.flagItemFieldsOfSubtree(slotNode, `${slotPath}[${childPosition}]`, childItemRows),
      );
    }
  }

  /** `ComposedViewTreeReading::findResolvedRowsOfItems()` — the object rows of a resolved `$from` list, or null. */
  findResolvedRowsOfItems(itemsValue: unknown): PlainObject[] | null {
    const binding = readComposedViewBinding(itemsValue);
    let fromBinding: unknown = undefined;
    if (binding?.kind === "from") fromBinding = binding.wireValue.$from;
    if (binding?.kind === "data" && hasOwn(this.tree.data, binding.target)) {
      const dataset = this.tree.data[binding.target];
      fromBinding = isPlainObject(dataset) ? dataset.$from : undefined;
    }
    if (!isPlainObject(fromBinding) || fromBinding.state !== RESOLVED_FROM_STATE || !isPhpList(fromBinding.value))
      return null;
    const objectRows = (fromBinding.value as unknown[]).filter(
      (row): row is PlainObject => isPlainObject(row) && !isEmptyContainer(row),
    );
    return objectRows.length === 0 ? null : objectRows;
  }

  /**
   * `ComposedViewTreeReading::flagInlineFiguresWithoutSource()` — each data node of the KEPT view that shows inline
   * figures without its `source`, a source on a data node around it or a source line (`SourceRef`) in the tree gets
   * one `INLINE_VALUE_WITHOUT_SOURCE`; the node stays.
   */
  flagInlineFiguresWithoutSource() {
    const view = this.tree.view;
    if (view === null) return;
    if (getComposedViewSubtreeNodes(view).some((node) => this.sourcingOf(node.type)?.namesSources === true)) return;
    this.flagUnsourcedDataNodesOfSubtree(view, "view", false, false);
  }

  sourcingOf(nodeType: string) {
    return hasOwn(this.schema.nodeTypes, nodeType) ? this.schema.nodeTypes[nodeType].sourcing : undefined;
  }

  flagUnsourcedDataNodesOfSubtree(
    node: ComposedViewNode,
    path: string,
    isSourcedAround: boolean,
    itemsAreInline: boolean,
  ) {
    const needsSource = this.sourcingOf(node.type)?.needsSource === true;
    let isSourced = isSourcedAround || (needsSource && hasOwn(node.props, "source"));
    if (needsSource && !isSourced && this.subtreeHoldsInlineFigures(node, itemsAreInline)) {
      this.addFinding(
        "INLINE_VALUE_WITHOUT_SOURCE",
        path,
        `a ${node.type} shows figures written inline without \`source\` — give it the publisher and page as \`source\` {"name","url"} (or a SourceRef beside it)`,
      );
      isSourced = true;
    }
    const childItemsAreInline = this.schema.nodeTypes[node.type]?.providesItemScope
      ? this.nodeHoldsInlineRows(node, itemsAreInline)
      : itemsAreInline;
    for (const [slotName, slotNodes] of Object.entries(node.nodesBySlotName)) {
      const slotPath = slotName === DEFAULT_SLOT_NAME ? `${path}.children` : `${path}.slots.${slotName}`;
      slotNodes.forEach((slotNode, childPosition) =>
        this.flagUnsourcedDataNodesOfSubtree(slotNode, `${slotPath}[${childPosition}]`, isSourced, childItemsAreInline),
      );
    }
  }

  subtreeHoldsInlineFigures(node: ComposedViewNode, itemsAreInline: boolean): boolean {
    if (this.nodeHoldsInlineRows(node, itemsAreInline) || this.nodeHoldsInlineFigure(node, itemsAreInline)) return true;
    const childItemsAreInline = this.schema.nodeTypes[node.type]?.providesItemScope
      ? this.nodeHoldsInlineRows(node, itemsAreInline)
      : itemsAreInline;
    return Object.values(node.nodesBySlotName).some((slotNodes) =>
      slotNodes.some((slotNode) => this.subtreeHoldsInlineFigures(slotNode, childItemsAreInline)),
    );
  }

  nodeHoldsInlineRows(node: ComposedViewNode, itemsAreInline: boolean): boolean {
    return (this.sourcingOf(node.type)?.rowsProps ?? []).some((rowsPropName) => {
      if (!hasOwn(node.props, rowsPropName)) return false;
      const rowsValue = node.props[rowsPropName];
      const binding = readComposedViewBinding(rowsValue);
      if (binding === null) return isPhpArray(rowsValue) && !isEmptyContainer(rowsValue);
      if (binding.kind === "data") return this.isInlineDataset(binding.target);
      return binding.kind === "item" && itemsAreInline;
    });
  }

  nodeHoldsInlineFigure(node: ComposedViewNode, itemsAreInline: boolean): boolean {
    return (this.sourcingOf(node.type)?.figureProps ?? []).some((figurePropName) => {
      if (!hasOwn(node.props, figurePropName)) return false;
      const figureValue = node.props[figurePropName];
      const binding = readComposedViewBinding(figureValue);
      if (binding !== null) return binding.kind === "item" && itemsAreInline;
      if (typeof figureValue === "number") return true;
      return typeof figureValue === "string" && FIGURE_TEXT_PATTERN.test(figureValue);
    });
  }

  /** Written rows (not a `$from` / `$source` binding) with at least one row. */
  isInlineDataset(dataName: string): boolean {
    if (!hasOwn(this.tree.data, dataName)) return false;
    const dataset = this.tree.data[dataName];
    return isPhpArray(dataset) && !isEmptyContainer(dataset) && readComposedViewBinding(dataset) === null;
  }

  readTitle(titleField: unknown) {
    if (typeof titleField !== "string") return;
    if (titleField.includes("```")) {
      this.addFinding("INVALID_PROP", "title", "a string with three backticks would end the widget fence");
      return;
    }
    this.tree.title = titleField;
  }

  /**
   * `ComposedViewTreeReading::readRootNode()` — the root held to the root rule; a refused `Widget` root naming a known
   * widget is lifted, a layout root the lift emptied is no view.
   */
  readRootNode(wireRootNode: unknown): ComposedViewNode | null {
    const liftedCountBefore = this.tree.liftedWidgets.length;
    const rootNode = this.readNode(wireRootNode, "view", 1, false);
    if (rootNode !== null && this.isPrunedByTheLift(rootNode, liftedCountBefore)) {
      this.pruneNodeEmptiedByTheLift(rootNode, "view");
      return null;
    }
    if (rootNode === null || this.isNodeAcceptedAsRoot(rootNode)) return rootNode;
    this.readNodeCount -= countComposedViewNodes(rootNode);
    if (this.isLiftedWhenRefused(rootNode)) {
      this.liftWidgetNode(rootNode, "view", null);
      return null;
    }
    this.addFinding(
      "INVALID_NODE",
      "view",
      rootNode.type === "Widget"
        ? this.buildNotEmbeddableMessage(rootNode)
        : `a ${rootNode.type} cannot be the root of the view`,
    );
    return null;
  }

  /** `WidgetComposedViewNode::isLiftedWhenRefused()` — a `Widget` node naming a known widget (any registered type). */
  isLiftedWhenRefused(node: ComposedViewNode): boolean {
    return node.type === "Widget" && this.findKnownWidgetName(node) !== undefined;
  }

  /** The canonical name of the known widget a `Widget` node names (a legacy `…Directive` name resolved), or undefined. */
  findKnownWidgetName(node: ComposedViewNode): string | undefined {
    if (typeof node.props.widget !== "string") return undefined;
    return resolveCanonicalWidgetName(node.props.widget, new Set(this.schema.widgetTypes ?? []));
  }

  /** `ComposedViewTreeReading::liftWidgetNode()` — out of the tree with its note, shown as its own widget after the view. */
  liftWidgetNode(node: ComposedViewNode, path: string, refusingSlot: ComposedNodeSlotSchema | null) {
    const widget = this.findKnownWidgetName(node) ?? "";
    const fields = isPlainObject(node.props.fields) ? (node.props.fields as Record<string, JsonValue>) : {};
    this.tree.liftedWidgets.push({ path, widget, fields });
    const embeddableWidget = this.findEmbeddableWidget(node);
    const reason =
      embeddableWidget !== undefined && refusingSlot !== null
        ? `"${widget}" needs a ${embeddableWidget.sizeClass} slot, the slot "${refusingSlot.name}" is ${refusingSlot.sizeClass}`
        : `"${widget}" does not embed`;
    this.addFinding("WIDGET_LIFTED", path, `${reason} — it is shown as its own widget block after the view`);
  }

  /** `ComposedViewTreeReading::isPrunedByTheLift()` — a layout node the lift left with no node. */
  isPrunedByTheLift(node: ComposedViewNode, liftedCountBefore: number): boolean {
    if (this.tree.liftedWidgets.length === liftedCountBefore) return false;
    if (!(this.schema.nodeTypes[node.type]?.categories ?? []).includes("layout")) return false;
    return Object.values(node.nodesBySlotName).every((slotNodes) => slotNodes.length === 0);
  }

  /** `ComposedViewTreeReading::pruneNodeEmptiedByTheLift()` — its count back, its slot-minimum notes gone. */
  pruneNodeEmptiedByTheLift(node: ComposedViewNode, path: string) {
    this.readNodeCount -= countComposedViewNodes(node);
    this.tree.findings = this.tree.findings.filter(
      (finding) =>
        finding.code !== "INVALID_SLOT_NODE_COUNT" || (finding.path !== path && !finding.path.startsWith(`${path}.`)),
    );
  }

  /** `ComposedViewNode::isAcceptedAsRoot()` — every node, but an embedded widget only when it is embeddable. */
  isNodeAcceptedAsRoot(node: ComposedViewNode): boolean {
    return node.type !== "Widget" || this.findEmbeddableWidget(node) !== undefined;
  }

  /** The embeddable widget a `Widget` node names (a legacy `…Directive` name resolved as everywhere), or undefined. */
  findEmbeddableWidget(node: ComposedViewNode): { sizeClass: ComposedSizeClass } | undefined {
    if (typeof node.props.widget !== "string") return undefined;
    const embeddableWidgetName = resolveCanonicalWidgetName(
      node.props.widget,
      new Set(Object.keys(this.schema.embeddableWidgets ?? {})),
    );
    return embeddableWidgetName === undefined ? undefined : this.schema.embeddableWidgets[embeddableWidgetName];
  }

  /** The PHP findings are an ObjectSet keyed by code, path and message — a repeat is kept once there, so here too. */
  dedupeFindings() {
    const seenFindingKeys = new Set<string>();
    this.tree.findings = this.tree.findings.filter((finding) => {
      const findingKey = `${finding.code}@${finding.path}@${finding.message}`;
      if (seenFindingKeys.has(findingKey)) return false;
      seenFindingKeys.add(findingKey);
      return true;
    });
  }

  readState(stateField: unknown) {
    if (!isPhpArray(stateField) || (!isEmptyContainer(stateField) && isPhpList(stateField))) {
      this.addFinding("INVALID_STATE", "state", "`state` must be an object of name → initial value");
      return;
    }
    for (const [stateName, initialValue] of entriesOf(stateField)) {
      if (Object.keys(this.tree.state).length >= this.budgets.maxStateKeys) {
        this.addFinding("BUDGET_EXCEEDED", `state.${stateName}`, `more than ${this.budgets.maxStateKeys} state names`);
        break;
      }
      if (!isAllowedName(stateName)) {
        this.addFinding(
          "INVALID_STATE",
          `state.${stateName}`,
          "a state name is an identifier (letters, digits, underscore)",
        );
        continue;
      }
      const isScalar = ["string", "number", "boolean"].includes(typeof initialValue);
      if (!isScalar && initialValue !== null) {
        this.addFinding("INVALID_STATE", `state.${stateName}`, "an initial value is a string, number, boolean or null");
        continue;
      }
      if (typeof initialValue === "string" && initialValue.includes("```")) {
        this.addFinding(
          "INVALID_STATE",
          `state.${stateName}`,
          "a string with three backticks would end the widget fence",
        );
        continue;
      }
      if (typeof initialValue === "string" && codePointLength(initialValue) > this.budgets.maxStateStringLength) {
        this.addFinding(
          "BUDGET_EXCEEDED",
          `state.${stateName}`,
          `an initial string longer than ${this.budgets.maxStateStringLength} characters`,
        );
        continue;
      }
      this.tree.state[stateName] = initialValue as string | number | boolean | null;
    }
  }

  readData(dataField: unknown) {
    if (!isPhpArray(dataField) || (!isEmptyContainer(dataField) && isPhpList(dataField))) {
      this.addFinding("INVALID_PROP", "data", "`data` must be an object of name → rows");
      return;
    }
    for (const [dataName, dataset] of entriesOf(dataField)) {
      const path = `data.${dataName}`;
      if (!isAllowedName(dataName) || hasOwn(this.tree.state, dataName)) {
        this.addFinding("INVALID_PROP", path, "a data name is an identifier that is not also a state name");
        continue;
      }
      const binding = readComposedViewBinding(dataset);
      if (binding !== null) {
        if (binding.kind !== "from" && binding.kind !== "source") {
          this.addFinding("UNKNOWN_BINDING", path, "a dataset binds a tool result ($from) or a data source ($source)");
          continue;
        }
        if (containsTripleBackticks(dataset)) {
          this.addFinding("INVALID_PROP", path, "a string with three backticks would end the widget fence");
          continue;
        }
        if (this.isValidBinding(binding, path, false)) this.tree.data[dataName] = dataset as JsonValue;
        continue;
      }
      if (!isPhpArray(dataset) || !isPhpList(dataset)) {
        this.addFinding("INVALID_PROP", path, "a dataset is a list of rows, a $from or a $source");
        continue;
      }
      if (containsTripleBackticks(dataset)) {
        this.addFinding("INVALID_PROP", path, "a string with three backticks would end the widget fence");
        continue;
      }
      // `json_decode(…, true)` makes `{}` and `[]` one empty array — kept as the empty LIST on both sides.
      if (this.isValidInlineData(dataset, path, false))
        this.tree.data[dataName] = (isEmptyContainer(dataset) ? [] : dataset) as JsonValue;
    }
  }

  readComputed(computedField: unknown) {
    if (!isPhpArray(computedField) || (!isEmptyContainer(computedField) && isPhpList(computedField))) {
      this.addFinding("INVALID_EXPRESSION", "computed", "`computed` must be an object of name → expression");
      return;
    }
    for (const [computedName, expressionSource] of entriesOf(computedField)) {
      const path = `computed.${computedName}`;
      if (Object.keys(this.tree.computed).length >= this.budgets.maxComputedExpressions) {
        this.addFinding("BUDGET_EXCEEDED", path, `more than ${this.budgets.maxComputedExpressions} computed values`);
        break;
      }
      if (
        !isAllowedName(computedName) ||
        hasOwn(this.tree.state, computedName) ||
        hasOwn(this.tree.data, computedName)
      ) {
        this.addFinding(
          "INVALID_EXPRESSION",
          path,
          "a computed name is an identifier that is not also a state or data name",
        );
        continue;
      }
      if (typeof expressionSource !== "string" || expressionSource.trim() === "") {
        this.addFinding("INVALID_EXPRESSION", path, "an expression is a non-empty string");
        continue;
      }
      if (codePointLength(expressionSource) > this.budgets.maxExpressionSourceLength) {
        this.addFinding(
          "BUDGET_EXCEEDED",
          path,
          `an expression longer than ${this.budgets.maxExpressionSourceLength} characters`,
        );
        continue;
      }
      if (expressionSource.includes("```")) {
        this.addFinding("INVALID_EXPRESSION", path, "a string with three backticks would end the widget fence");
        continue;
      }
      this.tree.computed[computedName] = expressionSource;
    }
  }

  readActions(actionsField: unknown) {
    if (!isPhpArray(actionsField) || (!isEmptyContainer(actionsField) && isPhpList(actionsField))) {
      this.addFinding("INVALID_ACTION", "actions", "`actions` must be an object of name → action");
      return;
    }
    for (const [actionName, action] of entriesOf(actionsField)) {
      const path = `actions.${actionName}`;
      if (Object.keys(this.tree.actions).length >= this.budgets.maxActions) {
        this.addFinding("BUDGET_EXCEEDED", path, `more than ${this.budgets.maxActions} actions`);
        break;
      }
      const actionObject = isPhpArray(action) ? (action as PlainObject) : null;
      const actionType = actionObject?.type;
      if (!isAllowedName(actionName) || typeof actionType !== "string" || !ACTION_TYPES.includes(actionType)) {
        this.addFinding(
          "INVALID_ACTION",
          path,
          `an action has an identifier name and a type of ${ACTION_TYPES.join(", ")}`,
        );
        continue;
      }
      if (
        actionType === "openUrl" &&
        !(typeof actionObject?.url === "string" && /^https?:\/\//i.test(actionObject.url))
      ) {
        this.addFinding("INVALID_ACTION", path, "`openUrl` needs an http(s) `url`");
        continue;
      }
      // `{"$item":"entityRef"}` — inside a Repeat over a resolved `$from` list, each row opens its own entity
      if (
        actionType === "openEntity" &&
        !isRowBoundEntityReference(actionObject?.ref) &&
        !(typeof actionObject?.ref === "string" && actionObject.ref !== "")
      ) {
        this.addFinding(
          "INVALID_ACTION",
          path,
          '`openEntity` needs the platform-minted `ref` of a tool result row, or {"$item":"entityRef"} inside a Repeat',
        );
        continue;
      }
      if (containsTripleBackticks(action)) {
        this.addFinding("INVALID_ACTION", path, "a string with three backticks would end the widget fence");
        continue;
      }
      this.tree.actions[actionName] = action as ComposedViewAction;
    }
  }

  readNode(wireNode: unknown, path: string, depth: number, isInItemScope: boolean): ComposedViewNode | null {
    if (!isPlainObject(wireNode) || typeof wireNode.type !== "string") {
      this.addFinding("INVALID_NODE", path, "a node is an object with a string `type`");
      return null;
    }
    const nodeType = wireNode.type;
    const nodeTypeSchema = Object.prototype.hasOwnProperty.call(this.schema.nodeTypes, nodeType)
      ? this.schema.nodeTypes[nodeType]
      : undefined;
    if (!nodeTypeSchema) {
      this.addFinding("INVALID_NODE", path, `unknown node type "${nodeType}"`);
      return null;
    }
    if (depth > this.budgets.maxDepth) {
      this.addFinding("BUDGET_EXCEEDED", path, `deeper than ${this.budgets.maxDepth} levels`);
      return null;
    }
    if (this.readNodeCount >= this.budgets.maxNodes) {
      this.addFinding("BUDGET_EXCEEDED", path, `more than ${this.budgets.maxNodes} nodes`);
      return null;
    }
    const ownWireFields = Object.fromEntries(
      Object.entries(wireNode).filter(([key]) => key !== "children" && key !== "slots"),
    );
    if (containsTripleBackticks(ownWireFields)) {
      this.addFinding("INVALID_NODE", path, "a string with three backticks would end the widget fence");
      return null;
    }
    this.readNodeCount++;
    const node: ComposedViewNode = {
      type: nodeType,
      id: typeof wireNode.id === "string" ? wireNode.id : null,
      props: {},
      nodesBySlotName: {},
    };
    if (!this.readProps(node, wireNode, path, isInItemScope)) {
      this.readNodeCount--;
      return null;
    }
    const childScope = isInItemScope || nodeTypeSchema.providesItemScope;
    const wireNodesBySlotName: Array<[string, unknown]> = [];
    if (hasOwn(wireNode, "children")) wireNodesBySlotName.push([DEFAULT_SLOT_NAME, wireNode.children]);
    if (isPhpArray(wireNode.slots)) {
      for (const [slotName, slotWireNodes] of entriesOf(wireNode.slots)) {
        if (slotName === DEFAULT_SLOT_NAME && hasOwn(wireNode, "children")) {
          this.addFinding(
            "INVALID_NODE",
            `${path}.slots.${slotName}`,
            "the default slot is written twice (`children` and `slots.children`) — `slots.children` is dropped",
          );
          continue;
        }
        wireNodesBySlotName.push([slotName, slotWireNodes]);
      }
    }
    for (const [slotName, slotWireNodes] of wireNodesBySlotName) {
      const slotPath = slotName === DEFAULT_SLOT_NAME ? `${path}.children` : `${path}.slots.${slotName}`;
      // the slot of THIS node: a Grid's cells narrow with its `columns` — the props are read above
      const declaredSlot = nodeTypeSchema.slots.find((candidate) => candidate.name === slotName);
      const slot = declaredSlot ? resolveComposedSlotForNodeProps(declaredSlot, node.props) : undefined;
      if (!slot) {
        this.addFinding("INVALID_NODE", slotPath, `${nodeType} has no slot "${slotName}" — its nodes are dropped`);
        continue;
      }
      node.nodesBySlotName[slotName] = this.readSlotNodes(slot, slotWireNodes, slotPath, depth, childScope);
    }
    for (const slot of nodeTypeSchema.slots) {
      const slotNodeCount = node.nodesBySlotName[slot.name]?.length ?? 0;
      if (slotNodeCount < slot.minChildren) {
        // the node is kept and renders what it has: a note, never a dropped node
        this.addFinding(
          "INVALID_SLOT_NODE_COUNT",
          `${path}.${slot.name}`,
          `${nodeType} needs at least ${slot.minChildren} node(s) in "${slot.name}", it has ${slotNodeCount}`,
        );
      }
    }
    return node;
  }

  readSlotNodes(
    slot: ComposedNodeSlotSchema,
    slotWireNodes: unknown,
    slotPath: string,
    depth: number,
    isInItemScope: boolean,
  ): ComposedViewNode[] {
    if (!isPhpArray(slotWireNodes) || !isPhpList(slotWireNodes)) {
      this.addFinding("INVALID_NODE", slotPath, "a slot holds a list of nodes");
      return [];
    }
    const slotNodes: ComposedViewNode[] = [];
    for (const [childPosition, slotWireNode] of entriesOf(slotWireNodes)) {
      const childPath = `${slotPath}[${childPosition}]`;
      if (slot.maxChildren !== null && slotNodes.length >= slot.maxChildren) {
        this.addFinding("INVALID_NODE", childPath, `the slot "${slot.name}" holds at most ${slot.maxChildren} node(s)`);
        break;
      }
      const liftedCountBefore = this.tree.liftedWidgets.length;
      const slotNode = this.readNode(slotWireNode, childPath, depth + 1, isInItemScope);
      if (slotNode === null) continue;
      if (this.isPrunedByTheLift(slotNode, liftedCountBefore)) {
        this.pruneNodeEmptiedByTheLift(slotNode, childPath);
        continue;
      }
      if (!this.isNodeAcceptedBySlot(slotNode, slot)) {
        this.readNodeCount -= countComposedViewNodes(slotNode);
        // a refused widget is lifted, never dropped — inside a repeat it would be lifted once per row
        if (this.isLiftedWhenRefused(slotNode) && !isInItemScope) {
          this.liftWidgetNode(slotNode, childPath, slot);
          continue;
        }
        this.addFinding("INVALID_NODE", childPath, this.buildSlotRefusalMessage(slotNode, slot));
        continue;
      }
      slotNodes.push(slotNode);
    }
    return slotNodes;
  }

  /**
   * `ComposedViewNode::buildSlotRefusalMessage()` — with `WidgetComposedViewNode`'s: a widget that does not embed names
   * the way out (its own block next to the ComposedView), an embeddable one too large for the slot names its size class.
   */
  buildSlotRefusalMessage(node: ComposedViewNode, slot: ComposedNodeSlotSchema): string {
    if (node.type === "Widget") {
      const embeddedWidget = this.findEmbeddableWidget(node);
      if (embeddedWidget === undefined) return this.buildNotEmbeddableMessage(node);
      if (!sizeClassFitsSlot(embeddedWidget.sizeClass, slot.sizeClass as ComposedSizeClass)) {
        return `the widget "${String(node.props.widget)}" needs a ${embeddedWidget.sizeClass} slot — the slot "${slot.name}" is ${slot.sizeClass}`;
      }
    }
    return `a ${node.type} does not fit the slot "${slot.name}" (${slot.sizeClass}) — it accepts ${[
      ...slot.acceptsCategories,
      ...slot.acceptsNodeTypes,
    ].join(", ")}`;
  }

  /** `WidgetComposedViewNode::buildNotEmbeddableMessage()` — the embeddable widgets in schema order. */
  buildNotEmbeddableMessage(node: ComposedViewNode): string {
    const embeddableWidgetNames = Object.keys(this.schema.embeddableWidgets ?? {});
    const writtenWidgetName = typeof node.props.widget === "string" ? node.props.widget : "";
    return `"${writtenWidgetName}" is no embeddable widget — show it as its own widget block next to the ComposedView, never as a Widget node; ${
      embeddableWidgetNames.length === 0 ? "no widget embeds" : `only ${embeddableWidgetNames.join(", ")} embed`
    }`;
  }

  /** `ComposedViewNode::isAcceptedBySlot()` — with the embedded-widget rule of `WidgetComposedViewNode`. */
  isNodeAcceptedBySlot(node: ComposedViewNode, slot: ComposedNodeSlotSchema): boolean {
    const categories = this.schema.nodeTypes[node.type]?.categories ?? [];
    if (node.type === "Widget") {
      const embeddedWidget = this.findEmbeddableWidget(node);
      return (
        embeddedWidget !== undefined &&
        sizeClassFitsSlot(embeddedWidget.sizeClass, slot.sizeClass as ComposedSizeClass) &&
        (slot.acceptsNodeTypes.includes("Widget") || slot.acceptsCategories.includes("heavy"))
      );
    }
    if (categories.includes("heavy") && slot.sizeClass === "compact" && slot.acceptsHeavyWhenCompact !== true)
      return false;
    return (
      slot.acceptsNodeTypes.includes(node.type) ||
      categories.some((category) => slot.acceptsCategories.includes(category))
    );
  }

  readProps(node: ComposedViewNode, wireNode: PlainObject, path: string, isInItemScope: boolean): boolean {
    const nodeTypeSchema = this.schema.nodeTypes[node.type];
    for (const [propName, propValue] of Object.entries(wireNode)) {
      if (RESERVED_WIRE_KEYS.includes(propName)) continue;
      const propPath = `${path}.${propName}`;
      const prop = nodeTypeSchema.props.find((candidate) => candidate.name === propName);
      if (!prop) {
        this.addFinding("INVALID_PROP", propPath, `${node.type} has no prop "${propName}" — dropped`);
        continue;
      }
      if (this.isValidPropValue(prop, propValue, propPath, isInItemScope))
        node.props[propName] = propValue as JsonValue;
    }
    for (const prop of nodeTypeSchema.props) {
      if (prop.isRequired && !hasOwn(node.props, prop.name)) {
        this.addFinding("INVALID_NODE", path, `${node.type} needs the prop "${prop.name}" — the node is dropped`);
        return false;
      }
    }
    return true;
  }

  isValidPropValue(
    prop: ComposedNodePropSchema,
    propValue: unknown,
    propPath: string,
    isInItemScope: boolean,
  ): boolean {
    if (prop.types.includes("event")) return this.isValidEvent(propValue, propPath);
    const binding = readComposedViewBinding(propValue);
    if (binding !== null) {
      if (!prop.isBindable) {
        this.addFinding("INVALID_PROP", propPath, "this prop takes a literal, not a binding");
        return false;
      }
      return this.isValidBinding(binding, propPath, isInItemScope);
    }
    if (!propAcceptsLiteral(prop, propValue)) {
      this.addFinding(
        "INVALID_PROP",
        propPath,
        `a ${prop.types.join(" or ")} is expected${prop.allowedValues !== null ? ` (one of ${prop.allowedValues.join(", ")})` : ""}`,
      );
      return false;
    }
    if (typeof propValue === "string" && codePointLength(propValue) > this.budgets.maxTextLength) {
      this.addFinding("BUDGET_EXCEEDED", propPath, `a text longer than ${this.budgets.maxTextLength} characters`);
      return false;
    }
    return !isPhpArray(propValue) || this.isValidInlineData(propValue, propPath, isInItemScope);
  }

  isValidEvent(propValue: unknown, propPath: string): boolean {
    const eventObject = isPlainObject(propValue) && Object.keys(propValue).length === 1 ? propValue : null;
    if (eventObject && typeof eventObject.set === "string" && hasOwn(this.tree.state, eventObject.set)) return true;
    if (eventObject && typeof eventObject.action === "string" && hasOwn(this.tree.actions, eventObject.action))
      return true;
    this.addFinding(
      "UNKNOWN_BINDING",
      propPath,
      'an event is {"set": <a state name>} or {"action": <an action name>} of this tree',
    );
    return false;
  }

  isValidBinding(binding: ComposedViewBinding, propPath: string, isInItemScope: boolean): boolean {
    let bindingProblem: string | null = null;
    if (!binding.isWellFormed) {
      bindingProblem =
        "a malformed binding (one of $state, $computed, $data, $item, $from {toolCall, path}, $source {name, params})";
    } else if (binding.kind === "state" && !hasOwn(this.tree.state, binding.target)) {
      bindingProblem = `no state "${binding.target}"`;
    } else if (binding.kind === "computed" && !hasOwn(this.tree.computed, binding.target)) {
      bindingProblem = `no computed value "${binding.target}"`;
    } else if (binding.kind === "data" && !hasOwn(this.tree.data, binding.target)) {
      bindingProblem = `no dataset "${binding.target}"`;
    } else if (binding.kind === "item" && !isInItemScope) {
      bindingProblem = "$item outside a node that repeats its children";
    } else if (binding.kind === "source") {
      bindingProblem = this.findSourceBindingProblem(binding);
    }
    if (bindingProblem === null) return true;
    this.addFinding("UNKNOWN_BINDING", propPath, `${bindingProblem} — the prop is dropped`);
    return false;
  }

  /** `ComposedViewTreeReading::findSourceBindingProblem()`. */
  findSourceBindingProblem(binding: ComposedViewBinding): string | null {
    const dataSource = hasOwn(this.schema.dataSources ?? {}, binding.target)
      ? this.schema.dataSources[binding.target]
      : undefined;
    if (!dataSource) return `no data source "${binding.target}"`;
    const declaredParams = new Map(dataSource.params.map((param) => [param.name, param]));
    for (const [paramName, paramValue] of Object.entries(binding.sourceParams)) {
      if (!declaredParams.has(paramName)) return `the data source "${binding.target}" has no param "${paramName}"`;
      const paramBinding = readComposedViewBinding(paramValue);
      if (paramBinding !== null && (paramBinding.kind !== "state" || !hasOwn(this.tree.state, paramBinding.target))) {
        return `the param "${paramName}" binds a declared state name only`;
      }
    }
    for (const [paramName, param] of declaredParams) {
      if (param.isRequired && !hasOwn(binding.sourceParams, paramName)) {
        return `the data source "${binding.target}" needs the param "${paramName}"`;
      }
    }
    return null;
  }

  isValidInlineData(inlineData: unknown[] | PlainObject, propPath: string, isInItemScope: boolean): boolean {
    const isList = isPhpList(inlineData);
    if (isList && entriesOf(inlineData).length > this.budgets.maxInlineArrayItems) {
      this.addFinding("BUDGET_EXCEEDED", propPath, `more than ${this.budgets.maxInlineArrayItems} inline items`);
      return false;
    }
    if (
      isPlainObject(inlineData) &&
      hasOwn(inlineData, "value") &&
      !hasOwn(inlineData, "source") &&
      !hasOwn(inlineData, "derivedFrom")
    ) {
      this.addFinding(
        "INLINE_VALUE_WITHOUT_SOURCE",
        propPath,
        "a value without `source` or `derivedFrom` — rendered without a source line",
      );
    }
    for (const [inlineKey, inlineValue] of entriesOf(inlineData)) {
      const nestedPath = isList ? `${propPath}[${inlineKey}]` : `${propPath}.${inlineKey}`;
      const binding = readComposedViewBinding(inlineValue);
      if (binding !== null) {
        if (!this.isValidBinding(binding, nestedPath, isInItemScope)) return false;
        continue;
      }
      if (isPhpArray(inlineValue)) {
        if (!this.isValidInlineData(inlineValue, nestedPath, isInItemScope)) return false;
        continue;
      }
      if (++this.inlineCellCount > this.budgets.maxInlineCells) {
        this.addFinding(
          "BUDGET_EXCEEDED",
          nestedPath,
          `more than ${this.budgets.maxInlineCells} inline cells in the block`,
        );
        return false;
      }
    }
    return true;
  }
}

/** Nodes in a subtree, the node included. */
export const countComposedViewNodes = (node: ComposedViewNode): number =>
  1 +
  Object.values(node.nodesBySlotName).reduce(
    (slotNodeCount, slotNodes) =>
      slotNodeCount + slotNodes.reduce((sum, slotNode) => sum + countComposedViewNodes(slotNode), 0),
    0,
  );

/**
 * The tree of the decoded block fields (without `type`), read and checked against `schema`. Fields nested deeper than
 * the backend decodes give no view; objects keyed `"0"`, `"1"`, … are lists, as `json_decode` makes them.
 */
export const readComposedViewTree = (
  widgetFields: Record<string, unknown>,
  schema: ComposedNodeSchema = getComposedNodeSchema(),
): ComposedViewTree => {
  const reader = new ComposedViewTreeReader(schema);
  if (exceedsJsonContainerDepth(widgetFields)) {
    reader.addFinding(
      "BUDGET_EXCEEDED",
      "block",
      `nested deeper than ${MAX_JSON_CONTAINER_DEPTH} levels — malformed, as the backend decodes it; no view is rendered`,
    );
    return reader.tree;
  }
  reader.read(normalizeToPhpArrays(widgetFields) as PlainObject);
  return reader.tree;
};

const utf8ByteLength = (text: string): number => new TextEncoder().encode(text).length;

/**
 * The tree of a widget `payload` (the fence's non-`type` fields as a JSON string). A payload that is not a JSON object
 * gives an empty tree; one over the source budget (counted on the canonical bytes, `measureComposedViewSourceBytes`)
 * gives no view and one `BUDGET_EXCEEDED` finding, as on the backend.
 */
export const parseComposedViewTree = (
  payload: string,
  schema: ComposedNodeSchema = getComposedNodeSchema(),
): ComposedViewTree => {
  const emptyTree: ComposedViewTree = {
    title: null,
    state: {},
    data: {},
    computed: {},
    actions: {},
    view: null,
    findings: [],
    liftedWidgets: [],
  };
  let widgetFields: unknown;
  try {
    widgetFields = JSON.parse(payload);
  } catch {
    return emptyTree;
  }
  if (!isPlainObject(widgetFields)) return emptyTree;
  // The depth check FIRST: measuring serialises the fields, and a deeper value than the backend decodes would throw.
  if (exceedsJsonContainerDepth(widgetFields)) return readComposedViewTree(widgetFields, schema);
  const sourceBytes = measureComposedViewSourceBytes(widgetFields);
  if (sourceBytes > schema.budgets.maxSourceBytes) {
    emptyTree.findings.push({
      code: "BUDGET_EXCEEDED",
      path: "block",
      message: `the block has ${sourceBytes} bytes, the budget is ${schema.budgets.maxSourceBytes} — no view is rendered`,
    });
    return emptyTree;
  }
  return readComposedViewTree(widgetFields, schema);
};

/**
 * The bytes the source budget counts — `ComposedViewTreeReading::measureSourceBytes()`: the fields without `type` as
 * compact JSON, so the written fence's whitespace never decides; the legacy `writtenSyntax` field some stored fences carry
 * (`WidgetBlock::LEGACY_WRITTEN_SYNTAX_FIELD`) does not count.
 */
export const measureComposedViewSourceBytes = (widgetFields: Record<string, unknown>): number => {
  const { type: unusedType, writtenSyntax: unusedWrittenSyntax, ...fieldsWithoutType } = widgetFields;
  // Measured as PHP encodes the decoded block: objects keyed "0", "1", … are lists there (`[…]`, without the keys).
  return utf8ByteLength(JSON.stringify(normalizeToPhpArrays(fieldsWithoutType)));
};

/** The node in its wire form — `ComposedViewNode::toWireArray()`. */
export const composedViewNodeToWire = (node: ComposedViewNode): Record<string, JsonValue> => {
  const wireNode: Record<string, JsonValue> = { type: node.type };
  if (node.id !== null) wireNode.id = node.id;
  Object.assign(wireNode, node.props);
  const namedSlots: Record<string, JsonValue> = {};
  for (const [slotName, slotNodes] of Object.entries(node.nodesBySlotName)) {
    if (slotNodes.length === 0) continue;
    const slotWireNodes = slotNodes.map(composedViewNodeToWire);
    if (slotName === DEFAULT_SLOT_NAME) wireNode.children = slotWireNodes;
    else namedSlots[slotName] = slotWireNodes;
  }
  if (Object.keys(namedSlots).length > 0) wireNode.slots = namedSlots;
  return wireNode;
};

/** The kept tree in its wire form — `ComposedViewConversationWidget::getWidgetFields()` (empty parts left out). */
export const composedViewTreeToWire = (tree: ComposedViewTree): Record<string, JsonValue> => {
  const wireFields: Record<string, JsonValue> = {};
  if (tree.title !== null) wireFields.title = tree.title;
  if (Object.keys(tree.state).length > 0) wireFields.state = tree.state;
  if (Object.keys(tree.data).length > 0) wireFields.data = tree.data;
  if (Object.keys(tree.computed).length > 0) wireFields.computed = tree.computed;
  if (tree.view !== null) wireFields.view = composedViewNodeToWire(tree.view);
  if (Object.keys(tree.actions).length > 0) wireFields.actions = tree.actions as unknown as JsonValue;
  return wireFields;
};

/** `ComposedViewNode::getNodesOfSubtreeByPath()` — every node of a subtree with its kept-tree path, depth first. */
export const getComposedViewSubtreeNodesByPath = (
  node: ComposedViewNode,
  path: string,
): Array<[string, ComposedViewNode]> => [
  [path, node],
  ...Object.entries(node.nodesBySlotName).flatMap(([slotName, slotNodes]) => {
    const slotPath = slotName === DEFAULT_SLOT_NAME ? `${path}.children` : `${path}.slots.${slotName}`;
    return slotNodes.flatMap((slotNode, childPosition) =>
      getComposedViewSubtreeNodesByPath(slotNode, `${slotPath}[${childPosition}]`),
    );
  }),
];

/** Every node of a subtree, depth first, the node first. */
export const getComposedViewSubtreeNodes = (node: ComposedViewNode): ComposedViewNode[] => [
  node,
  ...Object.values(node.nodesBySlotName).flatMap((slotNodes) => slotNodes.flatMap(getComposedViewSubtreeNodes)),
];

/** The slot schema of a node type, or undefined. */
export const findComposedViewSlotSchema = (
  nodeType: string,
  slotName: string,
  schema: ComposedNodeSchema = getComposedNodeSchema(),
): ComposedNodeSlotSchema | undefined => schema.nodeTypes[nodeType]?.slots.find((slot) => slot.name === slotName);

/**
 * Whether a kept view shows nothing: every node of it is a layout node (a Grid, a Stack or a Surface without a node to
 * hold). The renderer shows the explicit fallback line instead — never a title over an empty Grid.
 */
export const isComposedViewWithoutContent = (
  view: ComposedViewNode,
  schema: ComposedNodeSchema = getComposedNodeSchema(),
): boolean =>
  getComposedViewSubtreeNodes(view).every((node) => {
    const categories = schema.nodeTypes[node.type]?.categories ?? [];
    return categories.length > 0 && categories.every((category) => category === "layout");
  });
