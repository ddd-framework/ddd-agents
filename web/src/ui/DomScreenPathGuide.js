import { buildTargetRoute } from "./client-tools";
/** How many times a target is guided before the agent just opens it (`shouldGuide` default). */
export const DEFAULT_GUIDES_PER_TARGET = 2;
/** The key prefix of the guide counts in localStorage. */
export const PATH_GUIDE_COUNT_KEY_PREFIX = 'ddd-agents:pathGuide:';
/** How long one step of the path stays marked, in ms. */
export const PATH_GUIDE_STEP_MS = 700;
/** The class a marked step of the path carries; the step also gets an outline in the theme's `--ring` (inline, restored after). */
export const PATH_GUIDE_CLASS = 'agents-path-guide-step';
/**
 * The default `shouldGuide`: the first `limit` opens of a target are guided, counted per key in localStorage; without
 * storage (private window, blocked) every open is guided once per page life.
 */
export const createGuideCounter = (limit = DEFAULT_GUIDES_PER_TARGET, readStorage = () => window.localStorage) => {
    const counts = new Map();
    return (targetKey) => {
        const storageKey = `${PATH_GUIDE_COUNT_KEY_PREFIX}${targetKey}`;
        let count = counts.get(targetKey) ?? 0;
        try {
            count = Math.max(count, Number.parseInt(readStorage()?.getItem(storageKey) ?? '0', 10) || 0);
        }
        catch {
            // no storage: the count of this page life
        }
        if (count >= limit)
            return false;
        counts.set(targetKey, count + 1);
        try {
            readStorage()?.setItem(storageKey, String(count + 1));
        }
        catch {
            // no storage
        }
        return true;
    };
};
const PATH_GUIDE_OUTLINE = '2px solid var(--ring, #2563eb)';
const markStep = (element, outlinesBefore) => {
    element.classList.add(PATH_GUIDE_CLASS);
    if (element instanceof HTMLElement) {
        outlinesBefore.set(element, element.style.outline);
        element.style.outline = PATH_GUIDE_OUTLINE;
    }
};
const unmarkStep = (element, outlinesBefore) => {
    element.classList.remove(PATH_GUIDE_CLASS);
    if (element instanceof HTMLElement && outlinesBefore.has(element)) {
        element.style.outline = outlinesBefore.get(element) ?? '';
        outlinesBefore.delete(element);
    }
};
const prefersReducedMotion = () => {
    try {
        return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
    catch {
        return false;
    }
};
/**
 * The DOM default of `ScreenPathGuide`: marks the elements of the way to the target one after the other
 * (`PATH_GUIDE_CLASS`), so the user learns where the screen lives. It runs beside the navigation and never delays the
 * answer. Skipped under reduced motion, when the user already is on the target, when no element of the way is on the
 * screen (plain navigation), and when `shouldGuide` says so. Esc or a click cancels it.
 */
export const createDomScreenPathGuide = (options) => {
    const shouldGuide = options.shouldGuide ?? createGuideCounter();
    const stepMs = options.stepMs ?? PATH_GUIDE_STEP_MS;
    let timers = [];
    let markedElements = [];
    const outlinesBefore = new Map();
    let removeListeners = null;
    const cancel = () => {
        timers.forEach(timer => clearTimeout(timer));
        timers = [];
        markedElements.forEach(element => unmarkStep(element, outlinesBefore));
        markedElements = [];
        removeListeners?.();
        removeListeners = null;
    };
    const guide = (target, request) => {
        cancel();
        if (prefersReducedMotion())
            return false;
        const targetRoute = buildTargetRoute(target, request.recordIds, request.tab);
        const currentRoute = options.currentRoute().split('?')[0];
        if (targetRoute !== null && currentRoute === targetRoute)
            return false;
        const pathElements = options.findPathElements(target, request).filter(element => element.isConnected);
        if (pathElements.length === 0)
            return false;
        if (!shouldGuide(target.key))
            return false;
        const onKeyDown = (event) => {
            if (event.key === 'Escape')
                cancel();
        };
        const onPointerDown = () => cancel();
        document.addEventListener('keydown', onKeyDown, true);
        document.addEventListener('pointerdown', onPointerDown, true);
        removeListeners = () => {
            document.removeEventListener('keydown', onKeyDown, true);
            document.removeEventListener('pointerdown', onPointerDown, true);
        };
        pathElements.forEach((element, stepIndex) => {
            timers.push(setTimeout(() => {
                markedElements.forEach(marked => unmarkStep(marked, outlinesBefore));
                markStep(element, outlinesBefore);
                markedElements = [element];
            }, stepIndex * stepMs));
        });
        timers.push(setTimeout(cancel, pathElements.length * stepMs));
        return true;
    };
    return { guide, cancel };
};
