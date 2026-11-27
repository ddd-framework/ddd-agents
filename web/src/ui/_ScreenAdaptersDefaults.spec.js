import assert from 'node:assert/strict';
import { afterEach, describe, it, vi } from "vitest";
import { createAgentWindowAdapter } from "./agent-window";
import { createDomScreenPathGuide, createGuideCounter, PATH_GUIDE_CLASS, PATH_GUIDE_COUNT_KEY_PREFIX } from "./DomScreenPathGuide";
import { createDomScreenTargetSource } from "./DomScreenTargetSource";
import { acceptArrivedRealtimeMessage, CLIENT_TOOL_REQUEST_MESSAGE_TYPE, readClientToolRequestOfRealtimeMessage, runClientToolRequestForDevelopment, } from "./realtime-arrival";
const SETTINGS_TARGET = {
    key: 'settings',
    label: 'Settings',
    group: null,
    routePattern: '/settings',
    parameters: [],
    tabs: [],
    filters: [],
    views: [],
};
describe('createDomScreenTargetSource', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });
    it('reads the marks, the first mark of a key wins, a mark without a route is left out', () => {
        document.body.innerHTML = `
      <nav>
        <a href="/orders?x=1" data-agent-anchor="orders" data-agent-group="Sales" data-agent-filters="customerId, status" data-agent-tabs="create"> Orders </a>
        <a href="/orders/other" data-agent-anchor="orders">Duplicate</a>
        <button data-agent-anchor="order" data-agent-route="/orders/:orderId" data-agent-parameters="orderId" data-agent-label="Order">x</button>
        <span data-agent-anchor="noRoute">No route</span>
        <a href="/bad" data-agent-anchor="bad key">Bad</a>
      </nav>`;
        const targets = createDomScreenTargetSource().listTargets();
        assert.deepEqual(targets.map(target => target.key), ['orders', 'order']);
        assert.equal(targets[0].routePattern, '/orders');
        assert.equal(targets[0].label, 'Orders');
        assert.equal(targets[0].group, 'Sales');
        assert.deepEqual(targets[0].filters, [{ key: 'customerId' }, { key: 'status' }]);
        assert.deepEqual(targets[1].parameters, ['orderId']);
    });
    it('keeps only the marks of a group when a groupKey is given', () => {
        document.body.innerHTML = `
      <a href="/a" data-agent-anchor="a" data-agent-anchor-group="main">A</a>
      <a href="/b" data-agent-anchor="b" data-agent-anchor-group="footer">B</a>`;
        assert.deepEqual(createDomScreenTargetSource({ groupKey: 'main' }).listTargets().map(target => target.key), ['a']);
    });
});
describe('createGuideCounter', () => {
    const createMemoryStorage = () => {
        const entries = new Map();
        return { getItem: (key) => entries.get(key) ?? null, setItem: (key, value) => void entries.set(key, value) };
    };
    it('guides a target twice, counted in the storage', () => {
        const storage = createMemoryStorage();
        const shouldGuide = createGuideCounter(2, () => storage);
        assert.equal(shouldGuide('orders'), true);
        assert.equal(shouldGuide('orders'), true);
        assert.equal(shouldGuide('orders'), false);
        assert.equal(storage.getItem(`${PATH_GUIDE_COUNT_KEY_PREFIX}orders`), '2');
        assert.equal(createGuideCounter(2, () => storage)('orders'), false);
    });
    it('works without storage', () => {
        const shouldGuide = createGuideCounter(1, () => {
            throw new Error('blocked');
        });
        assert.equal(shouldGuide('x'), true);
        assert.equal(shouldGuide('x'), false);
    });
});
describe('createDomScreenPathGuide', () => {
    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });
    const openRequest = { recordIds: [], tab: null, filters: {} };
    it('marks the steps one after the other and ends by itself', () => {
        vi.useFakeTimers();
        document.body.innerHTML = '<a id="menu">Admin</a><a id="entry">Settings</a>';
        const menu = document.getElementById('menu');
        const entry = document.getElementById('entry');
        const pathGuide = createDomScreenPathGuide({
            findPathElements: () => [menu, entry],
            currentRoute: () => '/home',
            shouldGuide: () => true,
            stepMs: 100,
        });
        assert.equal(pathGuide.guide(SETTINGS_TARGET, openRequest), true);
        vi.advanceTimersByTime(0);
        assert.ok(menu.classList.contains(PATH_GUIDE_CLASS));
        vi.advanceTimersByTime(100);
        assert.ok(!menu.classList.contains(PATH_GUIDE_CLASS));
        assert.ok(entry.classList.contains(PATH_GUIDE_CLASS));
        vi.advanceTimersByTime(100);
        assert.ok(!entry.classList.contains(PATH_GUIDE_CLASS));
        assert.equal(entry.style.outline, '');
    });
    it('is cancelled by Esc and by a click', () => {
        vi.useFakeTimers();
        document.body.innerHTML = '<a id="entry">Settings</a>';
        const entry = document.getElementById('entry');
        const pathGuide = createDomScreenPathGuide({ findPathElements: () => [entry], currentRoute: () => '/', shouldGuide: () => true });
        pathGuide.guide(SETTINGS_TARGET, openRequest);
        vi.advanceTimersByTime(0);
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        assert.ok(!entry.classList.contains(PATH_GUIDE_CLASS));
        pathGuide.guide(SETTINGS_TARGET, openRequest);
        vi.advanceTimersByTime(0);
        document.dispatchEvent(new Event('pointerdown'));
        assert.ok(!entry.classList.contains(PATH_GUIDE_CLASS));
    });
    it('skips when already on the target, without a path element, and under reduced motion', () => {
        document.body.innerHTML = '<a id="entry">Settings</a>';
        const entry = document.getElementById('entry');
        const onTarget = createDomScreenPathGuide({ findPathElements: () => [entry], currentRoute: () => '/settings?tab=1', shouldGuide: () => true });
        assert.equal(onTarget.guide(SETTINGS_TARGET, openRequest), false);
        const noElement = createDomScreenPathGuide({ findPathElements: () => [], currentRoute: () => '/', shouldGuide: () => true });
        assert.equal(noElement.guide(SETTINGS_TARGET, openRequest), false);
        const matchMedia = window.matchMedia;
        window.matchMedia = ((query) => ({ matches: query.includes('reduce') }));
        try {
            const reduced = createDomScreenPathGuide({ findPathElements: () => [entry], currentRoute: () => '/', shouldGuide: () => true });
            assert.equal(reduced.guide(SETTINGS_TARGET, openRequest), false);
        }
        finally {
            window.matchMedia = matchMedia;
        }
    });
});
describe('createAgentWindowAdapter', () => {
    it('steps aside, restores the mode before and refuses unknown modes', async () => {
        let mode = 'docked';
        const agentWindow = createAgentWindowAdapter({ getMode: () => mode, applyMode: next => { mode = next; }, modes: ['docked', 'aside', 'full'] });
        await agentWindow.stepAside('OPEN_TARGET');
        assert.equal(mode, 'aside');
        await agentWindow.restore();
        assert.equal(mode, 'docked');
        assert.equal(await agentWindow.setMode('huge'), false);
        assert.equal(await agentWindow.setMode('full'), true);
        assert.equal(mode, 'full');
        assert.equal(agentWindow.shouldStepAsideBefore?.('LIST_TARGETS'), false);
        assert.equal(agentWindow.shouldStepAsideBefore?.('HIGHLIGHT'), true);
    });
});
describe('realtime arrival', () => {
    const requestMessage = {
        messageType: CLIENT_TOOL_REQUEST_MESSAGE_TYPE,
        messageKey: '42',
        payloadJson: JSON.stringify({ id: 42, requestType: 'READ_SCREEN', argumentsJson: '{}', secondsLeft: 20 }),
        secondsLeft: 15,
    };
    it('reads the request of a message, the smaller seconds left win', () => {
        assert.deepEqual(readClientToolRequestOfRealtimeMessage(requestMessage), { id: 42, requestType: 'READ_SCREEN', argumentsJson: '{}', secondsLeft: 15 });
        assert.equal(readClientToolRequestOfRealtimeMessage({ ...requestMessage, payloadJson: 'nope' }), null);
        assert.equal(readClientToolRequestOfRealtimeMessage({ ...requestMessage, messageType: 'call.ring' }), null);
    });
    it('hands a request to the acceptor and other types to the callback', () => {
        const accepted = [];
        const others = [];
        const acceptor = { acceptClientToolRequest: (_conversationId, delivery) => accepted.push(delivery.id) };
        const sendAnswer = async () => undefined;
        assert.equal(acceptArrivedRealtimeMessage(7, requestMessage, acceptor, sendAnswer, (_id, message) => others.push(message.messageType)), true);
        assert.equal(acceptArrivedRealtimeMessage(7, { ...requestMessage, messageType: 'call.ring' }, acceptor, sendAnswer, (_id, message) => others.push(message.messageType)), false);
        assert.deepEqual(accepted, [42]);
        assert.deepEqual(others, ['call.ring']);
    });
    it('runs a request for development and resolves with the answer', async () => {
        const acceptor = {
            acceptClientToolRequest: (_conversationId, delivery, sendAnswer) => {
                void sendAnswer(1, { outcome: 'OK', text: delivery.requestType, details: {} });
            },
        };
        const answer = await runClientToolRequestForDevelopment(acceptor, 'LIST_TARGETS');
        assert.equal(answer.text, 'LIST_TARGETS');
    });
});
