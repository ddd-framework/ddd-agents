/**
 * The pure half of the screen reader: references and the Markdown lines of fields, tables, buttons and messages.
 * The DOM walk (`domScreenReader.ts`) collects plain values and these functions write them, so the logic tests cover
 * the format without a DOM.
 *
 * References (stable within one read, `highlightOnScreen` takes them):
 * - `f.<name>` a form field (its `name`, else its label as a slug)
 * - `row.<key>` a table row (the row key: `data-row-key`, else the visible id cell)
 * - `b.<slug>` a button or link that acts
 * - `s.<slug>` a section (its heading)
 * - `t.<slug>` a tab
 * - `a.<anchor>` an anchor the application set (`data-agent-anchor`)
 * - `nav.<key>` an entry or group of the application's main menu (`data-nav-key`, `data-nav-group`)
 * - `col.<key>` a sortable column of a table (`data-agent-action="sort"`), for the action `sort`
 * - `act.<key>` a button the screen declares for the agent (`data-agent-action="press"` with the effect `view`)
 */
/**
 * The most rows of one table (and cards of one grid) in a read. A list of 200 loaded rows filled the whole read with
 * rows before; the rest of the page never reached the model. `focus` on a row reads it whole.
 */
export const MAX_TABLE_ROWS = 15;
/** The most characters of one table cell in a read (a cell says how long it was; `focus` on the row reads it whole). */
export const MAX_CELL_CHARS = 60;
/** The most characters of the plain text of one section (`Text: …`). */
export const MAX_SECTION_TEXT_CHARS = 1200;
/** The most characters of one value in a read (a long text says how long it is; `focus` reads it whole). */
export const MAX_VALUE_CHARS = 200;
/** The most buttons in a read. */
export const MAX_BUTTONS = 30;
/** A value standing in for a secret. */
export const HIDDEN_VALUE = '(hidden)';
/** A slug for a reference: lower case letters, digits and `-`, at most 40 characters. */
export const slugOf = (text) => text
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40)
    .replace(/-+$/g, '') || 'x';
/** A reference name from a field name (`order.endDate` stays, other characters become `-`). */
export const fieldReferenceNameOf = (name) => name.replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^[^A-Za-z0-9]+/, '').slice(0, 60) || 'x';
/** One line of text: whitespace collapsed. */
export const oneLine = (text) => text.replace(/\s+/g, ' ').trim();
/** A value cut to {@link MAX_VALUE_CHARS}, saying how long it was. */
export const capValue = (value, maxChars = MAX_VALUE_CHARS) => {
    const flat = oneLine(value);
    return flat.length <= maxChars ? flat : `${flat.slice(0, maxChars)}… (${flat.length} characters)`;
};
/** Makes a reference unique within one read by a number suffix. */
export class ReferenceNames {
    used = new Set();
    take(reference) {
        let candidate = reference;
        for (let suffix = 2; this.used.has(candidate); suffix += 1)
            candidate = `${reference}-${suffix}`;
        this.used.add(candidate);
        return candidate;
    }
}
/** `- End date (f.endDate, date): 2026-12-31 · required · error "…"` */
export const formatFieldLine = (field) => {
    const value = field.isSecret ? HIDDEN_VALUE : field.value.trim() === '' ? '(empty)' : capValue(field.value);
    const flags = [];
    if (field.required)
        flags.push('required');
    if (field.disabled)
        flags.push('read-only');
    if (field.invalidMessage)
        flags.push(`error "${capValue(field.invalidMessage, 120)}"`);
    return `- ${capValue(field.label, 80) || field.reference} (${field.reference}, ${field.kind}): ${value}${flags.length > 0 ? ` · ${flags.join(' · ')}` : ''}`;
};
/** The line that tells the agent the rows (or cards) of a list open with the action `openRow`. */
export const ROWS_OPEN_LINE = 'Rows open with actOnScreen (action openRow, reference row.<key>).';
/** `Sort (actOnScreen, action sort): col.created "Created" (desc) · col.id "ID"` */
export const formatSortColumns = (sortColumns) => {
    if (sortColumns.length === 0)
        return null;
    const shown = sortColumns.map(column => `${column.reference} "${capValue(column.label, 40)}"${column.direction ? ` (${column.direction})` : ''}`);
    return `Sort (actOnScreen, action sort): ${shown.join(' · ')}`;
};
/**
 * A table as `| … |` lines with the row reference first, at most {@link MAX_TABLE_ROWS} rows, cells cut to
 * {@link MAX_CELL_CHARS}. A column without text in every shown row (a selection box, an actions menu) is left out: the
 * selection shows as `(selected)` on the row.
 */
export const formatTable = (table, maxRows = MAX_TABLE_ROWS) => {
    const lines = [];
    const shownRows = table.rows.slice(0, maxRows);
    const columnCount = Math.max(table.columns.length, ...shownRows.map(row => row.cells.length));
    const keptColumns = [];
    for (let column = 0; column < columnCount; column += 1) {
        if (shownRows.length === 0 || shownRows.some(row => (row.cells[column] ?? '').trim() !== ''))
            keptColumns.push(column);
    }
    lines.push(`Table${table.caption ? ` "${capValue(table.caption, 80)}"` : ''}: ${table.totalRows} rows on screen`);
    lines.push(`| ref | ${keptColumns.map(column => capValue(table.columns[column] ?? '', 40) || '-').join(' | ')} |`);
    for (const row of shownRows) {
        const cells = keptColumns.map(column => capValue(row.cells[column] ?? '', MAX_CELL_CHARS).replace(/\|/g, '/'));
        lines.push(`| ${row.reference}${row.selected ? ' (selected)' : ''} | ${cells.join(' | ')} |`);
    }
    if (table.totalRows > shownRows.length)
        lines.push(`… ${table.totalRows - shownRows.length} more rows on screen`);
    const sortLine = formatSortColumns(table.sortColumns ?? []);
    if (sortLine)
        lines.push(sortLine);
    if (table.rowsOpen && shownRows.length > 0)
        lines.push(ROWS_OPEN_LINE);
    return lines;
};
/** `Buttons: b.save "Save" · b.delete "Delete" (disabled)` */
export const formatButtons = (buttons) => {
    if (buttons.length === 0)
        return null;
    const shown = buttons.slice(0, MAX_BUTTONS).map(button => `${button.reference} "${capValue(button.label, 40)}"${button.disabled ? ' (disabled)' : ''}`);
    const more = buttons.length > MAX_BUTTONS ? ` · … ${buttons.length - MAX_BUTTONS} more` : '';
    return `Buttons: ${shown.join(' · ')}${more}`;
};
/** `Actions (actOnScreen, action press): act.load-more "Load more" · act.clear-filters "Clear filters" (disabled)` */
export const formatActions = (actions) => {
    if (actions.length === 0)
        return null;
    const shown = actions.slice(0, MAX_BUTTONS).map(action => `${action.reference} "${capValue(action.label, 40)}"${action.disabled ? ' (disabled)' : ''}`);
    return `Actions (actOnScreen, action press): ${shown.join(' · ')}`;
};
/** Joins the parts of a read and cuts the whole at `maxChars`, saying so. */
export const joinScreenParts = (parts, maxChars) => {
    const text = parts.filter(part => part !== '').join('\n');
    if (text.length <= maxChars)
        return text;
    const cut = text.slice(0, maxChars);
    const lastLineBreak = cut.lastIndexOf('\n');
    return `${lastLineBreak > maxChars * 0.8 ? cut.slice(0, lastLineBreak) : cut}\n… (screen cut at ${maxChars} of ${text.length} characters; read one part with focus)`;
};
