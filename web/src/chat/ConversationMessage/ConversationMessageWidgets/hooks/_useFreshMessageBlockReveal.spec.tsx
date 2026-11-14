// @vitest-environment jsdom
import React, { RefObject } from "react";
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { renderHook } from "@testing-library/react";
import { useFreshMessageBlockReveal, VISIBILITY_DEADLINE_MS } from "./useFreshMessageBlockReveal";
import { WidgetWindowRootContext } from "../../../../renderer/widget-windowing";

type FakeEntry = { target: Element; isIntersecting: boolean };

class FakeIntersectionObserver {
  static instances: FakeIntersectionObserver[] = [];
  callback: (entries: FakeEntry[], observer: FakeIntersectionObserver) => void;
  options: IntersectionObserverInit;
  observed: Element[] = [];
  unobserved: Element[] = [];
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

  unobserve(element: Element) {
    this.unobserved.push(element);
  }

  disconnect() {
    this.disconnected = true;
  }

  fire(elements: Element[], isIntersecting = true) {
    this.fireEntries(elements.map((target) => ({ target, isIntersecting })));
  }

  fireEntries(entries: FakeEntry[]) {
    this.callback(entries, this);
  }
}

const buildContainer = (blockCount: number): HTMLDivElement => {
  const container = document.createElement("div");
  const blocksRoot = document.createElement("div");
  blocksRoot.setAttribute("data-markdown-blocks-root", "");
  for (let i = 0; i < blockCount; i++) blocksRoot.appendChild(document.createElement("p"));
  container.appendChild(blocksRoot);
  return container;
};

const blocksOf = (container: HTMLElement): HTMLElement[] =>
  Array.from(container.querySelector("[data-markdown-blocks-root]")!.children) as HTMLElement[];

const makeWrapper =
  (root: HTMLElement | null) =>
  ({ children }: { children: React.ReactNode }) => (
    <WidgetWindowRootContext.Provider value={root}>{children}</WidgetWindowRootContext.Provider>
  );

const renderReveal = (opts: {
  container: HTMLElement;
  root: HTMLElement | null;
  enabled?: boolean;
  messageId?: string | number | null;
}) => {
  const containerRef = { current: opts.container } as RefObject<HTMLDivElement | null>;
  return renderHook(
    () =>
      useFreshMessageBlockReveal({
        containerRef,
        enabled: opts.enabled ?? true,
        messageId: opts.messageId,
      }),
    { wrapper: makeWrapper(opts.root) },
  );
};

// Unique ids per test so the module-scoped once-only Set never leaks between tests.
let nextMessageId = 1;
const freshId = () => `msg-${nextMessageId++}`;

// The hook creates two observers: the one-shot initial-viewport one (full root, no margin) and the
// scroll-reveal one (explicit "0px" margin). Pick them by that distinguishing option, not by
// construction order.
const currentObservers = () => {
  const initialViewport = FakeIntersectionObserver.instances.find((o) => o.options.rootMargin === undefined)!;
  const scrollReveal = FakeIntersectionObserver.instances.find((o) => o.options.rootMargin !== undefined)!;
  return { initialViewport, scrollReveal };
};

