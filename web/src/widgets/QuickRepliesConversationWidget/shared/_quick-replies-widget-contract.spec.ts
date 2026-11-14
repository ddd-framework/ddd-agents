import { readFileSync } from "node:fs";
import { join } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { parseQuickRepliesPayload } from "./quick-replies-widget-payload";
import {
  type AgentsEnvironment,
  appendAnotherAgentSpaceToOwnerText,
  getAgentsEnvironment,
  getWidgetSelfHydrationKeyFieldName,
  setAgentsEnvironment,
} from "../../../provider/environment";

/**
 * The frontend half of `quick-replies-widget-contract.json` under the package defaults (an app with its own block field or
 * suffix keeps its own copy). The backend reader
 * (`QuickRepliesConversationWidget::readAnswer()`, `OwnerQuestionWidgetReadAnswerTest`) reads the very same file: the
 * example blocks render through this widget's payload parser, and every example click text is the one the widget posts
 * today for its option — the `sendMessageToChat` text of `QuickRepliesConversationWidget.tsx` (read as text).
 */
interface QuickRepliesContractReaction {
  exampleBlock: number;
  messageType: "TEXT";
  clickedOption?: string;
  clickedOnWidgetOfAnotherAgentSpace?: boolean;
  text: string;
  expectedAnotherAgentSpaceKey?: number;
}

interface QuickRepliesContract {
  wireName: string;
  anotherAgentSpaceSuffixTemplate: string;
  exampleBlocks: string[];
  exampleReactions: QuickRepliesContractReaction[];
}

const contract: QuickRepliesContract = JSON.parse(
  readFileSync(join(__dirname, "..", "quick-replies-widget-contract.json"), "utf8"),
);
const widgetSource = readFileSync(join(__dirname, "..", "QuickRepliesConversationWidget.tsx"), "utf8");

/** The ```widget fence's JSON object, as the fence plugin reads it. */
const readFenceFields = (exampleBlock: string): Record<string, unknown> => {
  const fenceMatch = /^```widget\n([\s\S]*)\n```$/.exec(exampleBlock);
  expect(fenceMatch).not.toBeNull();
  return JSON.parse(fenceMatch![1]) as Record<string, unknown>;
};

/** The text the widget posts for a click (its `outgoing`, pinned below by source). */
const composeClickText = (option: string, anotherAgentSpaceKey: number | undefined): string =>
  anotherAgentSpaceKey !== undefined ? appendAnotherAgentSpaceToOwnerText(option, anotherAgentSpaceKey) : option;

describe("quick-replies-widget-contract.json — the QuickReplies click text", () => {
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
    expect(contract.wireName).toBe("QuickReplies");
  });

  it("is the text the widget posts today: the option, with the app's suffix on the widget of another agent space", () => {
    expect(widgetSource).toContain(
      "? appendAnotherAgentSpaceToOwnerText(option, anotherAgentSpaceKey)",
    );
    expect(widgetSource).toContain("const result = await sendMessageToChat(outgoing);");
    // The suffix is the app's (`AgentsEnvironment.appendAnotherAgentSpaceToOwnerText`); the contract holds its template.
    expect(appendAnotherAgentSpaceToOwnerText("", 7)).toBe(
      contract.anotherAgentSpaceSuffixTemplate.replace(/\{\w+\}/, "7"),
    );
  });

  contract.exampleBlocks.forEach((exampleBlock, exampleBlockNumber) => {
    it(`example block ${exampleBlockNumber} is a QuickReplies fence the payload parser reads`, () => {
      const { type, ...fields } = readFenceFields(exampleBlock);
      expect(type).toBe("QuickReplies");
      const parsedPayload = parseQuickRepliesPayload(JSON.stringify(fields));
      expect(parsedPayload).not.toBeNull();
      expect(parsedPayload!.options).toEqual(fields.options);
    });
  });

  contract.exampleReactions
    .filter((exampleReaction) => exampleReaction.clickedOption !== undefined)
    .forEach((exampleReaction, exampleReactionNumber) => {
      it(`click text ${exampleReactionNumber} is what the widget posts for its option`, () => {
        const fields = readFenceFields(contract.exampleBlocks[exampleReaction.exampleBlock]);
        expect(fields.options).toContain(exampleReaction.clickedOption);
        const anotherAgentSpaceKey = exampleReaction.clickedOnWidgetOfAnotherAgentSpace
          ? (fields[getWidgetSelfHydrationKeyFieldName()] as number)
          : undefined;
        expect(anotherAgentSpaceKey).toBe(exampleReaction.expectedAnotherAgentSpaceKey);
        expect(composeClickText(exampleReaction.clickedOption!, anotherAgentSpaceKey)).toBe(exampleReaction.text);
      });
    });
});
