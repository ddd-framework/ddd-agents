import { readFileSync } from "node:fs";
import { join } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { parseMultiChoicePayload } from "./multi-choice-widget-payload";
import {
  type AgentsEnvironment,
  appendAnotherAgentSpaceToOwnerText,
  getAgentsEnvironment,
  getWidgetSelfHydrationKeyFieldName,
  setAgentsEnvironment,
} from "../../../provider/environment";

/**
 * The frontend half of `multi-choice-widget-contract.json` under the package defaults (an app with its own block field or
 * suffix keeps its own copy). The backend reader
 * (`MultiChoiceConversationWidget::readAnswer()`, `OwnerQuestionWidgetReadAnswerTest`) reads the very same file: the
 * example blocks carry what the widget's payload parse requires, and every example confirm text is the one the widget
 * posts today for the confirmed options — `formatConfirmAction` and the `sendMessageToChat` text of
 * `MultiChoiceConversationWidget.tsx` (read as text; the formatter is not exported). The payload parse is
 * `parseMultiChoicePayload()` (`multi-choice-widget-payload.ts`).
 */
interface MultiChoiceOption {
  label: string;
  value: string;
  sublabel?: string;
}

interface MultiChoiceContractReaction {
  exampleBlock: number;
  messageType: "TEXT";
  confirmedValues?: string[];
  clickedOnWidgetOfAnotherAgentSpace?: boolean;
  text: string;
  expectedAnotherAgentSpaceKey?: number;
}

interface MultiChoiceContract {
  wireName: string;
  anotherAgentSpaceSuffixTemplate: string;
  exampleBlocks: string[];
  exampleReactions: MultiChoiceContractReaction[];
}

const contract: MultiChoiceContract = JSON.parse(
  readFileSync(join(__dirname, "..", "multi-choice-widget-contract.json"), "utf8"),
);
const widgetSource = readFileSync(join(__dirname, "..", "MultiChoiceConversationWidget.tsx"), "utf8");

/** The ```widget fence's JSON object, as the fence plugin reads it. */
const readFenceFields = (exampleBlock: string): Record<string, unknown> => {
  const fenceMatch = /^```widget\n([\s\S]*)\n```$/.exec(exampleBlock);
  expect(fenceMatch).not.toBeNull();
  return JSON.parse(fenceMatch![1]) as Record<string, unknown>;
};

/** The widget's `formatConfirmAction` (pinned below by source). */
const formatConfirmAction = (template: string, values: string[]): string =>
  template.replace(/\{values\}/g, values.join(", ")).replace(/\{count\}/g, String(values.length));

/** The text the confirm posts: the confirmed options' labels in option order, the app's suffix on the widget of another agent space. */
const composeConfirmText = (
  fields: Record<string, unknown>,
  confirmedValues: string[],
  anotherAgentSpaceKey?: number,
): string => {
  const options = fields.options as MultiChoiceOption[];
  const labels = options.filter((option) => confirmedValues.includes(option.value)).map((option) => option.label);
  const action = formatConfirmAction((fields.confirmAction as string | undefined) ?? "Confirmed: {values}", labels);
  return anotherAgentSpaceKey !== undefined ? appendAnotherAgentSpaceToOwnerText(action, anotherAgentSpaceKey) : action;
};

describe("multi-choice-widget-contract.json — the MultiChoice confirm text", () => {
  // The package's own contract is read under the package defaults (field `agentSpaceId`, no suffix of another agent
  // space); an app that declares its own keeps its own copy of the contract.
  let appEnvironment: AgentsEnvironment = {};
  beforeAll(() => {
    appEnvironment = getAgentsEnvironment();
    setAgentsEnvironment({});
  });
  afterAll(() => {
    setAgentsEnvironment(appEnvironment);
  });

  it("names the widget", () => {
    expect(contract.wireName).toBe("MultiChoice");
  });

  it("is the text the widget posts today: the confirm action over the picked labels, with the app's suffix on another agent space's widget", () => {
    expect(widgetSource).toContain(
      'template.replace(/\\{values\\}/g, values.join(", ")).replace(/\\{count\\}/g, String(values.length));',
    );
    expect(widgetSource).toContain("const values = options.filter((o) => selected.has(o.value)).map((o) => o.label);");
    expect(widgetSource).toContain('const confirmAction = data.confirmAction ?? "Confirmed: {values}";');
    expect(widgetSource).toContain("? appendAnotherAgentSpaceToOwnerText(action, anotherAgentSpaceKey)");
    // The suffix is the app's (`AgentsEnvironment.appendAnotherAgentSpaceToOwnerText`); the contract holds its template.
    expect(appendAnotherAgentSpaceToOwnerText("", 7)).toBe(
      contract.anotherAgentSpaceSuffixTemplate.replace(/\{\w+\}/, "7"),
    );
  });

  it("the payload parse the widget applies: an object with an options list", () => {
    expect(widgetSource).toContain("useMemo(() => parseMultiChoicePayload(payload), [payload])");
    expect(parseMultiChoicePayload('{"options":[]}')).not.toBeNull();
    expect(parseMultiChoicePayload('{"title":"no options"}')).toBeNull();
    expect(parseMultiChoicePayload("not json")).toBeNull();
  });

  contract.exampleBlocks.forEach((exampleBlock, exampleBlockNumber) => {
    it(`example block ${exampleBlockNumber} is a MultiChoice fence whose options the widget renders`, () => {
      const { type, ...fields } = readFenceFields(exampleBlock);
      expect(type).toBe("MultiChoice");
      expect(Array.isArray(fields.options)).toBe(true);
      (fields.options as MultiChoiceOption[]).forEach((option) => {
        expect(typeof option.label).toBe("string");
        expect(typeof option.value).toBe("string");
      });
    });
  });

  contract.exampleReactions
    .filter((exampleReaction) => exampleReaction.confirmedValues !== undefined)
    .forEach((exampleReaction, exampleReactionNumber) => {
      it(`confirm text ${exampleReactionNumber} is what the widget posts for its confirmed options`, () => {
        const fields = readFenceFields(contract.exampleBlocks[exampleReaction.exampleBlock]);
        const anotherAgentSpaceKey = exampleReaction.clickedOnWidgetOfAnotherAgentSpace
          ? (fields[getWidgetSelfHydrationKeyFieldName()] as number)
          : undefined;
        expect(anotherAgentSpaceKey).toBe(exampleReaction.expectedAnotherAgentSpaceKey);
        expect(composeConfirmText(fields, exampleReaction.confirmedValues!, anotherAgentSpaceKey)).toBe(
          exampleReaction.text,
        );
      });
    });
});
