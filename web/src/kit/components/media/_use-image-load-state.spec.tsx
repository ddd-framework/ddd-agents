import { act, render, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { useImageLoadState } from "./use-image-load-state";

const ImageWithState = ({ src }: { src: string }) => {
  const { loadState, imageRef, markLoaded, markFailed } = useImageLoadState(src);
  return (
    <>
      <img ref={imageRef} src={src} alt="" onLoad={markLoaded} onError={markFailed} />
      <span data-testid="state">{loadState}</span>
    </>
  );
};

describe("useImageLoadState", () => {
  it("reads a picture that is already complete when it mounts as loaded (a cached one fires no onLoad React sees)", () => {
    Object.defineProperty(HTMLImageElement.prototype, "complete", { configurable: true, get: () => true });
    Object.defineProperty(HTMLImageElement.prototype, "naturalWidth", { configurable: true, get: () => 960 });
    try {
      const { getByTestId } = render(<ImageWithState src="https://example.org/cached.jpg" />);
      expect(getByTestId("state").textContent).toBe("loaded");
    } finally {
      delete (HTMLImageElement.prototype as { complete?: boolean }).complete;
      delete (HTMLImageElement.prototype as { naturalWidth?: number }).naturalWidth;
    }
  });

  it("keeps a loaded picture loaded and reads a new source as loading without an effect", () => {
    const { result, rerender } = renderHook(({ src }) => useImageLoadState(src), { initialProps: { src: "a.jpg" } });
    expect(result.current.loadState).toBe("loading");
    act(() => result.current.markLoaded());
    expect(result.current.loadState).toBe("loaded");
    rerender({ src: "b.jpg" });
    expect(result.current.loadState).toBe("loading");
    act(() => result.current.markFailed());
    expect(result.current.loadState).toBe("failed");
  });
});
