/**
 * The client half of the agent's client tools: a tool on the
 * server sends a request to the client (a browser tab, an app) that shows the conversation; the client executes it through the
 * application's adapters and posts the answer. Everything here is generic: no menu, no route, no word of one
 * application. The application supplies the four adapters (`ScreenAdapters`).
 *
 * Pure (no React, no app imports), so the logic tests cover it.
 */
/** A tab never starts a request with fewer seconds left: it would finish after the tool gave up. */
export const MIN_SECONDS_LEFT_TO_START = 3;
/** The most characters of an answer text (the server refuses more). */
export const MAX_ANSWER_TEXT_CHARS = 24000;
/** The refusal codes a tab answers with. */
export const CLIENT_TOOL_OUTCOMES = {
    ok: 'OK',
    unsavedChanges: 'UNSAVED_CHANGES',
    unsavedStateUnknown: 'UNSAVED_STATE_UNKNOWN',
    dialogOpen: 'DIALOG_OPEN',
    unknownTarget: 'UNKNOWN_TARGET',
    missingParameter: 'MISSING_PARAMETER',
    unknownTab: 'UNKNOWN_TAB',
    unknownFilter: 'UNKNOWN_FILTER',
    notOnScreen: 'NOT_ON_SCREEN',
    expiredBeforeStart: 'EXPIRED_BEFORE_START',
    readFailed: 'READ_FAILED',
    unknownRequest: 'UNKNOWN_REQUEST',
    unknownMode: 'UNKNOWN_MODE',
    pictureUnavailable: 'PICTURE_UNAVAILABLE',
    actionsUnavailable: 'ACTIONS_UNAVAILABLE',
    unknownAction: 'UNKNOWN_ACTION',
    noFilters: 'NO_FILTERS',
    notDeclared: 'NOT_DECLARED',
    writeAction: 'WRITE_ACTION',
    disabled: 'DISABLED',
    noEffect: 'NO_EFFECT',
    unknownFilterValue: 'UNKNOWN_FILTER_VALUE',
};
export const SCREEN_ACTION_KINDS = ['setFilters', 'sort', 'openRow', 'switchTab', 'press'];
export const SCREEN_SORT_DIRECTIONS = ['asc', 'desc', 'none'];
// ── Helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────────
/** The arguments of a delivery as an object; anything else is empty. */
export const parseClientToolArguments = (argumentsJson) => {
    try {
        const parsed = JSON.parse(argumentsJson);
        return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed) ? parsed : {};
    }
    catch {
        return {};
    }
};
/** A text cut to the answer's cap. */
export const capAnswerText = (text) => text.length <= MAX_ANSWER_TEXT_CHARS ? text : `${text.slice(0, MAX_ANSWER_TEXT_CHARS - 1)}…`;
/** Lower-case words of a search text. */
const searchWordsOf = (searchText) => searchText
    .toLowerCase()
    .split(/\s+/)
    .filter(word => word !== '');
/** One line of a target in the `listScreens` answer. */
export const formatScreenTargetLine = (target) => {
    const parts = [target.key, target.group ? `${target.group} › ${target.label}` : target.label];
    if (target.parameters.length > 0)
        parts[1] = `${parts[1]} (${target.parameters.join(', ')})`;
    if (target.tabs.length > 0)
        parts.push(`tabs ${target.tabs.join(', ')}`);
    // the filters of every page by key; the tabs with filters of their own by name (a narrowed list names those filters)
    const filtersOfEveryPage = target.filters.filter(filter => filter.tabs === undefined);
    if (filtersOfEveryPage.length > 0)
        parts.push(`filters ${filtersOfEveryPage.map(filter => filter.key).join(', ')}`);
    const tabsWithFilters = [...new Set(target.filters.flatMap(filter => filter.tabs ?? []))];
    if (tabsWithFilters.length > 0)
        parts.push(`tabs with filters ${tabsWithFilters.join(', ')}`);
    if (target.views.length > 0)
        parts.push(`views ${target.views.join(', ')}`);
    return `- ${parts.join(' · ')}`;
};
/** The most values of one filter a description names. */
export const MAX_DESCRIBED_FILTER_VALUES = 24;
/** Whether a filter says more than its key: values or a hint. */
export const isDescribedScreenTargetFilter = (filter) => (filter.values !== undefined && filter.values.length > 0) || (filter.hint !== undefined && filter.hint !== '');
/**
 * The values of a filter as one phrase. A sort (every value `<key>:asc` or `<key>:desc`, both of each key) names its
 * keys once: `one of id|created, each with :asc or :desc`.
 */
