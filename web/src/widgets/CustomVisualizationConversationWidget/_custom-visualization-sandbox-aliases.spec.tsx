// @vitest-environment jsdom
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { act, cleanup, fireEvent, render } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import {
  type AgentsEnvironment,
  type AgentsSandboxLegacyAliases,
  getAgentsEnvironment,
  setAgentsEnvironment,
} from "../../provider/environment";
import { CustomVisualizationConversationWidget } from "./CustomVisualizationConversationWidget";
import {
  CUSTOM_VISUALIZATION_FRAME_CHANNEL,
  type CustomVisualizationRenderMessage,
} from "./shared/custom-visualization-frame-protocol";

/**
 * The sandbox names an app declares (`AgentsEnvironment.sandboxLegacyAliases`): the host hands them to the frame in its
 * render message, and the frame — the real `custom-visualization-frame.html`, run here in a window of its own — defines
 * the same frozen global under each declared name and sets every theme variable under each declared prefix. Without a
 * declaration only `AGENTS_VISUALIZATION` and the `--agents-*` variables exist.
 */

const frameHtml = readFileSync(resolve(__dirname, "sandbox-frame", "custom-visualization-frame.html"), "utf8");

const DECLARED_ALIASES: AgentsSandboxLegacyAliases = {
  visualizationGlobalNames: ["APP_VISUALIZATION"],
  themeVariablePrefixes: ["--app-"],
};

/** The parts of the frame's window the spec reads (the window of a separate jsdom instance). */
type FrameWindow = typeof globalThis & Record<string, unknown>;

interface FrameDom {
  window: FrameWindow;
}

/** The test environment's jsdom instance (`vitest/jsdom`); its constructor builds the frame's own window. */
const FrameDomConstructor = (jsdom as unknown as { constructor: new (html: string, options: object) => FrameDom })
  .constructor;

interface RunningFrame {
  frameWindow: FrameWindow;
  /** Everything the frame posted to its parent (heartbeats, heights, ready, errors). */
  postedMessages: Array<Record<string, unknown>>;
  close: () => void;
}

/**
 * Loads the frame page with its bootstrap script. The frame turns every script into a `blob:` URL and appends a script
 * element; jsdom neither creates nor loads such URLs, so the spec's stand-ins keep each blob's text and run it when the
 * frame appends its script element — the order and the onload/onerror the frame waits for stay the browser's.
 */
const loadFrame = (): RunningFrame => {
  const postedMessages: Array<Record<string, unknown>> = [];
  const blobTexts = new Map<string, string>();
  const frameDom = new FrameDomConstructor(frameHtml, {
    runScripts: "dangerously",
    pretendToBeVisual: true,
    beforeParse: (frameWindow: FrameWindow) => {
      const frameUrlClass = frameWindow.URL as unknown as {
        createObjectURL: (blob: { scriptText: string }) => string;
        revokeObjectURL: (url: string) => void;
      };
      frameWindow.Blob = class {
        scriptText: string;
        constructor(parts: string[]) {
          this.scriptText = parts.join("");
        }
      } as unknown as typeof Blob;
      frameUrlClass.createObjectURL = (blob) => {
        const blobUrl = `blob:frame/${blobTexts.size}`;
        blobTexts.set(blobUrl, blob.scriptText);
        return blobUrl;
      };
      frameUrlClass.revokeObjectURL = () => undefined;
      frameWindow.postMessage = ((message: Record<string, unknown>) => {
        postedMessages.push(message);
      }) as Window["postMessage"];
      const appendChild = frameWindow.Node.prototype.appendChild;
      frameWindow.Node.prototype.appendChild = function appendAndRunBlobScript<NodeType extends Node>(
        this: Node,
        node: NodeType,
      ): NodeType {
        const appendedNode = appendChild.call(this, node) as NodeType;
        const scriptElement = node as unknown as HTMLScriptElement;
        const scriptText = scriptElement.tagName === "SCRIPT" ? blobTexts.get(scriptElement.src) : undefined;
        if (scriptText !== undefined) {
          queueMicrotask(() => {
            try {
              (frameWindow as unknown as { eval: (code: string) => unknown }).eval(scriptText);
            } catch {
              scriptElement.onerror?.(new frameWindow.Event("error"));
              return;
            }
            scriptElement.onload?.(new frameWindow.Event("load"));
          });
        }
        return appendedNode;
      };
    },
  });
  return { frameWindow: frameDom.window, postedMessages, close: () => frameDom.window.close() };
};

