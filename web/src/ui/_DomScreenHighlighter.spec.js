import assert from 'node:assert/strict';
import { afterEach, describe, it } from "vitest";
import { DomScreenHighlighter, planHighlightScroll } from "./DomScreenHighlighter";
import { ScreenReferenceRegistry } from "./DomScreenReader";
const VIEWPORT_HEIGHT = 800;
const rectOf = (top, left, bottom, right) => ({ top, left, bottom, right });
describe('planHighlightScroll', () => {
    it('scrolls without margins when nothing covers the page', () => {
        assert.deepEqual(planHighlightScroll(rectOf(700, 0, 740, 300), null, VIEWPORT_HEIGHT), {
            scrollMarginTop: 0,
            scrollMarginBottom: 0,
            coveredAfterScroll: false,
        });
    });
    it('adds the height of a bottom sheet as scroll margin below', () => {
        const plan = planHighlightScroll(rectOf(700, 0, 740, 300), rectOf(500, 0, 800, 400), VIEWPORT_HEIGHT);
        assert.equal(plan.scrollMarginBottom, 300);
        assert.equal(plan.scrollMarginTop, 0);
        assert.equal(plan.coveredAfterScroll, false);
    });
    it('adds the height of a top sheet as scroll margin above', () => {
        const plan = planHighlightScroll(rectOf(20, 0, 60, 300), rectOf(0, 0, 200, 400), VIEWPORT_HEIGHT);
        assert.equal(plan.scrollMarginTop, 200);
        assert.equal(plan.scrollMarginBottom, 0);
        assert.equal(plan.coveredAfterScroll, false);
    });
    it('leaves the scroll alone when the window sits beside the element', () => {
        const plan = planHighlightScroll(rectOf(700, 0, 740, 300), rectOf(500, 320, 800, 600), VIEWPORT_HEIGHT);
        assert.deepEqual(plan, { scrollMarginTop: 0, scrollMarginBottom: 0, coveredAfterScroll: false });
    });
    it('reports a side panel over the element as covered', () => {
        const plan = planHighlightScroll(rectOf(100, 200, 140, 500), rectOf(0, 300, 800, 700), VIEWPORT_HEIGHT);
        assert.equal(plan.coveredAfterScroll, true);
        assert.equal(plan.scrollMarginBottom, 0);
    });
    it('reports an element taller than the free band as covered', () => {
        const plan = planHighlightScroll(rectOf(0, 0, 500, 300), rectOf(400, 0, 800, 400), VIEWPORT_HEIGHT);
        assert.equal(plan.scrollMarginBottom, 400);
        assert.equal(plan.coveredAfterScroll, true);
    });
});
describe('DomScreenHighlighter with a covering agent window', () => {
    let highlighter = null;
    afterEach(() => {
        highlighter?.clear();
        highlighter = null;
        document.body.innerHTML = '';
    });
    const mountField = (top, bottom) => {
        const element = document.createElement('input');
        element.setAttribute('aria-label', 'End date');
        document.body.appendChild(element);
        element.getBoundingClientRect = () => ({ top, bottom, left: 0, right: 300, width: 300, height: bottom - top, x: 0, y: top, toJSON: () => ({}) });
        const scrollCalls = [];
        element.scrollIntoView = () => {
            scrollCalls.push({ marginBottom: element.style.scrollMarginBottom, marginTop: element.style.scrollMarginTop });
        };
        return { element, scrollCalls };
    };
    it('scrolls with the sheet height as margin, restores the margins and tells the application', () => {
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: VIEWPORT_HEIGHT });
        const { element, scrollCalls } = mountField(700, 740);
        const registry = new ScreenReferenceRegistry();
        registry.replace(new Map([['f.endDate', element]]));
        const events = [];
        highlighter = new DomScreenHighlighter({
            registry,
            announce: labels => `Marked: ${labels.join(', ')}`,
            getOccludingRect: () => rectOf(500, 0, 800, 400),
            onHighlight: event => events.push(event),
        });
        const result = highlighter.mark(['f.endDate'], null);
        assert.deepEqual(scrollCalls, [{ marginBottom: '300px', marginTop: '' }]);
        assert.equal(element.style.scrollMarginBottom, '');
        assert.equal(events.length, 1);
        assert.deepEqual(events[0]?.references, ['f.endDate']);
        assert.equal(events[0]?.coveredAfterScroll, false);
        assert.deepEqual(result.outOfView, []);
    });
    it('counts a mark under a side panel as out of view, and a failing hook breaks nothing', () => {
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: VIEWPORT_HEIGHT });
        const { element } = mountField(100, 140);
        const registry = new ScreenReferenceRegistry();
        registry.replace(new Map([['f.endDate', element]]));
        highlighter = new DomScreenHighlighter({
            registry,
            announce: () => 'Marked',
            getOccludingRect: () => rectOf(0, 100, 800, 500),
            onHighlight: () => {
                throw new Error('host hook failed');
            },
        });
        const result = highlighter.mark(['f.endDate'], 'here');
        assert.deepEqual(result.outOfView, ['f.endDate']);
        assert.ok(result.marked['f.endDate']);
    });
    it('keeps the old behaviour without the new options', () => {
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: VIEWPORT_HEIGHT });
        const { element, scrollCalls } = mountField(100, 140);
        const registry = new ScreenReferenceRegistry();
        registry.replace(new Map([['f.endDate', element]]));
        highlighter = new DomScreenHighlighter({ registry, announce: () => 'Marked' });
        highlighter.mark(['f.endDate'], null);
        assert.deepEqual(scrollCalls, [{ marginBottom: '', marginTop: '' }]);
    });
});
