// @vitest-environment jsdom
import { RefObject } from "react";
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { useIsMessagesBottomOutOfView } from "./useIsMessagesBottomOutOfView";

type FakeEntry = { target: Element; isIntersecting: boolean };

class FakeIntersectionObserver {
  static instances: FakeIntersectionObserver[] = [];
  callback: (entries: FakeEntry[], observer: FakeIntersectionObserver) => void;
  options: IntersectionObserverInit;
  observed: Element[] = [];
  disconnected = false;

  constructor(
    callback: (entries: FakeEntry[], observer: FakeIntersectionObserver) => void,
    options: IntersectionObserverInit,
  ) {
    this.callback = callback;
    this.options = options;
    FakeIntersectionObserver.instances.push(this);
  }

  observe(element: Element) {
    this.observed.push(element);
  }

  disconnect() {
    this.disconnected = true;
  }

  fire(isIntersecting: boolean) {
    this.callback(
      this.observed.map((target) => ({ target, isIntersecting })),
      this,
    );
  }
}

const bottomRef = (node: HTMLDivElement | null): RefObject<HTMLDivElement | null> =>
  ({ current: node }) as RefObject<HTMLDivElement | null>;

const latestObserver = () => FakeIntersectionObserver.instances[FakeIntersectionObserver.instances.length - 1];

describe("useIsMessagesBottomOutOfView", () => {
  beforeEach(() => {
    FakeIntersectionObserver.instances = [];
    vi.stubGlobal("IntersectionObserver", FakeIntersectionObserver);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("returns false and creates no observer when the scroll container is missing", () => {
    const { result } = renderHook(() =>
      useIsMessagesBottomOutOfView(null, document.createElement("div"), bottomRef(document.createElement("div"))),
    );

    expect(result.current).toBe(false);
    expect(FakeIntersectionObserver.instances).toHaveLength(0);
  });

  it("returns false and creates no observer when the sentinel is missing", () => {
    const { result } = renderHook(() =>
      useIsMessagesBottomOutOfView(document.createElement("div"), document.createElement("div"), bottomRef(null)),
    );

    expect(result.current).toBe(false);
    expect(FakeIntersectionObserver.instances).toHaveLength(0);
  });

  it("observes the sentinel with the 300px bottom rootMargin and the container as root", () => {
    const container = document.createElement("div");
    const sentinel = document.createElement("div");
    renderHook(() => useIsMessagesBottomOutOfView(container, document.createElement("div"), bottomRef(sentinel)));

    const observer = latestObserver();
    expect(observer.observed).toEqual([sentinel]);
    expect(observer.options.root).toBe(container);
    expect(observer.options.rootMargin).toBe("0px 0px 300px 0px");
  });

  it("flips to true when the sentinel leaves the extended viewport, and back to false when it re-enters", () => {
    // Stable inputs across re-renders: the hook re-observes (and resets to false) whenever the container or the list
    // element changes, so fresh elements created inside the render callback would swallow every state flip.
    const container = document.createElement("div");
    const list = document.createElement("div");
    const sentinelRef = bottomRef(document.createElement("div"));
    const { result } = renderHook(() => useIsMessagesBottomOutOfView(container, list, sentinelRef));

    expect(result.current).toBe(false);

    act(() => latestObserver().fire(false));
    expect(result.current).toBe(true);

    act(() => latestObserver().fire(true));
    expect(result.current).toBe(false);
  });

  it("disconnects the observer on unmount", () => {
    const { unmount } = renderHook(() =>
      useIsMessagesBottomOutOfView(
        document.createElement("div"),
        document.createElement("div"),
        bottomRef(document.createElement("div")),
      ),
    );

    const observer = latestObserver();
    unmount();
    expect(observer.disconnected).toBe(true);
  });
});
