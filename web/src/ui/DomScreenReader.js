import { capValue, fieldReferenceNameOf, formatActions, formatButtons, formatFieldLine, formatTable, joinScreenParts, MAX_SECTION_TEXT_CHARS, MAX_TABLE_ROWS, oneLine, ReferenceNames, ROWS_OPEN_LINE, slugOf, } from "./screen-markdown";
/** The attributes an application (and `@mgamadeus/ddd-admin-ui`) sets for the agent. */
export const AGENT_ATTRIBUTES = {
    /** A stable handle: `a.<value>` */
    anchor: 'data-agent-anchor',
    /** The value is never read (a key, a token) */
    secret: 'data-agent-secret',
    /** The element and everything in it is never read (the agent window itself) */
    skip: 'data-agent-skip',
    /** The key of a table row (@mgamadeus/ddd-admin-ui) */
    rowKey: 'data-row-key',
    /** The key of a main menu entry (@mgamadeus/ddd-admin-ui): `nav.<value>` */
    navKey: 'data-nav-key',
    /** The key of a main menu group (@mgamadeus/ddd-admin-ui): `nav.<value>`; its entries sit inside it */
    navGroup: 'data-nav-group',
    /**
     * What the agent may do with the element (`actOnScreen`): `sort` (a column's sort control), `open` (a row or card
     * that opens its record), `tab` (a tab), `press` (a button). Set by @mgamadeus/ddd-admin-ui on its tables, cards
     * and tabs, by the application on its own buttons. An element without it is never acted on.
     */
    action: 'data-agent-action',
    /** The stable key of a declared element: the sort key (`col.<value>`), the tab id, the button (`act.<value>`) */
    actionKey: 'data-agent-action-key',
    /** Only on `press`: `view` (changes what the screen shows, nothing stored). Anything else counts as a write and is refused. */
    actionEffect: 'data-agent-action-effect',
};
/** The values of `data-agent-action`. */
export const AGENT_ACTIONS = { sort: 'sort', open: 'open', tab: 'tab', press: 'press' };
/** The one effect of a declared button the agent may press. */
export const AGENT_ACTION_EFFECT_VIEW = 'view';
const DECLARED_TAB_SELECTOR = `[${AGENT_ATTRIBUTES.action}="${AGENT_ACTIONS.tab}"]`;
const DECLARED_SORT_SELECTOR = `[${AGENT_ATTRIBUTES.action}="${AGENT_ACTIONS.sort}"]`;
const DECLARED_PRESS_SELECTOR = `[${AGENT_ATTRIBUTES.action}="${AGENT_ACTIONS.press}"]`;
/** Elements never read. */
const SKIPPED_SELECTOR = `[${AGENT_ATTRIBUTES.skip}], script, style, noscript, template, svg, [aria-hidden="true"], [hidden]`;
/** Fields whose value is never read. */
const SECRET_FIELD_SELECTOR = `[${AGENT_ATTRIBUTES.secret}] , input[type="password"], input[autocomplete="one-time-code"]`;
const FIELD_SELECTOR = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]), textarea, select, [role="combobox"], [role="switch"], [role="checkbox"], [role="radiogroup"]';
const BUTTON_SELECTOR = 'button, [role="button"], a[href]';
const TABLE_SELECTOR = 'table, [role="grid"], [role="table"]';
/**
 * The selection checkbox of a row: a field of its own it is not. On a list of 200 rows the checkboxes gave 201 field
 * lines ("Select ticket …") before any table, and the read was cut before the table; the row says `(selected)` instead.
 */
const ROW_CHECKBOX_SELECTOR = [TABLE_SELECTOR, `[${AGENT_ATTRIBUTES.rowKey}]`]
    .flatMap(container => container.split(', ').flatMap(part => [`${part} input[type="checkbox"]`, `${part} [role="checkbox"]`]))
    .join(', ');
/**
 * Text the read writes already (fields and their labels, tables, cards, buttons and links, headings, tabs, dialogs,
 * messages) or never reads (the agent window, secrets, the menu, hidden parts): the `Text:` line leaves it out.
 */