const buildRenderMessage = (
  script: string,
  aliases: { visualizationGlobalNames: string[]; themeVariablePrefixes: string[] },
): CustomVisualizationRenderMessage => ({
  channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL,
  type: "render",
  token: "0123456789abcdef0123456789abcdef",
  html: '<p id="output"></p>',
  css: "#output{color:var(--agents-fg)}",
  script,
  librarySources: [],
  data: { rows: ["Mon", "Tue"] },
  theme: { "--agents-fg": "rgb(1, 2, 3)", "--agents-accent": "rgb(4, 5, 6)" },
  colorScheme: "dark",
  ...aliases,
});

/** Sends the render message as the parent would and waits until the frame reported its script ready or failed. */
const renderInFrame = async (runningFrame: RunningFrame, renderMessage: CustomVisualizationRenderMessage) => {
  const { frameWindow, postedMessages } = runningFrame;
  frameWindow.dispatchEvent(new frameWindow.MessageEvent("message", { data: renderMessage, source: frameWindow as unknown as Window }));
  await vi.waitFor(() => {
    expect(postedMessages.some((message) => message.type === "ready" || message.type === "error")).toBe(true);
  });
};

const readThemeVariableNames = (frameWindow: FrameWindow): string[] =>
  Array.from(frameWindow.document.documentElement.style).filter((propertyName) => propertyName.startsWith("--"));

/** A stored script of an app: it reads the global under the app's own name and draws from its data. */
const SCRIPT_READING_THE_DECLARED_ALIAS =
  'document.getElementById("output").textContent = window.APP_VISUALIZATION.data.rows.join(",") + " " + window.APP_VISUALIZATION.colorScheme;' +
  "window.sameObjectAsTheNeutralGlobal = window.APP_VISUALIZATION === window.AGENTS_VISUALIZATION;";

