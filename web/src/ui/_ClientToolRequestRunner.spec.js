import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from "vitest";
import { ClientToolRequestRunner, MAX_SCREEN_CHARS } from "./ClientToolRequestRunner";
import { formatScreenTargetList, MAX_TARGET_LIST_CHARS } from "./client-tools";
const ORDER_TARGET = {
    key: 'order',
    label: 'Orders (order)',
    group: 'Sales',
    routePattern: '/orders/:orderId',
    parameters: ['orderId'],
    tabs: ['details', 'items'],
    filters: [],
    views: [],
};
const ORDERS_TARGET = {
    key: 'orders',
    label: 'Orders',
    group: null,
    routePattern: '/orders',
    parameters: [],
    tabs: ['create'],
    filters: [{ key: 'customerId' }],
    views: [],
};
const createAdapters = (blocker = null) => {
    const opened = [];
    const marked = [];
    const adapters = {
        targets: { listTargets: () => [ORDERS_TARGET, ORDER_TARGET] },
        navigator: {
            describeBlockers: () => blocker,
            open: async (target, request) => {
                opened.push(`${target.key} ${request.recordIds.join(',')} ${request.tab ?? ''} ${JSON.stringify(request.filters)}`);
                return { route: '/orders/7/items', title: 'Order 7' };
            },
            currentLocation: () => ({ route: '/home', title: 'Home' }),
        },
        reader: {
            read: ({ focus }) => ({ markdown: focus ? `focus ${focus}` : '# Home', route: '/home', title: 'Home', references: {} }),
        },
        highlighter: {
            mark: references => {
                marked.push(references);
                return {
                    marked: (references.includes('f.endDate') ? { 'f.endDate': 'End date' } : {}),
                    missing: references.filter(reference => reference !== 'f.endDate'),
                    outOfView: [],
                    collapsed: [],
                };
            },
            clear: () => undefined,
        },
        isVisible: () => true,
    };
    return { adapters, opened, marked };
};
const delivery = (requestType, parameters, secondsLeft = 10) => ({
    id: 1,
    requestType,
    argumentsJson: JSON.stringify(parameters),
    secondsLeft,
});
describe('ClientToolRequestRunner', () => {
    it('reads the screen, with focus when it is a valid reference', async () => {
        const runner = new ClientToolRequestRunner(createAdapters().adapters);
        assert.equal((await runner.run(delivery('READ_SCREEN', {}))).text, '# Home');
        assert.equal((await runner.run(delivery('READ_SCREEN', { focus: 'f.description' }))).text, 'focus f.description');
        assert.equal((await runner.run(delivery('READ_SCREEN', { focus: '<script>' }))).text, '# Home');
    });
    it('refuses a request that arrives with too few seconds left', async () => {
        const runner = new ClientToolRequestRunner(createAdapters().adapters);
        assert.equal((await runner.run(delivery('READ_SCREEN', {}, 2))).outcome, 'EXPIRED_BEFORE_START');
    });
    it('lists the targets filtered by every word', async () => {
        const runner = new ClientToolRequestRunner(createAdapters().adapters);
        const answer = await runner.run(delivery('LIST_TARGETS', { searchText: 'order items' }));
        assert.match(answer.text, /^Screens this user can open \(1\)/);
        assert.match(answer.text, /- order · Sales › Orders \(order\) \(orderId\) · tabs details, items/);
        assert.match((await runner.run(delivery('LIST_TARGETS', { searchText: 'nothing' }))).text, /No screen matches/);
    });
    it('opens a record screen with its tab and declared filters only', async () => {
        const { adapters, opened } = createAdapters();
        const runner = new ClientToolRequestRunner(adapters);
        const answer = await runner.run(delivery('OPEN_TARGET', { target: 'order', recordId: 7, tab: 'items' }));
        assert.equal(answer.outcome, 'OK');
        assert.deepEqual(answer.details, { route: '/orders/7/items', title: 'Order 7' });
        await runner.run(delivery('OPEN_TARGET', { target: 'orders', filters: ['customerId=3'] }));
        assert.deepEqual(opened, ['order 7 items {}', 'orders   {"customerId":"3"}']);
    });
    it('refuses unknown targets, tabs, filters and a missing record id without navigating', async () => {
        const { adapters, opened } = createAdapters();
        const runner = new ClientToolRequestRunner(adapters);
        const outcomes = await Promise.all([
            runner.run(delivery('OPEN_TARGET', { target: 'nope' })),
            runner.run(delivery('OPEN_TARGET', { target: 'order', recordId: 7, tab: 'secret' })),
            runner.run(delivery('OPEN_TARGET', { target: 'orders', filters: ['email=x'] })),
            runner.run(delivery('OPEN_TARGET', { target: 'order' })),
            runner.run(delivery('OPEN_TARGET', { target: '/admin/../x' })),
        ]);
        assert.deepEqual(outcomes.map(answer => answer.outcome), ['UNKNOWN_TARGET', 'UNKNOWN_TAB', 'UNKNOWN_FILTER', 'MISSING_PARAMETER', 'UNKNOWN_TARGET']);
        assert.deepEqual(opened, []);
    });
    it('never navigates while the screen has unsaved changes', async () => {
        const { adapters, opened } = createAdapters({ outcome: 'UNSAVED_CHANGES', reason: 'unsaved' });
        const answer = await new ClientToolRequestRunner(adapters).run(delivery('OPEN_TARGET', { target: 'orders' }));
        assert.equal(answer.outcome, 'UNSAVED_CHANGES');
        assert.deepEqual(opened, []);
    });
    it('marks what is on the screen and names what is not', async () => {
        const runner = new ClientToolRequestRunner(createAdapters().adapters);
        const answer = await runner.run(delivery('HIGHLIGHT', { references: ['f.endDate', 'b.save'], note: 'Hier' }));
        assert.equal(answer.outcome, 'OK');
        assert.equal(answer.text, 'Marked f.endDate "End date".\nNot on the screen: b.save.');
        assert.equal((await runner.run(delivery('HIGHLIGHT', { references: ['b.save'] }))).outcome, 'NOT_ON_SCREEN');
    });
    it('answers each request once, also when it is delivered again', async () => {
        const runner = new ClientToolRequestRunner(createAdapters().adapters);
        const answers = [];
        const send = async (requestId, answer) => {
            answers.push([requestId, answer]);
        };
        runner.accept(delivery('READ_SCREEN', {}), send);
        runner.accept(delivery('READ_SCREEN', {}), send);
        await runner.whenIdle();
        assert.equal(answers.length, 1);
        assert.equal(answers[0]?.[1].outcome, 'OK');
    });
    it('answers an unknown request type with a refusal', async () => {
        const runner = new ClientToolRequestRunner(createAdapters().adapters);
        assert.equal((await runner.run(delivery('CAPTURE', {}))).outcome, 'UNKNOWN_REQUEST');
    });
    it('steps the agent window aside before opening, guides without waiting, and the guide never blocks the answer', async () => {
        const { adapters, opened } = createAdapters();
        const events = [];
        adapters.agentWindow = {
            stepAside: requestType => { events.push(`aside ${requestType}`); },
            restore: () => { events.push('restore'); },
            setMode: () => true,
        };
        adapters.pathGuide = {
            guide: () => { events.push('guide'); throw new Error('broken guide'); },
            cancel: () => undefined,
        };
        const runner = new ClientToolRequestRunner(adapters);
        const answer = await runner.run(delivery('OPEN_TARGET', { target: 'orders' }));
        assert.equal(answer.outcome, 'OK');
        assert.deepEqual(events, ['aside OPEN_TARGET', 'guide']);
        assert.equal(opened.length, 1);
    });
    it('sets the window mode, restores, and refuses unknown modes or a missing window', async () => {
        const { adapters } = createAdapters();
        const runnerWithoutWindow = new ClientToolRequestRunner(adapters);
        assert.equal((await runnerWithoutWindow.run(delivery('SET_WINDOW_MODE', { mode: 'aside' }))).outcome, 'UNKNOWN_MODE');
        const modes = [];
        adapters.agentWindow = {
            stepAside: () => undefined,
            restore: () => { modes.push('restore'); },
            setMode: mode => { modes.push(mode); return mode === 'aside'; },
        };
        const runner = new ClientToolRequestRunner(adapters);
        assert.equal((await runner.run(delivery('SET_WINDOW_MODE', { mode: 'aside' }))).outcome, 'OK');
        assert.equal((await runner.run(delivery('SET_WINDOW_MODE', { mode: 'restore' }))).outcome, 'OK');
        assert.equal((await runner.run(delivery('SET_WINDOW_MODE', { mode: 'huge' }))).outcome, 'UNKNOWN_MODE');
        assert.deepEqual(modes, ['aside', 'restore', 'huge']);
    });
    it('captures a picture with the window aside and restores it afterwards', async () => {
        const { adapters } = createAdapters();
        const runnerWithoutCapturer = new ClientToolRequestRunner(adapters);
        assert.equal((await runnerWithoutCapturer.run(delivery('READ_SCREEN', { format: 'picture' }))).outcome, 'PICTURE_UNAVAILABLE');
        const events = [];
        adapters.agentWindow = {
            stepAside: () => { events.push('aside'); },
            restore: () => { events.push('restore'); },
            setMode: () => true,
        };
        adapters.picture = {
            capture: async (request) => {
                events.push(`capture ${request.pictureSize} ${request.renderMode}`);
                return { base64: 'AAAA', mimeType: 'image/webp', width: 800, height: 600, byteSize: 3 };
            },
        };
        const runner = new ClientToolRequestRunner(adapters);
        const answer = await runner.run(delivery('READ_SCREEN', { format: 'picture', pictureSize: 'small', renderMode: 'machine' }));
        assert.equal(answer.outcome, 'OK');
        assert.equal(answer.pictureBase64, 'AAAA');
        assert.equal(answer.details.width, 800);
        assert.deepEqual(events, ['aside', 'capture small machine', 'restore']);
        const defaults = await runner.run(delivery('READ_SCREEN', { format: 'picture', pictureSize: 'huge' }));
        assert.equal(defaults.outcome, 'OK');
        assert.equal(events[4], 'capture medium colour');
    });
    it('runs a handler of the application for a further type, never for a type of the package', async () => {
        const { adapters } = createAdapters();
        adapters.requestHandlers = {
            PLAY_SOUND: parameters => ({ outcome: 'OK', text: `played ${String(parameters.sound)}`, details: {} }),
            READ_SCREEN: () => ({ outcome: 'OK', text: 'replaced', details: {} }),
        };
        const runner = new ClientToolRequestRunner(adapters);
        assert.equal((await runner.run(delivery('PLAY_SOUND', { sound: 'chime' }))).text, 'played chime');
        assert.equal((await runner.run(delivery('READ_SCREEN', {}))).text, '# Home');
    });
});
describe('ClientToolRequestRunner: actOnScreen', () => {
    const withActions = (blocker = null) => {
        const created = createAdapters(blocker);
        const applied = [];
        const acted = [];
        created.adapters.navigator.currentTarget = () => ORDERS_TARGET;
        created.adapters.navigator.applyFilters = async (target, filters) => {
            applied.push(`${target.key} ${JSON.stringify(filters)}`);
            return { route: '/orders?customerId=3', title: 'Orders' };
        };
        created.adapters.actions = {
            act: async (request) => {
                acted.push(`${request.action} ${request.reference} ${request.direction ?? ''}`.trim());
                return { outcome: 'OK', text: `Did ${request.action}.`, details: { route: '/orders/7', title: 'Order 7', routeChanged: true } };
            },
        };
        return { ...created, applied, acted };
    };
    it('sets and clears only the filters the open screen declares', async () => {
        const { adapters, applied } = withActions();
        const runner = new ClientToolRequestRunner(adapters);
        const answer = await runner.run(delivery('ACT_ON_SCREEN', { action: 'setFilters', filters: ['customerId=3'] }));
        const cleared = await runner.run(delivery('ACT_ON_SCREEN', { action: 'setFilters', filters: ['customerId='] }));
        const unknown = await runner.run(delivery('ACT_ON_SCREEN', { action: 'setFilters', filters: ['email=x'] }));
        const none = await runner.run(delivery('ACT_ON_SCREEN', { action: 'setFilters' }));
        assert.equal(answer.outcome, 'OK');
        assert.equal(answer.text, 'Filters of orders: customerId=3.');
        assert.equal(cleared.text, 'Filters of orders: customerId cleared.');
        assert.equal(unknown.outcome, 'UNKNOWN_FILTER');
        assert.match(unknown.text, /customerId/);
        assert.equal(none.outcome, 'MISSING_PARAMETER');
        assert.deepEqual(applied, ['orders {"customerId":"3"}', 'orders {"customerId":""}']);
    });
    it('refuses a filter value the open screen\'s filter does not take, and names its values', async () => {
        const { adapters, applied } = withActions();
        const valued = { ...ORDERS_TARGET, filters: [{ key: 'status', values: ['OPEN', 'SOLVED'] }] };
        adapters.navigator.currentTarget = () => valued;
        const runner = new ClientToolRequestRunner(adapters);
        const refused = await runner.run(delivery('ACT_ON_SCREEN', { action: 'setFilters', filters: ['status=DONE'] }));
        const taken = await runner.run(delivery('ACT_ON_SCREEN', { action: 'setFilters', filters: ['status=SOLVED'] }));
        const cleared = await runner.run(delivery('ACT_ON_SCREEN', { action: 'setFilters', filters: ['status='] }));
        assert.equal(refused.outcome, 'UNKNOWN_FILTER_VALUE');
        assert.equal(refused.text, 'Not a value of this filter: status=DONE (status one of OPEN|SOLVED).');
        assert.equal(taken.outcome, 'OK');
        assert.equal(cleared.outcome, 'OK');
        assert.deepEqual(applied, ['orders {"status":"SOLVED"}', 'orders {"status":""}']);
    });
    it('refuses setFilters on a screen without filters, with unsaved changes, and without a navigator that can', async () => {
        const withoutFilters = withActions();
        withoutFilters.adapters.navigator.currentTarget = () => ORDER_TARGET;
        assert.equal((await new ClientToolRequestRunner(withoutFilters.adapters).run(delivery('ACT_ON_SCREEN', { action: 'setFilters', filters: ['customerId=3'] }))).outcome, 'NO_FILTERS');
        const unsaved = withActions({ outcome: 'UNSAVED_CHANGES', reason: 'unsaved' });
        assert.equal((await new ClientToolRequestRunner(unsaved.adapters).run(delivery('ACT_ON_SCREEN', { action: 'setFilters', filters: ['customerId=3'] }))).outcome, 'UNSAVED_CHANGES');
        assert.deepEqual(unsaved.applied, []);
        const plain = createAdapters();
        assert.equal((await new ClientToolRequestRunner(plain.adapters).run(delivery('ACT_ON_SCREEN', { action: 'setFilters', filters: ['customerId=3'] }))).outcome, 'ACTIONS_UNAVAILABLE');
    });
    it('hands the other actions to the actor with a valid reference and direction only', async () => {
        const { adapters, acted } = withActions();
        const runner = new ClientToolRequestRunner(adapters);
        const sorted = await runner.run(delivery('ACT_ON_SCREEN', { action: 'sort', reference: 'col.created', direction: 'desc' }));
        const opened = await runner.run(delivery('ACT_ON_SCREEN', { action: 'openRow', reference: 'row.7', includeScreen: true }));
        const outcomes = await Promise.all([
            runner.run(delivery('ACT_ON_SCREEN', { action: 'click', reference: 'b.save' })),
            runner.run(delivery('ACT_ON_SCREEN', { action: 'press', reference: 'button.danger > span' })),
            runner.run(delivery('ACT_ON_SCREEN', { action: 'press' })),
        ]);
        assert.equal(sorted.outcome, 'OK');
        assert.equal(opened.text, 'Did openRow.\n# Home');
        assert.deepEqual(opened.details, { route: '/orders/7', title: 'Order 7', routeChanged: true });
        assert.deepEqual(outcomes.map(answer => answer.outcome), ['UNKNOWN_ACTION', 'MISSING_PARAMETER', 'MISSING_PARAMETER']);
        assert.deepEqual(acted, ['sort col.created desc', 'openRow row.7']);
    });
    it('answers ACTIONS_UNAVAILABLE without an actor, and steps the agent window aside before acting', async () => {
        const plain = createAdapters();
        assert.equal((await new ClientToolRequestRunner(plain.adapters).run(delivery('ACT_ON_SCREEN', { action: 'press', reference: 'act.more' }))).outcome, 'ACTIONS_UNAVAILABLE');
        const { adapters } = withActions();
        const steppedAside = [];
        adapters.agentWindow = { stepAside: requestType => void steppedAside.push(requestType), restore: () => undefined, setMode: () => true };
        await new ClientToolRequestRunner(adapters).run(delivery('ACT_ON_SCREEN', { action: 'press', reference: 'act.more' }));
        assert.deepEqual(steppedAside, ['ACT_ON_SCREEN']);
    });
});
describe('MAX_SCREEN_CHARS', () => {
    it('is the server cut of a UI tool answer, half the answer cap', () => {
        const dtoFile = resolve(dirname(fileURLToPath(import.meta.url)), '../../../src/Presentation/Mcp/Client/Agents/Dtos/AgentUIToolResponseDto.php');
        const serverCut = Number(/MAX_CONTENT_CHARS = (\d+);/.exec(readFileSync(dtoFile, 'utf8'))?.[1]);
        assert.equal(MAX_SCREEN_CHARS, serverCut);
    });
    it('names the values of the filters in a narrowed list and only the keys in the whole catalogue', async () => {
        const valued = {
            ...ORDERS_TARGET,
            filters: [
                { key: 'customerId', hint: 'id' },
                { key: 'status', values: ['OPEN', 'SOLVED'], multiple: true },
                { key: 'sort', values: ['id:asc', 'id:desc'] },
            ],
        };
        const others = [1, 2, 3, 4].map(index => ({ ...ORDERS_TARGET, key: `other${index}`, label: `Other ${index}`, routePattern: `/other${index}`, filters: [] }));
        const created = createAdapters();
        created.adapters.targets = { listTargets: () => [valued, ...others] };
        const runner = new ClientToolRequestRunner(created.adapters);
        const narrowed = await runner.run(delivery('LIST_TARGETS', { searchText: 'orders' }));
        assert.match(narrowed.text, /- orders · .*filters customerId, status, sort\n {2}filter values: customerId id · status one or more of OPEN\|SOLVED, comma-separated · sort one of id, each with :asc or :desc/);
        const whole = await runner.run(delivery('LIST_TARGETS', {}));
        assert.doesNotMatch(whole.text, /filter values:/);
        assert.match(whole.text, /Filter values of a screen: listScreens with its key as searchText\./);
    });
    it('names the filter values of the screen whose key is the search text, first, however many screens match', () => {
        const valued = { ...ORDERS_TARGET, key: 'order', label: 'Order', filters: [{ key: 'status', values: ['OPEN', 'SOLVED'] }] };
        const others = [1, 2, 3, 4, 5].map(index => ({ ...ORDERS_TARGET, key: `order-list${index}`, label: `Order list ${index}`, filters: [{ key: 'state', values: ['A', 'B'] }] }));
        const lines = formatScreenTargetList([...others, valued], 'order').split('\n');
        assert.match(lines[0] ?? '', /^Screens this user can open \(6\)\./);
        assert.match(lines[1] ?? '', /^- order · Order · /);
        assert.equal(lines[2], '  filter values: status one of OPEN|SOLVED');
        assert.equal(lines.filter(line => line.startsWith('  filter values:')).length, 1, 'the other five name their keys only');
        assert.equal(lines.filter(line => line.startsWith('- order-list')).length, 5);
    });
    it('lists every screen of a catalogue too long for full lines in a short line', () => {
        const tabs = ['details', 'items', 'invoices', 'shipments', 'returns', 'notes'];
        const filters = ['customerId', 'status', 'createdFrom', 'createdTo', 'country', 'channel', 'sort'].map(key => ({ key }));
        const many = Array.from({ length: 60 }, (_, index) => ({ ...ORDER_TARGET, key: `area${index}`, label: `Area ${index}`, tabs, filters }));
        const whole = formatScreenTargetList(many, '');
        assert.ok(whole.length <= MAX_TARGET_LIST_CHARS);
        assert.doesNotMatch(whole, /more; narrow with searchText/, 'no screen is left out');
        assert.match(whole, /Short lines, the list is long\. Tabs, filters and filter values of a screen: listScreens with its key as searchText\./);
        assert.match(whole, /\n- area59 · Area 59 \(orderId\) · 6 tabs · filters$/);
        const one = formatScreenTargetList(many, 'area7');
        assert.match(one, /^Screens this user can open \(1\)\. Pass the key to openScreen\.\n- area7 · Sales › Area 7 \(orderId\) · tabs details, items, invoices, shipments, returns, notes · filters customerId, status, createdFrom, createdTo, country, channel, sort$/);
        const tooMany = formatScreenTargetList(Array.from({ length: 400 }, (_, index) => ({ ...ORDER_TARGET, key: `area${index}`, label: `Area ${index}`, tabs, filters })), '');
        assert.match(tooMany, /… \d+ more; narrow with searchText\.$/, 'short lines are capped as well');
    });
    it('opens a screen without a filter value its filter does not take, and names it', async () => {
        const valued = { ...ORDERS_TARGET, filters: [{ key: 'status', values: ['OPEN', 'SOLVED'], multiple: true }, { key: 'customerId' }] };
        const created = createAdapters();
        created.adapters.targets = { listTargets: () => [valued] };
        const runner = new ClientToolRequestRunner(created.adapters);
        const answer = await runner.run(delivery('OPEN_TARGET', { target: 'orders', filters: ['status=OPEN,DONE', 'customerId=3'] }));
        assert.equal(answer.outcome, 'OK');
        assert.deepEqual(created.opened, ['orders   {"customerId":"3"}']);
        assert.equal(answer.details.filtersNotSet, 'status=OPEN,DONE (status one or more of OPEN|SOLVED, comma-separated)');
        const fine = await runner.run(delivery('OPEN_TARGET', { target: 'orders', filters: ['status=OPEN,SOLVED'] }));
        assert.equal(fine.details.filtersNotSet, undefined);
        assert.deepEqual(created.opened[1], 'orders   {"status":"OPEN,SOLVED"}');
    });
    it('takes a filter of a tab with that tab only and names the filters of each tab in a narrowed list', async () => {
        const record = {
            ...ORDER_TARGET,
            filters: [
                { key: 'status', values: ['OPEN', 'SOLVED'], tabs: ['items'] },
                { key: 'sort', values: ['id:asc', 'id:desc'], tabs: ['items'] },
                { key: 'sort', values: ['name:asc'], tabs: ['details'] },
            ],
        };
        const created = createAdapters();
        created.adapters.targets = { listTargets: () => [record] };
        const runner = new ClientToolRequestRunner(created.adapters);
        const listed = await runner.run(delivery('LIST_TARGETS', { searchText: 'order' }));
        assert.match(listed.text, /tabs with filters items, details\n {2}filters of tab items: status one of OPEN\|SOLVED · sort one of id, each with :asc or :desc\n {2}filters of tab details: sort one of name:asc/);
        const onTab = await runner.run(delivery('OPEN_TARGET', { target: 'order', recordId: 7, tab: 'items', filters: ['status=OPEN', 'sort=id:desc'] }));
        assert.equal(onTab.outcome, 'OK');
        assert.deepEqual(created.opened, ['order 7 items {"status":"OPEN","sort":"id:desc"}']);
        const otherTab = await runner.run(delivery('OPEN_TARGET', { target: 'order', recordId: 7, tab: 'details', filters: ['status=OPEN'] }));
        assert.equal(otherTab.outcome, 'UNKNOWN_FILTER');
        const noTab = await runner.run(delivery('OPEN_TARGET', { target: 'order', recordId: 7, filters: ['status=OPEN'] }));
        assert.equal(noTab.outcome, 'UNKNOWN_FILTER');
    });
});