const TEXT_SKIPPED_SELECTOR = [
    SKIPPED_SELECTOR,
    `[${AGENT_ATTRIBUTES.secret}]`,
    'nav',
    'input, textarea, select, label, [role="combobox"], [role="switch"], [role="checkbox"], [role="radiogroup"]',
    '[contenteditable="true"]',
    `${TABLE_SELECTOR}, [${AGENT_ATTRIBUTES.rowKey}]`,
    'button, [role="button"], a[href], [role="tablist"], [role="tab"], [role="menu"], [role="listbox"]',
    'h1, h2, h3, h4, [role="heading"]',
    '[role="dialog"], [role="alertdialog"], [role="alert"], [role="status"]',
].join(', ');
/** The heading of the main menu part of a read. */
export const MAIN_MENU_HEADING = '## Main menu (highlightOnScreen marks an entry by its nav. reference)';
/** The most tabs of the page a read names. */
const MAX_PAGE_TABS = 16;
/** Column headers that name the id column (for a host table whose rows carry no `data-row-key`). */
const ID_COLUMN_LABELS = new Set(['id', '#', 'nr', 'no']);
/** The references of the newest read: reference → element. The highlighter marks by them. */
export class ScreenReferenceRegistry {
    elements = new Map();
    replace(elements) {
        this.elements = elements;
    }
    find(reference) {
        const element = this.elements.get(reference);
        return element?.isConnected ? element : null;
    }
    /** Adds one reference to the newest read (an element found without a read, so the highlighter can mark it). */
    remember(reference, element) {
        this.elements.set(reference, element);
    }
    /**
     * A declared element found on the page even without a read: `col.<key>` (sort), `t.<key>` (tab), `act.<key>`
     * (press) by its action key, `row.<key>` by its row key. Null for every other reference.
     */
    findDeclared(reference) {
        const separator = reference.indexOf('.');
        if (separator <= 0)
            return null;
        const key = reference.slice(separator + 1);
        const selector = {
            col: `[${AGENT_ATTRIBUTES.action}="${AGENT_ACTIONS.sort}"][${AGENT_ATTRIBUTES.actionKey}]`,
            t: `[${AGENT_ATTRIBUTES.action}="${AGENT_ACTIONS.tab}"][${AGENT_ATTRIBUTES.actionKey}]`,
            act: `[${AGENT_ATTRIBUTES.action}="${AGENT_ACTIONS.press}"][${AGENT_ATTRIBUTES.actionKey}]`,
            row: `[${AGENT_ATTRIBUTES.rowKey}]`,
        }[reference.slice(0, separator)];
        if (!selector)
            return null;
        const keyAttribute = reference.startsWith('row.') ? AGENT_ATTRIBUTES.rowKey : AGENT_ATTRIBUTES.actionKey;
        for (const element of Array.from(document.querySelectorAll(selector))) {
            if (slugOf(element.getAttribute(keyAttribute) ?? '') === slugOf(key) && !element.closest(`[${AGENT_ATTRIBUTES.skip}]`))
                return element;
        }
        return null;
    }
    /** An anchor is found on the page even without a read. */
    findAnchor(reference) {
        if (!reference.startsWith('a.'))
            return null;
        const anchorName = reference.slice(2);
        for (const element of Array.from(document.querySelectorAll(`[${AGENT_ATTRIBUTES.anchor}]`))) {
            if (element.getAttribute(AGENT_ATTRIBUTES.anchor) === anchorName && !element.closest(`[${AGENT_ATTRIBUTES.skip}]`))
                return element;
        }
        return null;
    }
    labelOf(reference) {
        const element = this.find(reference) ?? this.findAnchor(reference);
        return element ? labelOfElement(element) : reference;
    }
}
const isRendered = (element) => {
    if (element.closest(SKIPPED_SELECTOR))
        return false;
    const htmlElement = element;
    if (typeof htmlElement.getClientRects === 'function' && htmlElement.getClientRects().length === 0)
        return false;
    return true;
};
const isChecked = (checkbox) => checkbox instanceof HTMLInputElement ? checkbox.checked : checkbox.getAttribute('aria-checked') === 'true';
/** Whether a row or card declares that it opens its record (`data-agent-action="open"`). */
export const isOpenableByAgent = (row) => row.getAttribute(AGENT_ATTRIBUTES.action) === AGENT_ACTIONS.open;
/** Whether a button is declared for the agent with the effect `view`: the only buttons `actOnScreen` presses. */
export const isPressableByAgent = (button) => button.getAttribute(AGENT_ATTRIBUTES.action) === AGENT_ACTIONS.press &&
    button.getAttribute(AGENT_ATTRIBUTES.actionEffect) === AGENT_ACTION_EFFECT_VIEW;