export const describeScreenFilterValues = (values, multiple) => {
    const sortKeys = [...new Set(values.map(value => value.replace(/:(asc|desc)$/, '')))];
    const isSort = values.length === sortKeys.length * 2 && sortKeys.every(key => values.includes(`${key}:asc`) && values.includes(`${key}:desc`));
    const named = isSort ? sortKeys : values;
    const list = `${named.slice(0, MAX_DESCRIBED_FILTER_VALUES).join('|')}${named.length > MAX_DESCRIBED_FILTER_VALUES ? '|…' : ''}`;
    if (isSort)
        return `one of ${list}, each with :asc or :desc`;
    return multiple ? `one or more of ${list}, comma-separated` : `one of ${list}`;
};
/** A filter with what its value is: `status one or more of OPEN|SOLVED, comma-separated`, `from date YYYY-MM-DD`. */
export const describeScreenTargetFilter = (filter) => {
    const parts = [filter.key];
    if (filter.values !== undefined && filter.values.length > 0) {
        parts.push(describeScreenFilterValues(filter.values, filter.multiple === true));
    }
    if (filter.hint !== undefined && filter.hint !== '')
        parts.push(filter.values && filter.values.length > 0 ? `(${filter.hint})` : filter.hint);
    return parts.join(' ');
};
/**
 * The filters of a target on one of its tabs (`null`: its own page): those of every page and those of that tab; a
 * filter declared for the tab replaces the one of every page with the same key.
 */
export const screenTargetFiltersOfTab = (target, tab) => {
    const ofTab = tab === null ? [] : target.filters.filter(filter => filter.tabs?.includes(tab));
    const keysOfTab = new Set(ofTab.map(filter => filter.key));
    return [...target.filters.filter(filter => filter.tabs === undefined && !keysOfTab.has(filter.key)), ...ofTab];
};
/**
 * The lines below a target that name the values of its filters, one per tab that has filters of its own (a filter of
 * a tab is passed with that tab); `null` when no filter says more than its key.
 */
export const formatScreenTargetFilterValues = (target) => {
    const lines = [];
    const ofEveryPage = target.filters.filter(filter => filter.tabs === undefined);
    if (ofEveryPage.some(isDescribedScreenTargetFilter)) {
        lines.push(`  filter values: ${ofEveryPage.filter(isDescribedScreenTargetFilter).map(describeScreenTargetFilter).join(' · ')}`);
    }
    const tabs = [...new Set(target.filters.flatMap(filter => filter.tabs ?? []))];
    for (const tab of tabs) {
        const ofTab = target.filters.filter(filter => filter.tabs?.includes(tab));
        lines.push(`  filters of tab ${tab}: ${ofTab.map(describeScreenTargetFilter).join(' · ')}`);
    }
    return lines.length === 0 ? null : lines.join('\n');
};
/**
 * The part of a filter value its filter does not take (`null` when the value is fine): a filter without values takes
 * anything, an empty value clears a filter, a filter for several takes a comma list of its values.
 */
export const findUnknownScreenFilterValue = (filter, value) => {
    if (filter.values === undefined || filter.values.length === 0 || value === '')
        return null;
    const parts = filter.multiple ? value.split(',').map(part => part.trim()) : [value];
    return parts.find(part => !filter.values?.includes(part)) ?? null;
};
/** `status=DONE (status one of OPEN|SOLVED)`: a filter value that was not taken, with what the filter takes. */
export const describeUnknownScreenFilterValue = (filter, value) => `${filter.key}=${value.slice(0, 60)} (${describeScreenTargetFilter(filter)})`;
/** The most matching targets a `listScreens` answer names the filter values of. */
export const MAX_TARGETS_WITH_FILTER_VALUES = 4;
/** The most characters of a `listScreens` answer. */
export const MAX_TARGET_LIST_CHARS = 4500;
/**
 * The short line of a target, for a catalogue too long for full lines: its key and label (without the group, which
 * the full line names), how many tabs it has and whether it has filters or views.
 */