describe("the sandbox frame with the names an app declared", () => {
  let runningFrame: RunningFrame | null = null;

  afterEach(() => {
    runningFrame?.close();
    runningFrame = null;
  });

  test("a script reading a declared alias global runs and gets the same data as through AGENTS_VISUALIZATION", async () => {
    runningFrame = loadFrame();
    await renderInFrame(
      runningFrame,
      buildRenderMessage(SCRIPT_READING_THE_DECLARED_ALIAS, {
        visualizationGlobalNames: ["APP_VISUALIZATION"],
        themeVariablePrefixes: ["--app-"],
      }),
    );
    const { frameWindow, postedMessages } = runningFrame;
    expect(postedMessages.filter((message) => message.type === "error")).toEqual([]);
    expect(postedMessages.some((message) => message.type === "ready")).toBe(true);
    expect(frameWindow.document.getElementById("output")?.textContent).toBe("Mon,Tue dark");
    expect(frameWindow.sameObjectAsTheNeutralGlobal).toBe(true);
    expect(frameWindow.AGENTS_VISUALIZATION).toEqual({ data: { rows: ["Mon", "Tue"] }, colorScheme: "dark" });
    expect(Object.isFrozen(frameWindow.APP_VISUALIZATION)).toBe(true);
  });

  test("every theme variable exists under the declared prefix as well, with the same value", async () => {
    runningFrame = loadFrame();
    await renderInFrame(
      runningFrame,
      buildRenderMessage("", { visualizationGlobalNames: [], themeVariablePrefixes: ["--app-"] }),
    );
    const rootStyle = runningFrame.frameWindow.document.documentElement.style;
    expect(rootStyle.getPropertyValue("--agents-fg")).toBe("rgb(1, 2, 3)");
    expect(rootStyle.getPropertyValue("--app-fg")).toBe("rgb(1, 2, 3)");
    expect(rootStyle.getPropertyValue("--agents-accent")).toBe("rgb(4, 5, 6)");
    expect(rootStyle.getPropertyValue("--app-accent")).toBe("rgb(4, 5, 6)");
  });

  test("a declared name never replaces a property the window already has", async () => {
    runningFrame = loadFrame();
    const { frameWindow } = runningFrame;
    const ownLocation = frameWindow.location;
    await renderInFrame(
      runningFrame,
      buildRenderMessage("", { visualizationGlobalNames: ["location", "not a name"], themeVariablePrefixes: [] }),
    );
    expect(frameWindow.location).toBe(ownLocation);
    expect(frameWindow["not a name"]).toBeUndefined();
  });

  test("without a declaration only AGENTS_VISUALIZATION and the --agents-* variables exist", async () => {
    runningFrame = loadFrame();
    await renderInFrame(
      runningFrame,
      buildRenderMessage("window.ranWithoutAliases = window.AGENTS_VISUALIZATION.data.rows.length;", {
        visualizationGlobalNames: [],
        themeVariablePrefixes: [],
      }),
    );
    const { frameWindow } = runningFrame;
    expect(frameWindow.ranWithoutAliases).toBe(2);
    expect(Object.keys(frameWindow).filter((globalName) => globalName.endsWith("_VISUALIZATION"))).toEqual([
      "AGENTS_VISUALIZATION",
    ]);
    expect(readThemeVariableNames(frameWindow).sort()).toEqual(["--agents-accent", "--agents-fg"]);
  });

  test("without a declaration a script reading an app's alias fails and the frame reports the error", async () => {
    runningFrame = loadFrame();
    await renderInFrame(
      runningFrame,
      buildRenderMessage(SCRIPT_READING_THE_DECLARED_ALIAS, { visualizationGlobalNames: [], themeVariablePrefixes: [] }),
    );
    const { frameWindow, postedMessages } = runningFrame;
    expect(postedMessages.some((message) => message.type === "error")).toBe(true);
    expect(postedMessages.some((message) => message.type === "ready")).toBe(false);
    expect("APP_VISUALIZATION" in frameWindow).toBe(false);
  });

  test("a theme variable under a prefix nobody declared is not set", async () => {
    runningFrame = loadFrame();
    const renderMessage = buildRenderMessage("", { visualizationGlobalNames: [], themeVariablePrefixes: [] });
    renderMessage.theme = { ...renderMessage.theme, "--app-fg": "rgb(9, 9, 9)" };
    await renderInFrame(runningFrame, renderMessage);
    expect(readThemeVariableNames(runningFrame.frameWindow).sort()).toEqual(["--agents-accent", "--agents-fg"]);
  });
});

describe("the host's render message", () => {
  let appEnvironment: AgentsEnvironment;

  beforeEach(() => {
    appEnvironment = getAgentsEnvironment();
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    setAgentsEnvironment(appEnvironment);
  });

  const renderAndReadRenderMessage = (): CustomVisualizationRenderMessage => {
    render(
      <CustomVisualizationConversationWidget
        payload={JSON.stringify({ title: "Week", script: "draw();", data: { rows: [] } })}
        interactive
        justDelivered
      />,
    );
    const frame = document.querySelector<HTMLIFrameElement>("iframe[data-custom-visualization-frame]");
    if (!frame) throw new Error("no sandbox frame rendered");
    const postMessageSpy = vi.spyOn(frame.contentWindow as Window, "postMessage").mockImplementation(() => undefined);
    act(() => {
      fireEvent.load(frame);
    });
    return postMessageSpy.mock.calls[0][0] as CustomVisualizationRenderMessage;
  };

  test("names the global names and variable prefixes the app declared", () => {
    setAgentsEnvironment({ ...appEnvironment, sandboxLegacyAliases: DECLARED_ALIASES });
    expect(renderAndReadRenderMessage()).toMatchObject({
      visualizationGlobalNames: ["APP_VISUALIZATION"],
      themeVariablePrefixes: ["--app-"],
    });
  });

  test("names none without a declaration, and its theme variables carry only the package's prefix", () => {
    setAgentsEnvironment({ ...appEnvironment, sandboxLegacyAliases: undefined });
    const renderMessage = renderAndReadRenderMessage();
    expect(renderMessage.visualizationGlobalNames).toEqual([]);
    expect(renderMessage.themeVariablePrefixes).toEqual([]);
    expect(Object.keys(renderMessage.theme).every((variableName) => variableName.startsWith("--agents-"))).toBe(true);
  });
});
