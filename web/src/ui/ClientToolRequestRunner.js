import { buildTargetRoute, CLIENT_TOOL_OUTCOMES, capAnswerText, describeUnknownScreenFilterValue, findUnknownScreenFilterValue, formatScreenTargetList, screenTargetFiltersOfTab, isClientToolToken, MAX_ANSWER_TEXT_CHARS, MIN_SECONDS_LEFT_TO_START, parseClientToolArguments, SCREEN_ACTION_KINDS, SCREEN_SORT_DIRECTIONS, } from "./client-tools";
/** The request types of the package; a handler of the application never replaces them. */
const PACKAGE_REQUEST_TYPES = ['READ_SCREEN', 'LIST_TARGETS', 'OPEN_TARGET', 'HIGHLIGHT', 'SET_WINDOW_MODE', 'ACT_ON_SCREEN'];
/** The request types the agent window steps aside for by default. */
const STEP_ASIDE_REQUEST_TYPES = ['OPEN_TARGET', 'HIGHLIGHT', 'READ_SCREEN', 'ACT_ON_SCREEN'];
/** The most filters one `setFilters` action takes (the server's `AgentUIMCPPackage::MAX_FILTERS`). */
const MAX_ACTION_FILTERS = 6;
/** The most characters of one filter value (the server's `AgentUIMCPPackage::MAX_TOKEN_CHARS`). */
const MAX_FILTER_VALUE_CHARS = 100;
const PICTURE_SIZES = ['small', 'medium', 'large'];
const RENDER_MODES = ['colour', 'greyscale', 'machine'];
/**
 * The most characters of screen content in one answer: half the answer's cap ({@link MAX_ANSWER_TEXT_CHARS}), the
 * same number as the server's cut (`AgentUIToolResponseDto::MAX_CONTENT_CHARS`, a contract test compares them). With
 * 6,000 a list page was cut before its table; the reader's own caps (rows, cells, section text) keep a read short.
 */
export const MAX_SCREEN_CHARS = MAX_ANSWER_TEXT_CHARS / 2;
/** How many request ids a runner remembers, so a redelivery never runs a request twice. */
const REMEMBERED_REQUEST_IDS = 50;
/**
 * Runs the requests the run-state read delivers, one at a time, through the application's adapters, and posts each
 * answer once. Generic: it knows the four request types and the refusal codes, nothing of a menu or a route.
 */
