import { useRef, useState, useCallback } from "react";
export const useTypewriter = (options = {}) => {
    const { speed = 200, commitIntervalMs = 50 } = options;
    const [displayedText, setDisplayedText] = useState("");
    const [isFinished, setIsFinished] = useState(false);
    const fullTextRef = useRef("");
    const typeRAFRef = useRef(null);
    const lastTimestampRef = useRef(null);
    const renderedLengthRef = useRef(0);
    const speedRef = useRef(speed);
    const commitIntervalRef = useRef(commitIntervalMs);
    const lastCommitTsRef = useRef(0);
    const startTypewriter = useCallback(() => {
        if (typeRAFRef.current !== null)
            return; // already running
        if (renderedLengthRef.current >= fullTextRef.current.length)
            return; // nothing to render
        const animate = (timestamp) => {
            if (lastTimestampRef.current === null) {
                lastTimestampRef.current = timestamp;
            }
            const elapsedSeconds = (timestamp - lastTimestampRef.current) / 1000;
            lastTimestampRef.current = timestamp;
            const charactersToAdd = Math.max(1, Math.floor(elapsedSeconds * speedRef.current));
            const nextLength = Math.min(fullTextRef.current.length, renderedLengthRef.current + charactersToAdd);
            renderedLengthRef.current = nextLength;
            // Only commit to React state at most every commitIntervalMs or when finished
            if (timestamp - lastCommitTsRef.current >= commitIntervalRef.current ||
                nextLength === fullTextRef.current.length) {
                const nextText = fullTextRef.current.slice(0, nextLength);
                setDisplayedText(nextText);
                lastCommitTsRef.current = timestamp;
            }
            if (nextLength < fullTextRef.current.length) {
                typeRAFRef.current = requestAnimationFrame(animate);
            }
            else {
                typeRAFRef.current = null;
                lastTimestampRef.current = null;
                setIsFinished(true);
            }
        };
        typeRAFRef.current = requestAnimationFrame(animate);
    }, []);
    const appendText = useCallback((text) => {
        if (!text)
            return;
        fullTextRef.current += text;
        setIsFinished(false);
        startTypewriter();
    }, [startTypewriter]);
    const stopTypewriter = useCallback(() => {
        if (typeRAFRef.current !== null) {
            cancelAnimationFrame(typeRAFRef.current);
            typeRAFRef.current = null;
            lastTimestampRef.current = null;
        }
    }, []);
    const finishTypewriter = useCallback(() => {
        stopTypewriter();
        setDisplayedText(fullTextRef.current);
        renderedLengthRef.current = fullTextRef.current.length;
        setIsFinished(true);
    }, [stopTypewriter]);
    return {
        displayedText,
        appendText,
        stopTypewriter,
        finishTypewriter,
        isFinished,
    };
};
