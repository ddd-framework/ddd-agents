import { useCallback, useState } from "react";

export type ImageLoadState = "loading" | "loaded" | "failed";

interface ImageLoadRecord {
  src: string | undefined;
  state: ImageLoadState;
}

/**
 * The load state of one `<img>`, bound to its `src`: a new source reads as `loading` without an effect (an effect that
 * resets after the commit would undo the `onLoad` of a cached picture that already fired), and the ref marks a picture
 * that is already complete when it mounts — a cached or lazily loaded one fires no `onLoad` React could still see.
 */
export const useImageLoadState = (src: string | undefined) => {
  const [loadRecord, setLoadRecord] = useState<ImageLoadRecord>({ src, state: "loading" });
  const loadState: ImageLoadState = loadRecord.src === src ? loadRecord.state : "loading";
  const markLoaded = useCallback(() => setLoadRecord({ src, state: "loaded" }), [src]);
  const markFailed = useCallback(() => setLoadRecord({ src, state: "failed" }), [src]);
  const imageRef = useCallback(
    (imageElement: HTMLImageElement | null) => {
      if (imageElement?.complete && imageElement.naturalWidth > 0) setLoadRecord({ src, state: "loaded" });
    },
    [src],
  );
  return { loadState, imageRef, markLoaded, markFailed };
};