export class ClientToolRequestRunner {
    handledRequestIds = [];
    running = Promise.resolve();
    adapters;
    constructor(adapters) {
        this.adapters = adapters;
    }
    /**
     * Takes a delivery and posts its answer through `sendAnswer`; a request already taken is ignored (the server
     * redelivers an unanswered one after 5 s).
     */
    accept(delivery, sendAnswer) {
        if (this.handledRequestIds.includes(delivery.id))
            return;
        this.handledRequestIds.push(delivery.id);
        if (this.handledRequestIds.length > REMEMBERED_REQUEST_IDS)
            this.handledRequestIds.shift();
        const receivedAt = Date.now();
        this.running = this.running.then(() => this.runAndAnswer(delivery, receivedAt, sendAnswer));
    }
    /** Resolves when every accepted request was answered (tests). */
    whenIdle() {
        return this.running;
    }
    async runAndAnswer(delivery, receivedAt, sendAnswer) {
        const answer = await this.run(delivery, receivedAt);
        try {
            await sendAnswer(delivery.id, { ...answer, text: capAnswerText(answer.text) });
        }
        catch {
            // the tool answers "no answer" after its wait; nothing else to do in the tab
        }
    }
    /** The answer of one request. Exposed for the tests. */
    async run(delivery, receivedAt = Date.now()) {
        const secondsLeft = delivery.secondsLeft - (Date.now() - receivedAt) / 1000;
        if (secondsLeft < MIN_SECONDS_LEFT_TO_START)
            return refusal(CLIENT_TOOL_OUTCOMES.expiredBeforeStart);
        const parameters = parseClientToolArguments(delivery.argumentsJson);
        try {
            switch (delivery.requestType) {
                case 'READ_SCREEN':
                    if (parameters.format === 'picture') {
                        await this.stepAgentWindowAside('READ_SCREEN');
                        try {
                            return await this.readScreenPicture(parameters);
                        }
                        finally {
                            await this.adapters.agentWindow?.restore();
                        }
                    }
                    return this.readScreen(parameters);
                case 'LIST_TARGETS':
                    return this.listTargets(parameters);
                case 'OPEN_TARGET':
                    return await this.openTarget(parameters);
                case 'HIGHLIGHT':
                    await this.stepAgentWindowAside('HIGHLIGHT');
                    return this.highlight(parameters);
                case 'SET_WINDOW_MODE':
                    return await this.setWindowMode(parameters);
                case 'ACT_ON_SCREEN':
                    return await this.actOnScreen(parameters);
                default:
                    return await this.runRequestOfApplication(delivery, parameters);
            }
        }
        catch {
            return refusal(CLIENT_TOOL_OUTCOMES.readFailed);
        }
    }
    /** A request type the application registered: its handler, else `UNKNOWN_REQUEST`. */
    async runRequestOfApplication(delivery, parameters) {
        const handler = PACKAGE_REQUEST_TYPES.includes(delivery.requestType) ? undefined : this.adapters.requestHandlers?.[delivery.requestType];
        if (!handler)
            return refusal(CLIENT_TOOL_OUTCOMES.unknownRequest);
        return handler(parameters, delivery);
    }
    /** Lets the agent window make room before the agent works on the screen (the default for the screen requests). */
    async stepAgentWindowAside(requestType) {
        const agentWindow = this.adapters.agentWindow;
        if (!agentWindow)
            return;
        const shouldStepAside = agentWindow.shouldStepAsideBefore
            ? agentWindow.shouldStepAsideBefore(requestType)
            : STEP_ASIDE_REQUEST_TYPES.includes(requestType);
        if (shouldStepAside)
            await agentWindow.stepAside(requestType);
    }
    async setWindowMode(parameters) {
        const agentWindow = this.adapters.agentWindow;
        if (!agentWindow || !isClientToolToken(parameters.mode))
            return refusal(CLIENT_TOOL_OUTCOMES.unknownMode);
        if (parameters.mode === 'restore') {
            await agentWindow.restore();
            return { outcome: CLIENT_TOOL_OUTCOMES.ok, text: '', details: { mode: 'restore' } };
        }
        const accepted = await agentWindow.setMode(parameters.mode);
        if (!accepted)
            return refusal(CLIENT_TOOL_OUTCOMES.unknownMode);
        return { outcome: CLIENT_TOOL_OUTCOMES.ok, text: '', details: { mode: parameters.mode } };
    }
    async readScreenPicture(parameters) {
        const capturer = this.adapters.picture;
        if (!capturer)
            return refusal(CLIENT_TOOL_OUTCOMES.pictureUnavailable);
        const pictureSize = PICTURE_SIZES.find(size => size === parameters.pictureSize) ?? 'medium';
        const renderMode = RENDER_MODES.find(mode => mode === parameters.renderMode) ?? 'colour';
        const picture = await capturer.capture({ pictureSize, renderMode });
        if (!picture)
            return refusal(CLIENT_TOOL_OUTCOMES.pictureUnavailable);
        const location = this.adapters.navigator.currentLocation();
        return {
            outcome: CLIENT_TOOL_OUTCOMES.ok,
            text: '',
            details: {
                route: location.route,
                visible: this.adapters.isVisible(),
                width: picture.width,
                height: picture.height,
                bytes: picture.byteSize,
                renderMode,
            },
            pictureBase64: picture.base64,
        };
    }
    readScreen(parameters) {
        const focus = isClientToolToken(parameters.focus) ? parameters.focus : undefined;
        const snapshot = this.adapters.reader.read({ focus, maxChars: MAX_SCREEN_CHARS });
        return {
            outcome: CLIENT_TOOL_OUTCOMES.ok,
            text: snapshot.markdown,
            details: { route: snapshot.route, visible: this.adapters.isVisible(), chars: snapshot.markdown.length },
        };
    }
    listTargets(parameters) {
        const searchText = typeof parameters.searchText === 'string' ? parameters.searchText : '';
        return {
            outcome: CLIENT_TOOL_OUTCOMES.ok,
            text: formatScreenTargetList(this.adapters.targets.listTargets(), searchText),
            details: {},
        };
    }
    async openTarget(parameters) {
        const target = this.findTarget(parameters.target);
        if (!target)
            return refusal(CLIENT_TOOL_OUTCOMES.unknownTarget);
        const tab = isClientToolToken(parameters.tab) ? parameters.tab : null;
        if (tab !== null && !target.tabs.includes(tab))
            return refusal(CLIENT_TOOL_OUTCOMES.unknownTab);
        const filters = {};
        // a value its filter does not take is left out and named in the answer: the screen opens without that filter,
        // never with a list that is silently empty
        const filtersNotSet = [];
        // a filter of a tab is taken with that tab only
        const filtersOfTab = screenTargetFiltersOfTab(target, tab);
        for (const filter of Array.isArray(parameters.filters) ? parameters.filters : []) {
            const [key, ...valueParts] = String(filter).split('=');
            const targetFilter = filtersOfTab.find(candidate => candidate.key === key);
            if (!targetFilter || valueParts.length === 0) {
                return refusal(CLIENT_TOOL_OUTCOMES.unknownFilter);
            }
            const value = valueParts.join('=');
            if (findUnknownScreenFilterValue(targetFilter, value) !== null) {
                filtersNotSet.push(describeUnknownScreenFilterValue(targetFilter, value));
                continue;
            }
            filters[key] = value;
        }
        const recordIds = typeof parameters.recordId === 'number' ? [parameters.recordId] : [];
        if (buildTargetRoute(target, recordIds, tab) === null)
            return refusal(CLIENT_TOOL_OUTCOMES.missingParameter);
        const blocker = this.adapters.navigator.describeBlockers();
        if (blocker)
            return { outcome: blocker.outcome, text: blocker.reason, details: {} };
        await this.stepAgentWindowAside('OPEN_TARGET');
        const openRequest = { recordIds, tab, filters };
        try {
            this.adapters.pathGuide?.guide(target, openRequest);
        }
        catch {
            // a guide is decoration: the navigation goes on without it
        }
        const location = await this.adapters.navigator.open(target, openRequest);
        const details = { route: location.route, title: location.title };
        if (filtersNotSet.length > 0)
            details.filtersNotSet = filtersNotSet.join('; ');
        if (parameters.includeScreen !== true)
            return { outcome: CLIENT_TOOL_OUTCOMES.ok, text: '', details };
        const snapshot = this.adapters.reader.read({ maxChars: MAX_SCREEN_CHARS });
        return { outcome: CLIENT_TOOL_OUTCOMES.ok, text: snapshot.markdown, details };
    }
    /**
     * A typed action on the open screen: `setFilters` through the navigator (the filters the screen's target declares),
     * every other kind through the actor on an element the screen declares for it. With `includeScreen` the answer
     * carries the screen after the action.
     */
    async actOnScreen(parameters) {
        const action = SCREEN_ACTION_KINDS.find(kind => kind === parameters.action);
        if (!action)
            return refusal(CLIENT_TOOL_OUTCOMES.unknownAction);
        const answer = action === 'setFilters' ? await this.setFilters(parameters) : await this.actOnElement(action, parameters);
        if (answer.outcome !== CLIENT_TOOL_OUTCOMES.ok || parameters.includeScreen !== true)
            return answer;
        const snapshot = this.adapters.reader.read({ maxChars: MAX_SCREEN_CHARS });
        return { ...answer, text: `${answer.text}\n${snapshot.markdown}` };
    }
    async actOnElement(action, parameters) {
        const actor = this.adapters.actions;
        if (!actor)
            return refusal(CLIENT_TOOL_OUTCOMES.actionsUnavailable);
        if (!isClientToolToken(parameters.reference))
            return refusal(CLIENT_TOOL_OUTCOMES.missingParameter);
        const direction = SCREEN_SORT_DIRECTIONS.find(candidate => candidate === parameters.direction) ?? null;
        await this.stepAgentWindowAside('ACT_ON_SCREEN');
        return actor.act({ action, reference: parameters.reference, direction });
    }
    /** Sets and clears URL filters of the open screen: only keys its target declares, the rest of the URL stays. */
    async setFilters(parameters) {
        const navigator = this.adapters.navigator;
        if (!navigator.currentTarget || !navigator.applyFilters)
            return refusal(CLIENT_TOOL_OUTCOMES.actionsUnavailable);
        const target = navigator.currentTarget();
        if (!target || target.filters.length === 0)
            return refusal(CLIENT_TOOL_OUTCOMES.noFilters);
        const entries = (Array.isArray(parameters.filters) ? parameters.filters : []).slice(0, MAX_ACTION_FILTERS);
        if (entries.length === 0)
            return refusal(CLIENT_TOOL_OUTCOMES.missingParameter);
        const filters = {};
        for (const entry of entries) {
            const [key, ...valueParts] = String(entry).split('=');
            const targetFilter = target.filters.find(candidate => candidate.key === key);
            if (!targetFilter || valueParts.length === 0) {
                return { outcome: CLIENT_TOOL_OUTCOMES.unknownFilter, text: `Filters of this screen: ${target.filters.map(filter => filter.key).join(', ')}.`, details: {} };
            }
            const value = valueParts.join('=').slice(0, MAX_FILTER_VALUE_CHARS);
            if (findUnknownScreenFilterValue(targetFilter, value) !== null) {
                return { outcome: CLIENT_TOOL_OUTCOMES.unknownFilterValue, text: `Not a value of this filter: ${describeUnknownScreenFilterValue(targetFilter, value)}.`, details: {} };
            }
            filters[key] = value;
        }
        const blocker = navigator.describeBlockers();
        if (blocker)
            return { outcome: blocker.outcome, text: blocker.reason, details: {} };
        await this.stepAgentWindowAside('ACT_ON_SCREEN');
        const location = await navigator.applyFilters(target, filters);
        const changes = Object.entries(filters).map(([key, value]) => (value === '' ? `${key} cleared` : `${key}=${value}`));
        return {
            outcome: CLIENT_TOOL_OUTCOMES.ok,
            text: `Filters of ${target.key}: ${changes.join(', ')}.`,
            details: { route: location.route, title: location.title },
        };
    }
    highlight(parameters) {
        const references = (Array.isArray(parameters.references) ? parameters.references : []).filter(isClientToolToken);
        const note = typeof parameters.note === 'string' && parameters.note.trim() !== '' ? parameters.note : null;
        const result = this.adapters.highlighter.mark(references, note);
        const markedReferences = Object.keys(result.marked);
        const lines = markedReferences.map(reference => {
            const suffix = result.outOfView.includes(reference)
                ? ' (scrolled into view)'
                : result.collapsed.includes(reference)
                    ? ' (inside a closed section: its header is marked)'
                    : '';
            return `Marked ${reference} "${result.marked[reference]}"${suffix}.`;
        });
        if (result.missing.length > 0)
            lines.push(`Not on the screen: ${result.missing.join(', ')}.`);
        const outcome = markedReferences.length > 0 ? CLIENT_TOOL_OUTCOMES.ok : CLIENT_TOOL_OUTCOMES.notOnScreen;
        return { outcome, text: lines.join('\n'), details: { marked: markedReferences.length } };
    }
    findTarget(key) {
        if (!isClientToolToken(key))
            return null;
        return this.adapters.targets.listTargets().find(target => target.key === key) ?? null;
    }
}
const refusal = (outcome) => ({ outcome, text: '', details: {} });
