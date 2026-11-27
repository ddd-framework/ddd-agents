import assert from 'node:assert/strict';
import { describe, it } from "vitest";
import { ClientToolRequestRunner } from "./ClientToolRequestRunner";
import { createLatestScreenAdapters } from "./useAgentUIClient";
const ORDERS_TARGET = {
    key: 'orders',
    label: 'Orders',
    group: null,
    routePattern: '/orders',
    parameters: [],
    tabs: [],
    filters: [{ key: 'customerId' }],
    views: [],
};
const createPlainAdapters = () => ({
    targets: { listTargets: () => [ORDERS_TARGET] },
    navigator: {
        describeBlockers: () => null,
        open: async () => ({ route: '/orders', title: 'Orders' }),
        currentLocation: () => ({ route: '/orders', title: 'Orders' }),
    },
    reader: { read: () => ({ markdown: '# Orders', route: '/orders', title: 'Orders', references: {} }) },
    highlighter: { mark: () => ({ marked: {}, missing: [], outOfView: [], collapsed: [] }), clear: () => undefined },
    isVisible: () => true,
});
const delivery = (parameters) => ({ id: 1, requestType: 'ACT_ON_SCREEN', argumentsJson: JSON.stringify(parameters), secondsLeft: 10 });
describe('createLatestScreenAdapters', () => {
    it('passes the screen actions of the newest adapters on: the actor and the navigator methods of setFilters', async () => {
        const applied = [];
        const acted = [];
        let current = createPlainAdapters();
        const latest = createLatestScreenAdapters(() => current);
        const runner = new ClientToolRequestRunner(latest);
        // adapters without the screen actions: the runner says so
        assert.equal(latest.actions, undefined);
        assert.equal(latest.navigator.currentTarget, undefined);
        assert.equal(latest.navigator.applyFilters, undefined);
        assert.equal((await runner.run(delivery({ action: 'setFilters', filters: ['customerId=3'] }))).outcome, 'ACTIONS_UNAVAILABLE');
        assert.equal((await runner.run(delivery({ action: 'sort', reference: 'col.created', direction: 'asc' }))).outcome, 'ACTIONS_UNAVAILABLE');
        // a later render brings them: the same adapter object answers with them
        const withActions = createPlainAdapters();
        withActions.navigator.currentTarget = () => ORDERS_TARGET;
        withActions.navigator.applyFilters = async (target, filters) => {
            applied.push(`${target.key} ${JSON.stringify(filters)}`);
            return { route: '/orders?customerId=3', title: 'Orders' };
        };
        withActions.actions = {
            act: async (request) => {
                acted.push(`${request.action} ${request.reference}`);
                return { outcome: 'OK', text: 'Sorted.', details: {} };
            },
        };
        current = withActions;
        assert.equal((await runner.run(delivery({ action: 'setFilters', filters: ['customerId=3'] }))).outcome, 'OK');
        assert.equal((await runner.run(delivery({ action: 'sort', reference: 'col.created', direction: 'asc' }))).outcome, 'OK');
        assert.deepEqual(applied, ['orders {"customerId":"3"}']);
        assert.deepEqual(acted, ['sort col.created']);
    });
});
