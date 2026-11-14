import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import {
  ComposedViewNode,
  getComposedViewSubtreeNodes,
  readComposedViewTree,
} from "../../../renderer/widget-tree/composed-view-tree";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import {
  collectBoundStateNames,
  composeFormDeclineSignal,
  composeFormSubmitSignal,
  formatFormFieldValue,
  isFormNameAllowed,
  MAX_FORM_SIGNAL_CHARS,
} from "./compose-form-signal";

/**
 * The frontend half of `composed-view-widget-contract.json`: every example block reads (with the
 * generated schema) into a tree whose `Form` node names the reaction's form, the fields the form sends are the ones the
 * fixture lists, and the composer writes exactly the fixture's sentence from the fixture's state. The backend reader
 * (`ComposedViewConversationWidget::readAnswer()`, `FormComposedViewNodeTest`) reads the same file.
 */
interface ComposedViewContractReaction {
  exampleBlock: number;
  messageType: "SIGNAL" | "TEXT";
  signalSource?: string;
  text: string;
  expectsAnswer: boolean;
  expectsDecline: boolean;
  formName: string;
  formFields: string[];
  fromState?: Record<string, ExpressionValue>;
}

interface ComposedViewContract {
  wireName: string;
  exampleBlocks: string[];
  exampleReactions: ComposedViewContractReaction[];
}

const contract: ComposedViewContract = JSON.parse(
  readFileSync(join(__dirname, "..", "..", "composed-view-widget-contract.json"), "utf8"),
);

const readExampleTree = (exampleBlock: string) => {
  const fenceMatch = /^```widget\n([\s\S]*)\n```$/.exec(exampleBlock);
  expect(fenceMatch).not.toBeNull();
  const { type, ...widgetFields } = JSON.parse(fenceMatch![1]) as Record<string, unknown>;
  expect(type).toBe("ComposedView");
  return readComposedViewTree(widgetFields, getComposedNodeSchema());
};

const findFormNode = (view: ComposedViewNode | null, formName: string): ComposedViewNode | undefined =>
  view === null
    ? undefined
    : getComposedViewSubtreeNodes(view).find((node) => node.type === "Form" && node.props.name === formName);

describe("composed-view-widget-contract.json — the Form sentences", () => {
  it("names the widget", () => {
    expect(contract.wireName).toBe("ComposedView");
  });

  contract.exampleReactions.forEach((exampleReaction, exampleReactionNumber) => {
    it(`reaction ${exampleReactionNumber} is what the Form of its block composes`, () => {
      expect(exampleReaction.messageType).toBe("SIGNAL");
      expect(exampleReaction.signalSource).toBe("FRONTEND_WIDGET_CLICK");
      expect(isFormNameAllowed(exampleReaction.formName)).toBe(true);
      const tree = readExampleTree(contract.exampleBlocks[exampleReaction.exampleBlock]);
      const formNode = findFormNode(tree.view, exampleReaction.formName);
      expect(formNode).toBeDefined();
      if (exampleReaction.expectsDecline) {
        expect(composeFormDeclineSignal(exampleReaction.formName)).toBe(exampleReaction.text);
        return;
      }
      expect(collectBoundStateNames(formNode!)).toEqual(exampleReaction.formFields);
      const state = exampleReaction.fromState ?? {};
      expect(
        composeFormSubmitSignal(
          exampleReaction.formName,
          exampleReaction.formFields.map((fieldName) => [fieldName, state[fieldName]] as const),
        ),
      ).toBe(exampleReaction.text);
    });
  });

  it("keeps every value on its line and the signal under the backend cap", () => {
    expect(formatFormFieldValue("a\r\nb\\c\td")).toBe("a\\nb\\\\c d");
    expect(formatFormFieldValue(false)).toBe("false");
    expect(formatFormFieldValue(null)).toBe("");
    const longValue = "x".repeat(900) + "\\";
    const signal = composeFormSubmitSignal("long", [
      ["first", longValue],
      ["second", longValue],
      ["third", longValue],
    ]);
    expect(signal.length).toBeLessThanOrEqual(MAX_FORM_SIGNAL_CHARS);
    expect(signal.split("\n")).toHaveLength(4);
    signal
      .split("\n")
      .slice(1)
      .forEach((fieldLine) => expect(fieldLine.endsWith("…")).toBe(true));
  });
});
