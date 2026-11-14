import { describe, expect, test } from "vitest";
import {
  admitCustomVisualizationHeightMessage,
  clampCustomVisualizationHeight,
  createCustomVisualizationInstanceToken,
  CUSTOM_VISUALIZATION_FRAME_CHANNEL,
  CUSTOM_VISUALIZATION_MAX_HEIGHT_MESSAGES_PER_SECOND,
  readCustomVisualizationFrameMessage,
} from "./custom-visualization-frame-protocol";

const frameWindow = {} as MessageEventSource;
const otherWindow = {} as MessageEventSource;
const token = "a1b2c3";

const read = (data: unknown, source: MessageEventSource | null = frameWindow) =>
  readCustomVisualizationFrameMessage({ data, source }, frameWindow, token);

describe("readCustomVisualizationFrameMessage", () => {
  test("accepts the four message types of the frame with exactly their keys", () => {
    const envelope = { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token };
    expect(read({ ...envelope, type: "heartbeat" })).toEqual({ type: "heartbeat" });
    expect(read({ ...envelope, type: "ready" })).toEqual({ type: "ready" });
    expect(read({ ...envelope, type: "height", height: 412.5 })).toEqual({ type: "height", height: 412.5 });
    expect(read({ ...envelope, type: "error", message: "x is not defined" })).toEqual({
      type: "error",
      message: "x is not defined",
    });
  });

  test("refuses a message from another window, with another token or channel, or of an unknown type", () => {
    const envelope = { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token, type: "ready" };
    expect(read(envelope, otherWindow)).toBeNull();
    expect(read(envelope, null)).toBeNull();
    expect(read({ ...envelope, token: "forged" })).toBeNull();
    expect(read({ ...envelope, channel: "other" })).toBeNull();
    expect(read({ ...envelope, type: "navigate" })).toBeNull();
    expect(read({ ...envelope, type: "__proto__" })).toBeNull();
  });

  test("refuses extra keys, wrong value types, non-finite heights and oversized messages — never a command", () => {
    const envelope = { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token };
    expect(read({ ...envelope, type: "ready", url: "https://example.com" })).toBeNull();
    expect(read({ ...envelope, type: "height", height: "400" })).toBeNull();
    expect(read({ ...envelope, type: "height", height: Number.POSITIVE_INFINITY })).toBeNull();
    expect(read({ ...envelope, type: "height", height: Number.NaN })).toBeNull();
    expect(read({ ...envelope, type: "error", message: "x".repeat(301) })).toBeNull();
    expect(read({ ...envelope, type: "error", message: "x".repeat(2000) })).toBeNull();
    expect(read("ready")).toBeNull();
    expect(read([envelope])).toBeNull();
  });
});

describe("the height rules", () => {
  test("a height is clamped to 80–1200 and rounded up", () => {
    expect(clampCustomVisualizationHeight(10)).toBe(80);
    expect(clampCustomVisualizationHeight(404.2)).toBe(405);
    expect(clampCustomVisualizationHeight(99999)).toBe(1200);
  });

  test("at most the allowed number of height messages per second is applied", () => {
    const appliedAtTimes: number[] = [];
    const admitted = Array.from({ length: CUSTOM_VISUALIZATION_MAX_HEIGHT_MESSAGES_PER_SECOND + 5 }, () =>
      admitCustomVisualizationHeightMessage(appliedAtTimes, 1000),
    );
    expect(admitted.filter(Boolean)).toHaveLength(CUSTOM_VISUALIZATION_MAX_HEIGHT_MESSAGES_PER_SECOND);
    expect(admitCustomVisualizationHeightMessage(appliedAtTimes, 2000)).toBe(true);
  });
});

test("every instance gets its own 128-bit token", () => {
  const firstToken = createCustomVisualizationInstanceToken();
  expect(firstToken).toMatch(/^[0-9a-f]{32}$/);
  expect(createCustomVisualizationInstanceToken()).not.toBe(firstToken);
});
