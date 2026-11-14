// @vitest-environment jsdom
import type { ReactNode } from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { describe, expect, it } from "vitest";
import { ConversationWidgetRenderContext } from "../../renderer/ConversationWidgetRenderContext";
import {
  WidgetRenderReportContext,
  type WidgetRenderReportEntry,
} from "../../renderer/render-report/widget-render-report";
import { ConceptExplainerConversationWidget } from "./ConceptExplainerConversationWidget";
import type {
  ConceptExplainerStep,
  ConceptExplainerTypeDefinition,
  ConceptExplainerVisualProps,
} from "./shared/concept-explainer-type";
import {
  buildConceptExplainerTypeRegistry,
  conceptExplainerTypes,
  findConceptExplainerType,
  registerConceptExplainerTypes,
} from "./shared/concept-explainer-types";
import { MAX_TITLE_LENGTH, parseConceptExplainerPayload } from "./shared/concept-explainer-widget-payload";
import conceptExplainerPreviewFixtures from "./concept-explainer.preview-fixtures.json";

/** A neutral example type of an app: four states of a day, the e-mail capture shows dusk. */
const DAY_CYCLE_STEPS: ConceptExplainerStep[] = [
  { id: "dawn", label: { key: "Dawn" }, caption: { key: "Dawn: the first light" }, description: { key: "The sky brightens." } },
  { id: "noon", label: { key: "Noon" }, caption: { key: "Noon: the sun at its highest" }, description: { key: "Short shadows." } },
  { id: "dusk", label: { key: "Dusk" }, caption: { key: "Dusk: the last light" }, description: { key: "The sky darkens." } },
  { id: "night", label: { key: "Night" }, caption: { key: "Night: no sun" }, description: { key: "The stars show." } },
];

const ExampleVisual = (props: ConceptExplainerVisualProps) => {
  const { steps, stepIndex } = props;
  return <div>{steps[stepIndex]?.id}</div>;
};

const DAY_CYCLE_TYPE: ConceptExplainerTypeDefinition = {
  typeId: "dayCycle",
  defaultTitle: { key: "How a day passes" },
  subtitle: { key: "From the first light to the night" },
  controlLabel: { key: "Time of day" },
  illustrationLine: { key: "An illustration" },
  readValues: () => ({ values: {}, droppedValueProblems: [], carriesBlockNumbers: false }),
  buildSteps: () => DAY_CYCLE_STEPS,
  emailStepIndex: (steps) => steps.findIndex((step) => step.id === "dusk"),
  Visual: ExampleVisual,
};

/** A second neutral example type: two tide levels. */
const TIDE_LEVEL_TYPE: ConceptExplainerTypeDefinition = {
  typeId: "tideLevel",
  defaultTitle: { key: "How the tide moves" },
  subtitle: { key: "High and low water" },
  controlLabel: { key: "Tide" },
  illustrationLine: { key: "An illustration" },
  readValues: () => ({ values: {}, droppedValueProblems: [], carriesBlockNumbers: false }),
  buildSteps: () => [
    { id: "low", label: { key: "Low" }, caption: { key: "Low water" }, description: { key: "The beach is wide." } },
    { id: "high", label: { key: "High" }, caption: { key: "High water" }, description: { key: "The beach is narrow." } },
  ],
  Visual: ExampleVisual,
};

// As an app registers its own types, before the first render.
registerConceptExplainerTypes([DAY_CYCLE_TYPE, TIDE_LEVEL_TYPE]);

const buildStore = () => configureStore({ reducer: { GlobalReducer: () => ({ languageCode: "en" }) } });

const renderExplainer = (payload: string, options: { email?: boolean } = {}) => {
  const reports: WidgetRenderReportEntry[] = [];
  const reporter = { report: (entry: WidgetRenderReportEntry) => reports.push(entry) };
  const wrap = (children: ReactNode) => (
    <Provider store={buildStore()}>
      <ConversationWidgetRenderContext.Provider value={options.email ? "email" : "conversation"}>
        <WidgetRenderReportContext.Provider value={reporter}>{children}</WidgetRenderReportContext.Provider>
      </ConversationWidgetRenderContext.Provider>
    </Provider>
  );
  const view = render(wrap(<ConceptExplainerConversationWidget payload={payload} interactive />));
  return { ...view, reports };
};

const lastReport = (reports: WidgetRenderReportEntry[]) => reports[reports.length - 1];

describe("parseConceptExplainerPayload", () => {
  it("reads the canonical shape", () => {
    expect(
      parseConceptExplainerPayload(
        JSON.stringify({
          explainer: "tideLevel",
          title: "Rating",
          values: { currentAverage: 4.2, reviewCount: 37 },
          step: 2,
        }),
      ),
    ).toEqual({
      explainer: "tideLevel",
      title: "Rating",
      values: { currentAverage: 4.2, reviewCount: 37 },
      step: 2,
    });
  });

  it("accepts the aliases and ChatGPT's own LearningViz shape", () => {
    expect(parseConceptExplainerPayload(JSON.stringify({ type_id: "dayCycle" })).explainer).toBe(
      "dayCycle",
    );
    const learningVizShape = parseConceptExplainerPayload(JSON.stringify({ parameters: { type_id: "MOON_PHASES" } }));
    expect(learningVizShape.explainer).toBe("MOON_PHASES");
    expect(parseConceptExplainerPayload(JSON.stringify({ explainer: "x", initialStep: "reviews" })).step).toBe(
      "reviews",
    );
  });

  it("never throws, caps the title and keeps scalar values only", () => {
    expect(parseConceptExplainerPayload("not json")).toEqual({ explainer: "", values: {} });
    expect(parseConceptExplainerPayload("[1,2]")).toEqual({ explainer: "", values: {} });
    const parsed = parseConceptExplainerPayload(
      JSON.stringify({
        explainer: "tideLevel",
        title: "x".repeat(400),
        values: { currentAverage: 4, nested: { a: 1 }, list: [1], ["__proto__"]: 1 },
      }),
    );
    expect(parsed.title).toHaveLength(MAX_TITLE_LENGTH);
    expect(parsed.values).toEqual({ currentAverage: 4 });
  });
});

