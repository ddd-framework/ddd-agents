import { CLIENT_TOOL_OUTCOMES, } from "./client-tools";
import { AGENT_ACTIONS, AGENT_ATTRIBUTES, isOpenableByAgent, isOpenTab, isPressableByAgent, labelOfElement, sortDirectionOf, } from "./DomScreenReader";
import { capValue } from "./screen-markdown";
/** How long the mark stays on the element before the action runs, so the user sees what is about to happen. */
export const ACTION_MARK_BEAT_MS = 700;
/** A sort control cycles through at most this many states (ascending, descending, unsorted). */
const MAX_SORT_CLICKS = 3;
/** How long one click on a sort control may take to show in the column's `aria-sort`. */
const SORT_CHANGE_WAIT_MS = 1500;
const OPEN_DIALOG_SELECTOR = '[role="dialog"], [role="alertdialog"]';
/** The action kinds of a request and the declaration each needs on its element. */
const DECLARATION_OF_ACTION = {
    sort: AGENT_ACTIONS.sort,
    openRow: AGENT_ACTIONS.open,
    switchTab: AGENT_ACTIONS.tab,
    press: AGENT_ACTIONS.press,
};
const wait = (milliseconds) => new Promise(resolve => setTimeout(resolve, milliseconds));
const nextFrame = () => new Promise(resolve => {
    if (typeof requestAnimationFrame === 'function')
        requestAnimationFrame(() => resolve());
    else
        setTimeout(resolve, 16);
});
const refusal = (outcome, text = '') => ({ outcome, text, details: {} });
const isDisabled = (element) => element.hasAttribute('disabled') || element.getAttribute('aria-disabled') === 'true';
/**
 * A click as a pointer makes it: components that act on `pointerdown` or `mousedown` (a tab list selects on mouse
 * down) and those that act on `click` (a link, a button, a row) all see it. No coordinates, no keyboard, no focus move.
 */
export const activateElement = (element) => {
    const init = { bubbles: true, cancelable: true, composed: true, button: 0 };
    const PointerEventClass = typeof PointerEvent === 'function' ? PointerEvent : MouseEvent;
    element.dispatchEvent(new PointerEventClass('pointerdown', init));
    element.dispatchEvent(new MouseEvent('mousedown', init));
    element.dispatchEvent(new PointerEventClass('pointerup', init));
    element.dispatchEvent(new MouseEvent('mouseup', init));
    if (typeof element.click === 'function')
        element.click();
    else
        element.dispatchEvent(new MouseEvent('click', init));
};
/**
 * Carries out the typed actions of `actOnScreen` on the DOM. It acts only on an element that DECLARES the action
 * (`data-agent-action`; a tab list's `role="tab"` counts as a declared tab) and that a reference of the newest read
 * names; it never takes a selector, never types, never submits a form. A button is pressed only when it is declared
 * with the effect `view`: a button without a declaration, or with any other effect, is refused, so a save, a delete
 * or a send never runs through here. Before it acts it marks the element (the highlighter) and waits a beat, so the
 * user sees what happens.
 *
 * What blocks: unsaved changes block every action that leaves the screen (a row, a route tab); while a dialog is open
 * only an element inside it is acted on, and never one that leaves the screen.
 */