describe("useFreshMessageBlockReveal", () => {
  beforeEach(() => {
    FakeIntersectionObserver.instances = [];
    vi.stubGlobal("IntersectionObserver", FakeIntersectionObserver);
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })) as unknown as typeof window.matchMedia;
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("does nothing when disabled — no blocks hidden, no observer", () => {
    const container = buildContainer(3);
    renderReveal({ container, root: document.createElement("div"), enabled: false, messageId: freshId() });

    expect(blocksOf(container).every((b) => b.dataset.blockReveal === undefined)).toBe(true);
    expect(FakeIntersectionObserver.instances).toHaveLength(0);
  });

  it("does nothing under prefers-reduced-motion: reduce", () => {
    (window.matchMedia as unknown as ReturnType<typeof vi.fn>).mockImplementation((query: string) => ({
      matches: query.includes("reduce"),
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    }));
    const container = buildContainer(3);
    renderReveal({ container, root: document.createElement("div"), messageId: freshId() });

    expect(blocksOf(container).every((b) => b.dataset.blockReveal === undefined)).toBe(true);
    expect(FakeIntersectionObserver.instances).toHaveLength(0);
  });

  it("does nothing when the scroll root is null (rendered outside the chat scroll container)", () => {
    const container = buildContainer(3);
    renderReveal({ container, root: null, messageId: freshId() });

    expect(blocksOf(container).every((b) => b.dataset.blockReveal === undefined)).toBe(true);
    expect(FakeIntersectionObserver.instances).toHaveLength(0);
  });

  it("hides every block and observes them against the FULL viewport; the explicit zero margin sits only on the scroll observer", () => {
    const container = buildContainer(3);
    const root = document.createElement("div");
    renderReveal({ container, root, messageId: freshId() });

    expect(blocksOf(container).every((b) => b.dataset.blockReveal === "hidden")).toBe(true);
    expect(FakeIntersectionObserver.instances).toHaveLength(2);
    const { initialViewport, scrollReveal } = currentObservers();
    expect(initialViewport.observed).toHaveLength(3);
    expect(initialViewport.options.root).toBe(root);
    expect(initialViewport.options.rootMargin).toBeUndefined();
    expect(scrollReveal.observed).toHaveLength(0);
    expect(scrollReveal.options.root).toBe(root);
    expect(scrollReveal.options.rootMargin).toBe("0px");
  });

  it("staggers the initial in-view batch (index*90 capped at 720) with the fast fade, then disconnects the initial observer", () => {
    const container = buildContainer(10);
    renderReveal({ container, root: document.createElement("div"), messageId: freshId() });
    const blocks = blocksOf(container);
    const { initialViewport, scrollReveal } = currentObservers();

    initialViewport.fire(blocks);

    const expectedDelays = ["0ms", "90ms", "180ms", "270ms", "360ms", "450ms", "540ms", "630ms", "720ms", "720ms"];
    blocks.forEach((block, index) => {
      expect(block.dataset.blockReveal).toBe("revealed");
      expect(block.style.animationDelay).toBe(expectedDelays[index]);
      // The initial batch overrides the fade duration inline; scroll-revealed blocks keep the CSS default.
      expect(block.style.animationDuration).toBe("550ms");
    });
    expect(initialViewport.disconnected).toBe(true);
    expect(scrollReveal.observed).toHaveLength(0);
  });

  it("hands below-the-fold blocks to the scroll observer; those reveal on scroll with no stagger delay", () => {
    const container = buildContainer(4);
    renderReveal({ container, root: document.createElement("div"), messageId: freshId() });
    const blocks = blocksOf(container);
    const { initialViewport, scrollReveal } = currentObservers();

    // Initial pass: blocks 0-1 in view (staggered), blocks 2-3 below the fold (handed off, still hidden).
    initialViewport.fireEntries([
      { target: blocks[0], isIntersecting: true },
      { target: blocks[1], isIntersecting: true },
      { target: blocks[2], isIntersecting: false },
      { target: blocks[3], isIntersecting: false },
    ]);

    expect(blocks[0].style.animationDelay).toBe("0ms");
    expect(blocks[1].style.animationDelay).toBe("90ms");
    expect(blocks[2].dataset.blockReveal).toBe("hidden");
    expect(scrollReveal.observed).toEqual([blocks[2], blocks[3]]);

    // Scrolled into view later: single fade, no delay, and no inline duration → keeps the calmer CSS default.
    scrollReveal.fire([blocks[2]]);

    expect(blocks[2].dataset.blockReveal).toBe("revealed");
    expect(blocks[2].style.animationDelay).toBe("");
    expect(blocks[2].style.animationDuration).toBe("");
    expect(blocks[3].dataset.blockReveal).toBe("hidden");
  });

  it("ignores non-intersecting scroll-observer entries (no reveal, still observed)", () => {
    const container = buildContainer(2);
    renderReveal({ container, root: document.createElement("div"), messageId: freshId() });
    const blocks = blocksOf(container);
    const { initialViewport, scrollReveal } = currentObservers();

    initialViewport.fireEntries([
      { target: blocks[0], isIntersecting: true },
      { target: blocks[1], isIntersecting: false },
    ]);
    // The scroll observer's own initial callback for a block still fully outside the scroll root.
    scrollReveal.fire([blocks[1]], false);

    expect(blocks[1].dataset.blockReveal).toBe("hidden");
    expect(scrollReveal.unobserved).toHaveLength(0);
  });

  it("makes every still-hidden block plainly visible on cleanup (no fade), keeps revealed ones", () => {
    const container = buildContainer(3);
    const { unmount } = renderReveal({ container, root: document.createElement("div"), messageId: freshId() });
    const blocks = blocksOf(container);
    const { initialViewport, scrollReveal } = currentObservers();
    initialViewport.fireEntries([
      { target: blocks[0], isIntersecting: true },
      { target: blocks[1], isIntersecting: false },
      { target: blocks[2], isIntersecting: false },
    ]);

    unmount();

    expect(initialViewport.disconnected).toBe(true);
    expect(scrollReveal.disconnected).toBe(true);
    expect(blocks[0].dataset.blockReveal).toBe("revealed");
    expect(blocks[1].dataset.blockReveal).toBeUndefined();
    expect(blocks[2].dataset.blockReveal).toBeUndefined();
  });

  it("finalizes by the visibility deadline: hidden blocks become visible without a fade, observers stop, late callbacks are ignored", () => {
    vi.useFakeTimers();
    try {
      const container = buildContainer(3);
      renderReveal({ container, root: document.createElement("div"), messageId: freshId() });
      const blocks = blocksOf(container);
      const { initialViewport, scrollReveal } = currentObservers();
      initialViewport.fireEntries([
        { target: blocks[0], isIntersecting: true },
        { target: blocks[1], isIntersecting: false },
        { target: blocks[2], isIntersecting: false },
      ]);
      expect(blocks[1].dataset.blockReveal).toBe("hidden");

      vi.advanceTimersByTime(VISIBILITY_DEADLINE_MS - 1);
      expect(blocks[1].dataset.blockReveal).toBe("hidden");

      vi.advanceTimersByTime(1);
      expect(blocks[0].dataset.blockReveal).toBe("revealed");
      expect(blocks[1].dataset.blockReveal).toBeUndefined();
      expect(blocks[2].dataset.blockReveal).toBeUndefined();
      expect(blocks[1].style.animationDelay).toBe("");
      expect(scrollReveal.disconnected).toBe(true);

      // A queued observer callback after the deadline must not re-hide or re-animate anything.
      scrollReveal.fire([blocks[2]]);
      expect(blocks[2].dataset.blockReveal).toBeUndefined();
    } finally {
      vi.useRealTimers();
    }
  });

  it("never replays for an already-revealed message id", () => {
    const id = freshId();
    const first = renderReveal({ container: buildContainer(3), root: document.createElement("div"), messageId: id });
    first.unmount();

    // Same id, a fresh container: the once-only guard must skip it entirely.
    const secondContainer = buildContainer(3);
    renderReveal({ container: secondContainer, root: document.createElement("div"), messageId: id });

    expect(blocksOf(secondContainer).every((b) => b.dataset.blockReveal === undefined)).toBe(true);
    // Only the first render created observers (the initial-viewport + scroll-reveal pair).
    expect(FakeIntersectionObserver.instances).toHaveLength(2);
  });

  it("targets only the direct-child blocks root — a nested Markdown root inside a widget block is left alone", () => {
    const container = buildContainer(2);
    const outerBlocksRoot = container.querySelector("[data-markdown-blocks-root]")!;
    // A widget (e.g. WebSearchAnswer) rendering its own Markdown produces a second, nested
    // [data-markdown-blocks-root] inside one of the outer root's blocks.
    const widgetBlock = document.createElement("div");
    const nestedBlocksRoot = document.createElement("div");
    nestedBlocksRoot.setAttribute("data-markdown-blocks-root", "");
    nestedBlocksRoot.appendChild(document.createElement("p"));
    widgetBlock.appendChild(nestedBlocksRoot);
    outerBlocksRoot.appendChild(widgetBlock);

    renderReveal({ container, root: document.createElement("div"), messageId: freshId() });

    // The outer root's direct children (2 paragraphs + the widget block) are hidden and observed …
    const outerBlocks = Array.from(outerBlocksRoot.children) as HTMLElement[];
    expect(outerBlocks.every((b) => b.dataset.blockReveal === "hidden")).toBe(true);
    expect(currentObservers().initialViewport.observed).toEqual(outerBlocks);
    // … while the nested root and its children are untouched.
    expect((nestedBlocksRoot as HTMLElement).dataset.blockReveal).toBeUndefined();
    expect((nestedBlocksRoot.firstElementChild as HTMLElement).dataset.blockReveal).toBeUndefined();
  });

  it("does nothing when the blocks root is not the container's direct child (contract with renderedRef)", () => {
    const container = document.createElement("div");
    const extraWrapper = document.createElement("div");
    const blocksRoot = document.createElement("div");
    blocksRoot.setAttribute("data-markdown-blocks-root", "");
    blocksRoot.appendChild(document.createElement("p"));
    extraWrapper.appendChild(blocksRoot);
    container.appendChild(extraWrapper);

    renderReveal({ container, root: document.createElement("div"), messageId: freshId() });

    expect((blocksRoot.firstElementChild as HTMLElement).dataset.blockReveal).toBeUndefined();
    expect(FakeIntersectionObserver.instances).toHaveLength(0);
  });

  it("still animates when messageId is null, but records nothing (would replay)", () => {
    const container = buildContainer(2);
    renderReveal({ container, root: document.createElement("div"), messageId: null });

    expect(blocksOf(container).every((b) => b.dataset.blockReveal === "hidden")).toBe(true);
    expect(FakeIntersectionObserver.instances).toHaveLength(2);
  });
});
