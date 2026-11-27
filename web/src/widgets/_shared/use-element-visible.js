import { useEffect, useState } from "react";
/**
 * True while the element is in the viewport (IntersectionObserver) AND the document is visible — the widget polls only
 * when someone can see it. Environments without IntersectionObserver (jsdom) count as visible.
 */
export const useElementVisible = (ref) => {
    const [intersecting, setIntersecting] = useState(true);
    const [documentVisible, setDocumentVisible] = useState(typeof document === "undefined" || document.visibilityState !== "hidden");
    useEffect(() => {
        const element = ref.current;
        if (!element || typeof IntersectionObserver === "undefined")
            return undefined;
        const observer = new IntersectionObserver((entries) => {
            setIntersecting(entries.some((entry) => entry.isIntersecting));
        });
        observer.observe(element);
        return () => observer.disconnect();
    }, [ref]);
    useEffect(() => {
        if (typeof document === "undefined")
            return undefined;
        const onChange = () => setDocumentVisible(document.visibilityState !== "hidden");
        document.addEventListener("visibilitychange", onChange);
        return () => document.removeEventListener("visibilitychange", onChange);
    }, []);
    return intersecting && documentVisible;
};