export class DomScreenActor {
    options;
    constructor(options) {
        this.options = options;
    }
    async act(request) {
        const element = this.options.registry.find(request.reference) ?? this.options.registry.findDeclared(request.reference);
        if (!element)
            return refusal(CLIENT_TOOL_OUTCOMES.notOnScreen);
        const declarationRefusal = this.checkDeclaration(request, element);
        if (declarationRefusal)
            return declarationRefusal;
        if (isDisabled(element))
            return refusal(CLIENT_TOOL_OUTCOMES.disabled, this.name(request.reference, element));
        const blockerRefusal = this.checkBlockers(request, element);
        if (blockerRefusal)
            return blockerRefusal;
        const label = labelOfElement(element);
        const routeBefore = this.options.currentLocation().route;
        if (request.action === 'switchTab' && isOpenTab(element)) {
            return this.done(`Tab ${this.name(request.reference, element)} is open already.`, routeBefore);
        }
        if (request.action === 'sort' && this.sortOf(element) === request.direction) {
            return this.done(`${this.name(request.reference, element)} is sorted ${this.describeDirection(request.direction)} already.`, routeBefore);
        }
        await this.markBeforeActing(request, element, label);
        if (request.action === 'sort') {
            const reached = await this.sort(request.reference, element, request.direction ?? 'asc');
            if (!reached)
                return refusal(CLIENT_TOOL_OUTCOMES.noEffect, this.name(request.reference, element));
            await this.settle();
            return this.done(`Sorted by ${this.name(request.reference, element)} ${this.describeDirection(request.direction)}.`, routeBefore);
        }
        activateElement(element);
        await this.settle();
        const sentence = {
            openRow: `Opened ${this.nameOf(request.reference, label)}.`,
            switchTab: `Switched to tab ${this.nameOf(request.reference, label)}.`,
            press: `Pressed ${this.nameOf(request.reference, label)}.`,
        }[request.action];
        return this.done(sentence, routeBefore);
    }
    /** The element must declare the action of the request; a button must declare the effect `view`. */
    checkDeclaration(request, element) {
        const declared = element.getAttribute(AGENT_ATTRIBUTES.action);
        if (request.action === 'switchTab') {
            return declared === AGENT_ACTIONS.tab || element.getAttribute('role') === 'tab' ? null : this.notDeclared(request, element);
        }
        if (request.action === 'openRow')
            return isOpenableByAgent(element) ? null : this.notDeclared(request, element);
        if (request.action === 'sort') {
            if (declared !== AGENT_ACTIONS.sort)
                return this.notDeclared(request, element);
            return request.direction === null ? refusal(CLIENT_TOOL_OUTCOMES.missingParameter) : null;
        }
        if (declared !== DECLARATION_OF_ACTION.press)
            return this.notDeclared(request, element);
        return isPressableByAgent(element) ? null : refusal(CLIENT_TOOL_OUTCOMES.writeAction, this.name(request.reference, element));
    }
    notDeclared(request, element) {
        return refusal(CLIENT_TOOL_OUTCOMES.notDeclared, this.name(request.reference, element));
    }
    /**
     * Unsaved changes refuse what leaves the screen; an open dialog refuses every element outside it and what leaves
     * the screen inside it.
     */
    checkBlockers(request, element) {
        const blocker = this.options.describeBlockers();
        if (!blocker)
            return null;
        const leavesScreen = request.action === 'openRow' || (request.action === 'switchTab' && element.matches('a[href]'));
        if (blocker.outcome === CLIENT_TOOL_OUTCOMES.dialogOpen) {
            return element.closest(OPEN_DIALOG_SELECTOR) === null || leavesScreen ? { outcome: blocker.outcome, text: blocker.reason, details: {} } : null;
        }
        return leavesScreen ? { outcome: blocker.outcome, text: blocker.reason, details: {} } : null;
    }
    /** Marks the element with the application's note and waits the beat; a failing highlighter never stops the action. */
    async markBeforeActing(request, element, label) {
        const highlighter = this.options.highlighter;
        if (!highlighter)
            return;
        try {
            this.options.registry.remember(request.reference, element);
            highlighter.mark([request.reference], this.options.describeAction?.(request.action, label) ?? null);
        }
        catch {
            return;
        }
        await wait(this.options.markBeatMs ?? ACTION_MARK_BEAT_MS);
    }
    /** Clicks the sort control until its column shows the wanted direction; false when it never does. */
    async sort(reference, control, direction) {
        let current = control;
        for (let click = 0; click < MAX_SORT_CLICKS; click += 1) {
            if (this.sortOf(current) === direction)
                return true;
            const before = this.sortOf(current);
            activateElement(current);
            const startedAt = Date.now();
            do {
                await nextFrame();
                // a re-rendered header is a new element: the reference is looked up again by its key
                current = current.isConnected ? current : (this.options.registry.findDeclared(reference) ?? current);
            } while (this.sortOf(current) === before && Date.now() - startedAt < SORT_CHANGE_WAIT_MS);
            if (this.sortOf(current) === before)
                return false;
        }
        return this.sortOf(current) === direction;
    }
    /** The sort a control shows, in the words of the request (`none` when its column is not sorted). */
    sortOf(control) {
        return sortDirectionOf(control) ?? 'none';
    }
    describeDirection(direction) {
        return direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'in the list\'s own order';
    }
    async settle() {
        if (this.options.settle) {
            await this.options.settle();
            return;
        }
        await nextFrame();
        await nextFrame();
    }
    done(text, routeBefore) {
        const location = this.options.currentLocation();
        return {
            outcome: CLIENT_TOOL_OUTCOMES.ok,
            text,
            details: { route: location.route, title: location.title, routeChanged: location.route !== routeBefore },
        };
    }
    name(reference, element) {
        return this.nameOf(reference, labelOfElement(element));
    }
    nameOf(reference, label) {
        return label === '' ? reference : `${reference} "${capValue(label, 80)}"`;
    }
}