/** The sort a declared sort control shows: the `aria-sort` of its column header. */
export const sortDirectionOf = (control) => {
    const ariaSort = control.closest('th, [role="columnheader"]')?.getAttribute('aria-sort');
    return ariaSort === 'ascending' ? 'asc' : ariaSort === 'descending' ? 'desc' : null;
};
/** Whether a tab is the open one: `aria-selected` (a tab list), `aria-current` (a route tab), `aria-pressed` (a button tab). */
export const isOpenTab = (tab) => tab.getAttribute('aria-selected') === 'true' || tab.getAttribute('aria-current') !== null || tab.getAttribute('aria-pressed') === 'true';
const textOf = (element) => {
    if (!element)
        return '';
    const htmlElement = element;
    // biome-ignore lint/performance/useDomNodeTextContent: the rendered text (no hidden parts) is what the user sees
    return oneLine(htmlElement.innerText ?? element.textContent ?? '');
};
/** The accessible label of an element: aria-label, labelledby, a `<label for>`, the wrapping label, its text. */
export const labelOfElement = (element) => {
    const ariaLabel = element.getAttribute('aria-label');
    if (ariaLabel)
        return oneLine(ariaLabel);
    const labelledBy = element.getAttribute('aria-labelledby');
    if (labelledBy) {
        const text = labelledBy
            .split(/\s+/)
            .map(id => textOf(document.getElementById(id)))
            .join(' ');
        if (text.trim() !== '')
            return oneLine(text);
    }
    const id = element.getAttribute('id');
    if (id) {
        const label = document.querySelector(`label[for="${CSS.escape(id)}"]`);
        if (label)
            return textOf(label);
    }
    const wrappingLabel = element.closest('label');
    if (wrappingLabel)
        return textOf(wrappingLabel);
    const placeholder = element.getAttribute('placeholder');
    if (placeholder)
        return oneLine(placeholder);
    return textOf(element).slice(0, 80);
};
/** The name of a tab: its accessible label, else its text. */
const labelOfTab = (tab) => tab.getAttribute('aria-label') ?? textOf(tab);
/**
 * The key of a table row: `data-row-key` (the package's list rows and cards, 0.3.10); a table of the host without it
 * falls back to the visible id cell, then to the row's position.
 */
