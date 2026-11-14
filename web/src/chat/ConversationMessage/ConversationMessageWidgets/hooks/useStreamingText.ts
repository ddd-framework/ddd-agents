import { useEffect, useRef, useCallback } from "react";

interface UseStreamingTextOptions {
  eventSource?: EventSource;
  onTextChunk?: (chunk: string) => void;
  onError?: () => void;
  onDone?: () => void;
}

export const useStreamingText = ({ eventSource, onTextChunk, onError, onDone }: UseStreamingTextOptions) => {
  const bufferRef = useRef<string>("");
  const flushRAFRef = useRef<number | null>(null);

  const onTextChunkRef = useRef<typeof onTextChunk>(null);
  const onErrorRef = useRef<typeof onError>(null);
  const onDoneRef = useRef<typeof onDone>(null);
  onTextChunkRef.current = onTextChunk;
  onErrorRef.current = onError;
  onDoneRef.current = onDone;

  const flushBuffer = useCallback(() => {
    flushRAFRef.current = null;
    if (!bufferRef.current) return;

    const chunk = bufferRef.current;
    bufferRef.current = "";
    onTextChunkRef.current?.(chunk);
  }, []);

  const finish = useCallback(() => {
    if (flushRAFRef.current !== null) {
      cancelAnimationFrame(flushRAFRef.current);
      flushRAFRef.current = null;
    }
    flushBuffer();
    onDoneRef.current?.();
  }, [flushBuffer]);

  const handleMessage = useCallback(
    (event: MessageEvent) => {
      // OpenAI Chat Completions terminator
      if (event.data === "[DONE]") {
        finish();
        return;
      }

      try {
        const parsed = JSON.parse(event.data);

        // Chat Completions streaming delta
        const piece =
          parsed?.choices?.[0]?.delta?.content ??
          // fallback: support simple formats too
          (typeof parsed === "string" ? parsed : (parsed?.text ?? ""));

        // Some servers send a final frame with finish_reason
        const finishReason = parsed?.choices?.[0]?.finish_reason;

        if (piece) {
          bufferRef.current += piece;
          if (flushRAFRef.current === null) {
            flushRAFRef.current = requestAnimationFrame(flushBuffer);
          }
        }

        if (finishReason === "stop") {
          finish();
        }
      } catch (error) {
        console.error("Error parsing SSE message:", error, event.data);
        onErrorRef.current?.();
      }
    },
    [flushBuffer, finish],
  );

  const handleError = useCallback(() => {
    onErrorRef.current?.();

    if (flushRAFRef.current !== null) {
      cancelAnimationFrame(flushRAFRef.current);
      flushRAFRef.current = null;
    }

    flushBuffer();
  }, [flushBuffer]);

  useEffect(() => {
    if (typeof window === "undefined" || !eventSource) return;

    eventSource.addEventListener("message", handleMessage);
    eventSource.addEventListener("error", handleError);

    return () => {
      eventSource.removeEventListener("message", handleMessage);
      eventSource.removeEventListener("error", handleError);

      if (flushRAFRef.current !== null) {
        cancelAnimationFrame(flushRAFRef.current);
        flushRAFRef.current = null;
      }

      flushBuffer();
    };
  }, [eventSource, handleMessage, handleError, flushBuffer]);
};