export const formatScreenTargetShortLine = (target) => {
    const parts = [target.key, target.parameters.length > 0 ? `${target.label} (${target.parameters.join(', ')})` : target.label];
    if (target.tabs.length > 0)
        parts.push(target.tabs.length === 1 ? '1 tab' : `${target.tabs.length} tabs`);
    if (target.filters.length > 0)
        parts.push('filters');
    if (target.views.length > 0)
        parts.push('views');
    return `- ${parts.join(' · ')}`;
};
/** Lines up to the answer's cap; the line that says how many were left out replaces the rest. */
const capTargetLines = (header, lines) => {
    const kept = [];
    let chars = header.length;
    for (const line of lines) {
        if (chars + line.length + 1 > MAX_TARGET_LIST_CHARS) {
            kept.push(`… ${lines.length - kept.length} more; narrow with searchText.`);
            break;
        }
        kept.push(line);
        chars += line.length + 1;
    }
    return [header, ...kept].join('\n');
};
/**
 * The `listScreens` answer: the targets whose line contains every word of the search text, capped. A screen names the
 * values of its filters when the search is narrowed to at most {@link MAX_TARGETS_WITH_FILTER_VALUES} screens, and
 * always when the search text is its key (it then comes first). A catalogue too long for full lines lists every
 * screen in a short line instead of leaving screens out.
 */
export const formatScreenTargetList = (targets, searchText) => {
    const searchWords = searchWordsOf(searchText);
    const searchedKey = searchWords.length === 1 ? searchWords[0] : null;
    const isSearchedKey = (target) => target.key.toLowerCase() === searchedKey;
    const matches = targets
        .map(target => ({ target, line: formatScreenTargetLine(target) }))
        .filter(match => searchWords.every(word => match.line.toLowerCase().includes(word)))
        .sort((first, second) => Number(isSearchedKey(second.target)) - Number(isSearchedKey(first.target)));
    if (matches.length === 0)
        return 'No screen matches. Call listScreens without searchText for all of them.';
    const isNarrowed = matches.length <= MAX_TARGETS_WITH_FILTER_VALUES;
    const withFilterValues = (target, line) => {
        const valuesLine = isNarrowed || isSearchedKey(target) ? formatScreenTargetFilterValues(target) : null;
        return valuesLine === null ? line : `${line}\n${valuesLine}`;
    };
    const hasDescribedFilters = matches.some(match => match.target.filters.some(filter => isDescribedScreenTargetFilter(filter) || filter.tabs !== undefined));
    const opening = `Screens this user can open (${matches.length}). Pass the key to openScreen.`;
    const fullHeader = opening + (hasDescribedFilters && !isNarrowed ? ' Filter values of a screen: listScreens with its key as searchText.' : '');
    const fullLines = matches.map(match => withFilterValues(match.target, match.line));
    const fullChars = fullLines.reduce((chars, line) => chars + line.length + 1, fullHeader.length);
    // a narrowed answer is what names the values: it keeps its full lines and is cut when they are too many
    if (isNarrowed || fullChars <= MAX_TARGET_LIST_CHARS)
        return capTargetLines(fullHeader, fullLines);
    const shortHeader = `${opening} Short lines, the list is long. Tabs, filters and filter values of a screen: listScreens with its key as searchText.`;
    const shortLines = matches.map(match => (isSearchedKey(match.target) ? withFilterValues(match.target, match.line) : formatScreenTargetShortLine(match.target)));
    return capTargetLines(shortHeader, shortLines);
};
/** A reference, a key, a tab or a filter key: what the server lets through. */
const TOKEN_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/;
export const isClientToolToken = (value) => typeof value === 'string' && TOKEN_PATTERN.test(value);
/** The route of a target for the given record ids and tab; `null` when a record id is missing. */
export const buildTargetRoute = (target, recordIds, tab) => {
    let parameterIndex = 0;
    const segments = [];
    for (const segment of target.routePattern.split('/').filter(part => part !== '')) {
        if (!segment.startsWith(':')) {
            segments.push(segment);
            continue;
        }
        const recordId = recordIds[parameterIndex];
        parameterIndex += 1;
        if (recordId === undefined || !Number.isInteger(recordId) || recordId <= 0)
            return null;
        segments.push(String(recordId));
    }
    if (tab)
        segments.push(tab);
    return `/${segments.join('/')}`;
};