export const rowKeyOf = (row, idColumnIndex, rowIndex) => {
    const rowKey = row.getAttribute(AGENT_ATTRIBUTES.rowKey);
    if (rowKey)
        return slugOf(rowKey);
    if (idColumnIndex !== null) {
        const idText = textOf(row.children.item(idColumnIndex)).replace(/^#/, '');
        if (/^[A-Za-z0-9-]{1,40}$/.test(idText))
            return idText;
    }
    return `n${rowIndex + 1}`;
};
const valueOfField = (field) => {
    if (field instanceof HTMLInputElement) {
        if (field.type === 'checkbox' || field.type === 'radio')
            return { value: field.checked ? 'on' : 'off', kind: field.type };
        return { value: field.value, kind: field.type || 'text' };
    }
    if (field instanceof HTMLTextAreaElement)
        return { value: field.value, kind: 'text area' };
    if (field instanceof HTMLSelectElement)
        return { value: textOf(field.selectedOptions.item(0)), kind: 'select' };
    const role = field.getAttribute('role') ?? '';
    if (role === 'switch' || role === 'checkbox')
        return { value: field.getAttribute('aria-checked') === 'true' ? 'on' : 'off', kind: role };
    if (role === 'radiogroup') {
        const checked = field.querySelector('[aria-checked="true"], [data-state="checked"]');
        return { value: checked ? labelOfElement(checked) : '', kind: 'choice' };
    }
    return { value: textOf(field), kind: role || 'field' };
};
const invalidMessageOf = (field) => {
    if (field.getAttribute('aria-invalid') !== 'true')
        return null;
    const describedBy = field.getAttribute('aria-describedby') ?? field.getAttribute('aria-errormessage');
    const message = (describedBy ?? '')
        .split(/\s+/)
        .map(id => textOf(document.getElementById(id)))
        .join(' ')
        .trim();
    return message !== '' ? message : 'invalid';
};
/**
 * Reads the open screen from the DOM: title and route, the agent window and the browser size, tabs, open dialogs,
 * messages, then per section its plain text (`Text: …`), tables, cards, fields and buttons, and the main menu. Never
 * the agent window (`data-agent-skip`), never a secret's value.
 */
export class DomScreenReader {
    options;
    /** The containers whose text this read wrote already (a section before the page around it). */
    textReadContainers = new Set();
    constructor(options) {
        this.options = options;
    }
    read({ focus, maxChars }) {
        const location = this.options.currentLocation();
        if (focus) {
            const focused = this.options.registry.find(focus) ?? this.options.registry.findAnchor(focus);
            if (!focused) {
                return { markdown: `${focus} is not on the screen any more. Read the screen again without focus.`, route: location.route, title: location.title, references: {} };
            }
            const value = focused.matches(SECRET_FIELD_SELECTOR) || focused.closest(`[${AGENT_ATTRIBUTES.secret}]`) ? '(hidden)' : this.readWhole(focused);
            return { markdown: joinScreenParts([`${focus} "${labelOfElement(focused)}" in full:`, value], maxChars), route: location.route, title: location.title, references: {} };
        }
        const elements = new Map();
        const references = {};
        const names = new ReferenceNames();
        const register = (reference, element) => {
            const unique = names.take(reference);
            elements.set(unique, element);
            references[unique] = labelOfElement(element);
            return unique;
        };
        this.textReadContainers = new Set();
        const parts = [`# ${capValue(location.title, 120)}`, `Route: ${location.route}`];
        const unsaved = this.options.describeUnsavedChanges();
        parts.push(`Unsaved changes: ${unsaved}`);
        parts.push(this.describeWindow());
        const filters = this.options.describeFilters?.() ?? null;
        if (filters)
            parts.push(`Filters (actOnScreen, action setFilters): ${oneLine(filters)}`);
        const root = this.options.findRoot();
        const dialogs = Array.from(document.querySelectorAll('[role="dialog"], [role="alertdialog"]')).filter(isRendered);
        for (const dialog of dialogs) {
            parts.push(`## Dialog "${capValue(labelOfElement(dialog), 80)}" (open)`);
            parts.push(...this.readContainer(dialog, register));
        }
        if (root) {
            // the page's own tabs only: a tab list inside a form is a field's (the language tabs of a translatable text,
            // 14 per field on a long form); its open tab shows in the field's value
            // a declared tab (`data-agent-action="tab"`) is listed with its key, also while it is not the open one
            const tabs = Array.from(root.querySelectorAll(`[role="tab"], ${DECLARED_TAB_SELECTOR}, nav[aria-label] a[aria-current]`))
                .filter(tab => isRendered(tab) && tab.closest('form, fieldset') === null)
                .slice(0, MAX_PAGE_TABS);
            if (tabs.length > 0) {
                const tabNames = tabs.map(tab => {
                    const reference = register(`t.${slugOf(tab.getAttribute(AGENT_ATTRIBUTES.actionKey) ?? textOf(tab))}`, tab);
                    return `${reference} "${capValue(labelOfTab(tab), 40)}"${isOpenTab(tab) ? ' (open)' : ''}`;
                });
                const switchable = tabs.some(tab => tab.matches(`[role="tab"], ${DECLARED_TAB_SELECTOR}`));
                parts.push(`Tabs${switchable ? ' (actOnScreen, action switchTab)' : ''}: ${tabNames.join(' · ')}`);
            }
            const messages = Array.from(root.querySelectorAll('[role="alert"], [role="status"]')).filter(isRendered);
            for (const message of messages) {
                const text = textOf(message);
                if (text !== '')
                    parts.push(`Message: ${capValue(text, 200)}`);
            }
            parts.push(...this.readContainer(root, register));
        }
        else {
            parts.push('(no page content)');
        }
        const anchors = Array.from(document.querySelectorAll(`[${AGENT_ATTRIBUTES.anchor}]`)).filter(isRendered);
        if (anchors.length > 0) {
            parts.push(`Anchors: ${anchors.map(anchor => register(`a.${anchor.getAttribute(AGENT_ATTRIBUTES.anchor)}`, anchor)).join(' · ')}`);
        }
        // the menu goes last and is never cut away by a long page: the page gets the characters the menu leaves
        const menu = this.readNavigation(register).join('\n');
        this.options.registry.replace(elements);
        const page = joinScreenParts(parts, Math.max(maxChars - (menu === '' ? 0 : menu.length + 1), Math.floor(maxChars / 2)));
        return { markdown: menu === '' ? page : `${page}\n${menu}`, route: location.route, title: location.title, references };
    }
    /** `Agent window: … · browser 1440×900 px`, or `Browser window: 1440×900 px` without a description of the window. */
    describeWindow() {
        const size = typeof window === 'undefined' ? 'unknown' : `${Math.round(window.innerWidth)}×${Math.round(window.innerHeight)} px`;
        const agentWindow = this.options.describeAgentWindow?.() ?? null;
        return agentWindow ? `Agent window: ${oneLine(agentWindow)} · browser ${size}` : `Browser window: ${size}`;
    }
    /**
     * The main menu: its entries (`data-nav-key`) and groups (`data-nav-group`) on the screen, as `nav.<key>`
     * references (the menu of a compact layout shows icons only: an entry's name is its aria-label). A closed group
     * shows only itself.
     */
    readNavigation(register) {
        const navigation = this.options.findNavigation?.() ?? null;
        if (!navigation)
            return [];
        const groups = new Map();
        const readKeys = new Set();
        for (const mark of Array.from(navigation.querySelectorAll(`[${AGENT_ATTRIBUTES.navKey}], [${AGENT_ATTRIBUTES.navGroup}]`))) {
            const groupKey = mark.getAttribute(AGENT_ATTRIBUTES.navGroup);
            const key = mark.getAttribute(AGENT_ATTRIBUTES.navKey) ?? groupKey;
            // a group of a list is the whole `<li>` with its entries: its row is the first child
            const element = mark.tagName === 'LI' ? (mark.firstElementChild ?? mark) : mark;
            if (key === null || readKeys.has(key) || !isRendered(element))
                continue;
            const label = labelOfElement(element);
            if (label === '')
                continue;
            readKeys.add(key);
            const reference = register(`nav.${slugOf(key)}`, element);
            const entry = `${reference} "${capValue(label, 40)}"${mark.getAttribute('aria-current') !== null ? ' (open)' : ''}`;
            if (groupKey !== null) {
                const isExpanded = Array.from(mark.querySelectorAll(`[${AGENT_ATTRIBUTES.navKey}]`)).some(isRendered);
                groups.set(groupKey, [isExpanded ? `${capValue(label, 40)} (${reference}):` : `${entry} (closed group)`]);
                continue;
            }
            const owningGroup = mark.parentElement?.closest(`[${AGENT_ATTRIBUTES.navGroup}]`)?.getAttribute(AGENT_ATTRIBUTES.navGroup) ?? '';
            groups.set(owningGroup, [...(groups.get(owningGroup) ?? []), entry]);
        }
        if (readKeys.size === 0)
            return [];
        const lines = [MAIN_MENU_HEADING];
        for (const [groupKey, entries] of groups) {
            if (groupKey === '') {
                lines.push(`- ${entries.join(' · ')}`);
                continue;
            }
            const [head, ...rest] = entries;
            lines.push(rest.length > 0 ? `- ${head} ${rest.join(' · ')}` : `- ${head}`);
        }
        return lines;
    }
    readWhole(element) {
        if (element.matches(FIELD_SELECTOR))
            return valueOfField(element).value;
        // biome-ignore lint/performance/useDomNodeTextContent: the rendered text (no hidden parts) is what the user sees
        return element.innerText ?? element.textContent ?? '';
    }
    /** The sections of a container (by headings), each with its fields, tables and buttons. */
    readContainer(container, register) {
        const lines = [];
        const seen = new Set();
        const headings = Array.from(container.querySelectorAll('h1, h2, h3, h4, [role="heading"]')).filter(isRendered);
        for (const heading of headings) {
            const section = heading.closest('section, fieldset, [data-slot="card"], form') ?? heading.parentElement;
            const text = textOf(heading);
            if (text === '')
                continue;
            const reference = register(`s.${slugOf(text)}`, section ?? heading);
            const collapsed = section?.querySelector('[aria-expanded="false"]') && !section.querySelector(FIELD_SELECTOR) ? ' (closed)' : '';
            lines.push(`## ${capValue(text, 80)} (${reference})${collapsed}`);
            if (section)
                lines.push(...this.readElements(section, register, seen));
        }
        lines.push(...this.readElements(container, register, seen));
        return lines;
    }
    /**
     * The plain text, tables, cards, fields and buttons of a container. Tables and cards go before the fields: a page's
     * filters and forms never push its list out of the read.
     */
    readElements(container, register, seen) {
        const lines = [];
        const text = this.readPlainText(container);
        this.textReadContainers.add(container);
        if (text !== '')
            lines.push(`Text: ${text}`);
        for (const checkbox of Array.from(container.querySelectorAll(ROW_CHECKBOX_SELECTOR)))
            seen.add(checkbox);
        lines.push(...this.readTablesAndCards(container, register, seen));
        for (const field of Array.from(container.querySelectorAll(FIELD_SELECTOR))) {
            if (seen.has(field) || !isRendered(field) || field.closest('[role="radiogroup"]') !== null && field.getAttribute('role') !== 'radiogroup')
                continue;
            seen.add(field);
            const name = field.getAttribute('name') ?? field.getAttribute('id') ?? labelOfElement(field);
            const { value, kind } = valueOfField(field);
            const facts = {
                reference: register(`f.${fieldReferenceNameOf(name.includes(' ') ? slugOf(name) : name)}`, field),
                label: labelOfElement(field),
                value,
                kind,
                required: field.hasAttribute('required') || field.getAttribute('aria-required') === 'true',
                disabled: field.hasAttribute('disabled') || field.getAttribute('aria-readonly') === 'true' || field.hasAttribute('readonly'),
                invalidMessage: invalidMessageOf(field),
                isSecret: field.matches(SECRET_FIELD_SELECTOR) || field.closest(`[${AGENT_ATTRIBUTES.secret}]`) !== null,
            };
            lines.push(formatFieldLine(facts));
        }
        const buttons = [];
        const actions = [];
        for (const button of Array.from(container.querySelectorAll(`${BUTTON_SELECTOR}, ${DECLARED_PRESS_SELECTOR}`))) {
            if (seen.has(button) || !isRendered(button) || button.closest('table, [role="grid"], [role="tablist"]'))
                continue;
            // a declared tab stands in the `Tabs:` line of the page
            if (button.matches(DECLARED_TAB_SELECTOR) && button.closest('form, fieldset') === null)
                continue;
            seen.add(button);
            const label = labelOfElement(button);
            if (label === '')
                continue;
            const disabled = button.hasAttribute('disabled') || button.getAttribute('aria-disabled') === 'true';
            const actionKey = isPressableByAgent(button) ? button.getAttribute(AGENT_ATTRIBUTES.actionKey) : null;
            if (actionKey) {
                actions.push({ reference: register(`act.${slugOf(actionKey)}`, button), label, disabled });
                continue;
            }
            buttons.push({ reference: register(`b.${slugOf(label)}`, button), label, disabled });
        }
        const buttonLine = formatButtons(buttons);
        if (buttonLine)
            lines.push(buttonLine);
        const actionLine = formatActions(actions);
        if (actionLine)
            lines.push(actionLine);
        return lines;
    }
    /** The tables of a container and the cards of a card grid (`EntityListGrid` sets `data-row-key` on each). */
    readTablesAndCards(container, register, seen) {
        const lines = [];
        for (const table of Array.from(container.querySelectorAll(TABLE_SELECTOR))) {
            if (seen.has(table) || !isRendered(table))
                continue;
            seen.add(table);
            lines.push(...formatTable(this.readTable(table, register)));
        }
        const cards = Array.from(container.querySelectorAll(`[${AGENT_ATTRIBUTES.rowKey}]`)).filter(card => !seen.has(card) && !card.closest(TABLE_SELECTOR) && isRendered(card));
        if (cards.length > 0) {
            lines.push(`Cards: ${cards.length} on screen`);
            for (const card of cards)
                seen.add(card);
            for (const card of cards.slice(0, MAX_TABLE_ROWS)) {
                for (const inner of Array.from(card.querySelectorAll(BUTTON_SELECTOR)))
                    seen.add(inner);
                const checkbox = card.querySelector('input[type="checkbox"], [role="checkbox"]');
                const selected = checkbox !== null && isChecked(checkbox) ? ' (selected)' : '';
                lines.push(`- ${register(`row.${rowKeyOf(card, null, 0)}`, card)}${selected}: ${capValue(textOf(card), 160)}`);
            }
            if (cards.length > MAX_TABLE_ROWS)
                lines.push(`… ${cards.length - MAX_TABLE_ROWS} more cards on screen`);
            if (cards.some(isOpenableByAgent))
                lines.push(ROWS_OPEN_LINE);
        }
        return lines;
    }
    /**
     * The visible text of a container that no field, table, card or button line carries, its pieces joined with " · "
     * (pieces of one block with a space), without the text of a section read before: a message, a record's facts or a
     * dashboard figure the user reads.
     */
    readPlainText(container) {
        // two headings in one section read it twice: its text goes once
        if (this.textReadContainers.has(container))
            return '';
        let text = '';
        let previousBlock = null;
        const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node !== null && text.length < MAX_SECTION_TEXT_CHARS; node = walker.nextNode()) {
            const piece = oneLine(node.textContent ?? '');
            const parent = node.parentElement;
            if (piece === '' || parent === null)
                continue;
            // a dialog read as a container is not left out by its own role, only what sits inside it
            const skipped = parent.closest(TEXT_SKIPPED_SELECTOR);
            if (skipped !== null && skipped !== container && container.contains(skipped))
                continue;
            if (!isRendered(parent) || this.isInsideReadSection(parent, container))
                continue;
            const block = this.blockOf(parent, container);
            text = text === '' ? piece : `${text}${block === previousBlock ? ' ' : ' · '}${piece}`;
            previousBlock = block;
        }
        return text.length <= MAX_SECTION_TEXT_CHARS ? text : `${text.slice(0, MAX_SECTION_TEXT_CHARS - 1)}…`;
    }
    /** The nearest ancestor that is not laid out inline (the container at the latest). */
    blockOf(element, container) {
        for (let ancestor = element; ancestor !== null && ancestor !== container; ancestor = ancestor.parentElement) {
            if (!window.getComputedStyle(ancestor).display.startsWith('inline'))
                return ancestor;
        }
        return container;
    }
    isInsideReadSection(element, container) {
        for (let ancestor = element; ancestor !== null && ancestor !== container; ancestor = ancestor.parentElement) {
            if (this.textReadContainers.has(ancestor))
                return true;
        }
        return false;
    }
    readTable(table, register) {
        const headerCells = Array.from(table.querySelectorAll('thead th, [role="columnheader"]'));
        const columns = headerCells.map(cell => textOf(cell));
        const idColumnIndex = columns.findIndex(column => ID_COLUMN_LABELS.has(column.toLowerCase()));
        const bodyRows = Array.from(table.querySelectorAll('tbody tr, [role="row"]')).filter(row => row.querySelector('th[scope="col"], [role="columnheader"]') === null && isRendered(row));
        const rows = bodyRows.slice(0, MAX_TABLE_ROWS).map((row, rowIndex) => ({
            reference: register(`row.${rowKeyOf(row, idColumnIndex >= 0 ? idColumnIndex : null, rowIndex)}`, row),
            cells: Array.from(row.children).map(cell => {
                const secret = cell.closest(`[${AGENT_ATTRIBUTES.secret}]`) !== null || cell.querySelector(`[${AGENT_ATTRIBUTES.secret}]`) !== null;
                return secret ? '(hidden)' : textOf(cell);
            }),
            selected: row.getAttribute('aria-selected') === 'true' || row.getAttribute('data-state') === 'selected' || this.isRowChecked(row),
        }));
        const sortColumns = [];
        for (const control of Array.from(table.querySelectorAll(DECLARED_SORT_SELECTOR))) {
            const sortKey = control.getAttribute(AGENT_ATTRIBUTES.actionKey);
            if (!sortKey || !isRendered(control))
                continue;
            sortColumns.push({ reference: register(`col.${slugOf(sortKey)}`, control), label: labelOfElement(control), direction: sortDirectionOf(control) });
        }
        return {
            caption: textOf(table.querySelector('caption')) || table.getAttribute('aria-label'),
            columns,
            rows,
            totalRows: bodyRows.length,
            sortColumns,
            rowsOpen: bodyRows.some(isOpenableByAgent),
        };
    }
    /** Whether the row's selection checkbox is checked. */
    isRowChecked(row) {
        const checkbox = row.querySelector('input[type="checkbox"], [role="checkbox"]');
        return checkbox !== null && isChecked(checkbox);
    }
}
