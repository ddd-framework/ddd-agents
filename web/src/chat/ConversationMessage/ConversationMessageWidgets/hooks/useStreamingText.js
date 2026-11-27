import { useEffect, useRef, useCallback } from "react";
export const useStreamingText = ({ eventSource, onTextChunk, onError, onDone }) => {
    const bufferRef = useRef("");
    const flushRAFRef = useRef(null);
    const onTextChunkRef = useRef(null);
    const onErrorRef = useRef(null);
    const onDoneRef = useRef(null);
    onTextChunkRef.current = onTextChunk;
    onErrorRef.current = onError;
    onDoneRef.current = onDone;
    const flushBuffer = useCallback(() => {
        flushRAFRef.current = null;
        if (!bufferRef.current)
            return;
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
    const handleMessage = useCallback((event) => {
        // OpenAI Chat Completions terminator
        if (event.data === "[DONE]") {
            finish();
            return;
        }
        try {
            const parsed = JSON.parse(event.data);
            // Chat Completions streaming delta
            const piece = parsed?.choices?.[0]?.delta?.content ??
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
        }
        catch (error) {
            console.error("Error parsing SSE message:", error, event.data);
            onErrorRef.current?.();
        }
    }, [flushBuffer, finish]);
    const handleError = useCallback(() => {
        onErrorRef.current?.();
        if (flushRAFRef.current !== null) {
            cancelAnimationFrame(flushRAFRef.current);
            flushRAFRef.current = null;
        }
        flushBuffer();
    }, [flushBuffer]);
    useEffect(() => {
        if (typeof window === "undefined" || !eventSource)
            return;
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