describe("the type registry", () => {
  it("builds a registry from the type definitions and resolves the id loosely", () => {
    const registry = buildConceptExplainerTypeRegistry([DAY_CYCLE_TYPE, TIDE_LEVEL_TYPE]);
    expect(Object.keys(registry).sort()).toEqual(["dayCycle", "tideLevel"]);
    expect(findConceptExplainerType("DayCycle", registry)?.typeId).toBe("dayCycle");
    expect(findConceptExplainerType("tide_level", registry)?.typeId).toBe("tideLevel");
    expect(findConceptExplainerType("MOON_PHASES", registry)).toBeUndefined();
    expect(findConceptExplainerType("--", registry)).toBeUndefined();
  });

  it("adds an app's types to the registry the widget reads", () => {
    expect(conceptExplainerTypes.dayCycle).toBe(DAY_CYCLE_TYPE);
    expect(findConceptExplainerType("day-cycle")?.typeId).toBe("dayCycle");
  });

  it("throws when two files register one type id", () => {
    expect(() => buildConceptExplainerTypeRegistry([DAY_CYCLE_TYPE, DAY_CYCLE_TYPE])).toThrow(/dayCycle/);
    expect(() => registerConceptExplainerTypes([DAY_CYCLE_TYPE])).toThrow(/dayCycle/);
  });
});

describe("ConceptExplainerConversationWidget", () => {
  it("reports empty for a block without an explainer", () => {
    const { reports } = renderExplainer("{}");
    expect(screen.getByText("No explanation to show.")).toBeTruthy();
    expect(lastReport(reports)).toMatchObject({ status: "empty", code: "NO_DATA" });
  });

  it("reports failed for an unknown type and names the known ones", () => {
    const { reports } = renderExplainer(JSON.stringify({ explainer: "MOON_PHASES" }));
    expect(screen.getByText("This explanation is not available.")).toBeTruthy();
    expect(lastReport(reports)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
    const knownTypeIds = Object.keys(conceptExplainerTypes).sort();
    expect(knownTypeIds).toEqual(expect.arrayContaining(["dayCycle", "tideLevel"]));
    expect(lastReport(reports)?.detail).toBe(
      `Unknown explainer "MOON_PHASES" — nothing is shown. Known explainers: ${knownTypeIds.join(", ")}.`,
    );
  });

  it("renders the model's title as written, never as markup", () => {
    renderExplainer(JSON.stringify({ explainer: "dayCycle", title: '<b onmouseover="x">Bold</b> & co' }));
    expect(screen.getByText('<b onmouseover="x">Bold</b> & co')).toBeTruthy();
  });

  it("starts at the step the block names and moves with the step buttons", () => {
    const { reports } = renderExplainer(JSON.stringify({ explainer: "dayCycle", step: "noon" }));
    expect(lastReport(reports)).toMatchObject({ status: "mounted" });
    expect(screen.getByTestId("concept-explainer-caption").textContent).toContain("Noon: the sun at its highest");
    fireEvent.click(screen.getByTestId("concept-explainer-step-dusk"));
    expect(screen.getByTestId("concept-explainer-caption").textContent).toContain("Dusk: the last light");
    expect(screen.getByTestId("concept-explainer-step-dusk").getAttribute("aria-pressed")).toBe("true");
  });

  it("moves through the states with the slider", () => {
    renderExplainer(JSON.stringify({ explainer: "dayCycle" }));
    // The slider's range input: the keyboard and a drag both arrive as its change.
    fireEvent.change(screen.getByRole("slider"), { target: { value: "1" } });
    expect(screen.getByTestId("concept-explainer-caption").textContent).toContain("Noon: the sun at its highest");
  });

  it("shows one state without controls in the e-mail capture", () => {
    renderExplainer(JSON.stringify({ explainer: "dayCycle" }), { email: true });
    expect(screen.queryByRole("slider")).toBeNull();
    expect(screen.queryByTestId("concept-explainer-step-dawn")).toBeNull();
    expect(screen.getByTestId("concept-explainer-caption").textContent).toContain("Dusk: the last light");
  });

  it("renders the package's preview fixtures in their expected states", () => {
    const expectedStatusByState: Record<string, string> = {
      data: "mounted",
      loading: "pending",
      empty: "empty",
      error: "failed",
    };
    for (const fixture of conceptExplainerPreviewFixtures.fixtures) {
      const { reports, unmount } = renderExplainer(JSON.stringify(fixture.payload));
      expect(lastReport(reports)?.status, fixture.name).toBe(expectedStatusByState[fixture.expectedState]);
      unmount();
    }
  });
});
