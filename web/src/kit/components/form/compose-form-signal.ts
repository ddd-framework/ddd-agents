import type {
  ComposedViewNode,
  JsonValue,
} from "../../../renderer/widget-tree/composed-view-tree";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";

/**
 * The reaction sentences of a `ComposedView` `Form` node — the frontend half of
 * `composed-view-widget-contract.json`; the backend reader is `ComposedViewConversationWidget::readAnswer()`:
 *
 *     ComposedView form "<name>" submitted:
 *     - <field>: <value>
 *
 * or `ComposedView form "<name>" declined.` Posted as a FRONTEND_WIDGET_CLICK signal. A stored contract: never reword it.
 */

/** `FormComposedViewNode::FORM_NAME_PATTERN` — a name the quoted span of the signal can carry. */
export const FORM_NAME_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;

/** `AIConversationMessage::MAX_SIGNAL_TEXT_CHARS` — the backend cuts a longer signal, so the composer stays below it. */
export const MAX_FORM_SIGNAL_CHARS = 2000;

const ELLIPSIS = "…";

export const isFormNameAllowed = (formName: unknown): formName is string =>
  typeof formName === "string" && FORM_NAME_PATTERN.test(formName);

/**
 * One value on its signal line: a boolean as `true` / `false`, an empty value as nothing; inside a text a backslash is
 * written `\\`, a line break `\n`, any other control character becomes a space — so a value never leaves its line.
 */
export const formatFormFieldValue = (value: ExpressionValue | undefined): string => {
  if (value === undefined || value === null) return "";
  if (typeof value === "boolean") return value ? "true" : "false";
  if (typeof value === "number") return Number.isFinite(value) ? String(value) : "";
  if (typeof value !== "string") return "";
  return value
    .trim()
    .replace(/\\/g, "\\\\")
    .replace(/\r\n|\r|\n/g, "\\n")
    .replace(/\p{Cc}/gu, " ");
};

/** Cuts an escaped value to `maxLength` characters without splitting an escape (`\\`, `\n`) in two. */
const cutEscapedValue = (escapedValue: string, maxLength: number): string => {
  if (escapedValue.length <= maxLength) return escapedValue;
  let cutValue = escapedValue.slice(0, Math.max(0, maxLength - ELLIPSIS.length));
  const trailingBackslashCount = /\\+$/.exec(cutValue)?.[0].length ?? 0;
  if (trailingBackslashCount % 2 === 1) cutValue = cutValue.slice(0, -1);
  return cutValue + ELLIPSIS;
};

const buildFieldLine = (fieldName: string, formattedValue: string) =>
  formattedValue === "" ? `- ${fieldName}:` : `- ${fieldName}: ${formattedValue}`;

const joinSignalLines = (headLine: string, formattedFieldValues: ReadonlyArray<readonly [string, string]>) =>
  [
    headLine,
    ...formattedFieldValues.map(([fieldName, formattedValue]) => buildFieldLine(fieldName, formattedValue)),
  ].join("\n");

/**
 * The submit sentence: the head line, then one line per field in the given order. Over {@link MAX_FORM_SIGNAL_CHARS}
 * the values share what the head and the field names leave, each cut to its share with an ellipsis.
 */
export const composeFormSubmitSignal = (
  formName: string,
  fieldValues: ReadonlyArray<readonly [string, ExpressionValue | undefined]>,
): string => {
  const headLine = `ComposedView form "${formName}" submitted:`;
  const formattedFieldValues = fieldValues.map(
    ([fieldName, value]) => [fieldName, formatFormFieldValue(value)] as const,
  );
  const fullSignal = joinSignalLines(headLine, formattedFieldValues);
  if (fullSignal.length <= MAX_FORM_SIGNAL_CHARS || formattedFieldValues.length === 0) return fullSignal;
  const lengthWithoutValues = joinSignalLines(
    headLine,
    formattedFieldValues.map(([fieldName]) => [fieldName, ""] as const),
  ).length;
  // A filled field line is one space plus its value longer than the empty one.
  const valueShare = Math.max(
    ELLIPSIS.length + 1,
    Math.floor((MAX_FORM_SIGNAL_CHARS - lengthWithoutValues) / formattedFieldValues.length) - 1,
  );
  return joinSignalLines(
    headLine,
    formattedFieldValues.map(
      ([fieldName, formattedValue]) => [fieldName, cutEscapedValue(formattedValue, valueShare)] as const,
    ),
  );
};

export const composeFormDeclineSignal = (formName: string) => `ComposedView form "${formName}" declined.`;

/** The state name a prop value reads or writes: a `{"$state": …}` binding or a `{"set": …}` event. */
const readBoundStateName = (propValue: JsonValue): string | undefined => {
  if (typeof propValue !== "object" || propValue === null || Array.isArray(propValue)) return undefined;
  if (typeof propValue.$state === "string") return propValue.$state;
  if (typeof propValue.set === "string") return propValue.set;
  return undefined;
};

const collectBoundStateNamesOfNode = (node: ComposedViewNode, stateNames: string[]): void => {
  for (const propValue of Object.values(node.props)) {
    const stateName = readBoundStateName(propValue);
    if (stateName !== undefined && !stateNames.includes(stateName)) stateNames.push(stateName);
  }
  for (const slotNodes of Object.values(node.nodesBySlotName)) {
    for (const slotNode of slotNodes) collectBoundStateNamesOfNode(slotNode, stateNames);
  }
};

/**
 * The fields a form sends when its `fields` prop names none: every state name its body binds (`{"$state": …}` values,
 * `{"set": …}` events), tree order, each once.
 */
export const collectBoundStateNames = (formNode: ComposedViewNode): string[] => {
  const stateNames: string[] = [];
  for (const slotNodes of Object.values(formNode.nodesBySlotName)) {
    for (const slotNode of slotNodes) collectBoundStateNamesOfNode(slotNode, stateNames);
  }
  return stateNames;
};

/** The state names a list prop (`fields`, `requiredFields`) names, each once, kept only when the tree declares that state. */
export const readStateNameList = (propValue: JsonValue | undefined, state: Record<string, unknown>): string[] => {
  if (!Array.isArray(propValue)) return [];
  const stateNames: string[] = [];
  for (const stateName of propValue) {
    if (
      typeof stateName === "string" &&
      Object.prototype.hasOwnProperty.call(state, stateName) &&
      !stateNames.includes(stateName)
    ) {
      stateNames.push(stateName);
    }
  }
  return stateNames;
};

/** A required field is filled: not empty, not only spaces, and a checkbox ticked. */
export const isFormFieldFilled = (value: ExpressionValue | undefined): boolean => {
  if (value === undefined || value === null || value === false) return false;
  if (typeof value === "string") return value.trim() !== "";
  return true;
};
