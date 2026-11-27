import { useEffect, useState } from "react";
/** The value as it was `delayMs` after its last change. */
export const useDebouncedValue = (value, delayMs) => {
    const [debouncedValue, setDebouncedValue] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebouncedValue(value), delayMs);
        return () => clearTimeout(timer);
    }, [value, delayMs]);
    return debouncedValue;
};
