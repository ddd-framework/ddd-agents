import assert from 'node:assert/strict';
import { describe, it } from "vitest";
import { formatButtons, formatFieldLine, formatTable, joinScreenParts, ReferenceNames, slugOf } from "./screen-markdown";
describe('screen Markdown', () => {
    it('builds slugs and unique references', () => {
        assert.equal(slugOf('Änderungen speichern!'), 'anderungen-speichern');
        assert.equal(slugOf('   '), 'x');
        const names = new ReferenceNames();
        assert.deepEqual([names.take('b.save'), names.take('b.save'), names.take('b.save')], ['b.save', 'b.save-2', 'b.save-3']);
    });
    it('writes a field with its flags, never a secret value', () => {
        assert.equal(formatFieldLine({ reference: 'f.endDate', label: 'End date', value: '2026-12-31', kind: 'date', required: true, invalidMessage: 'Too early' }), '- End date (f.endDate, date): 2026-12-31 · required · error "Too early"');
        assert.equal(formatFieldLine({ reference: 'f.apiKey', label: 'API key', value: 'sk-123', kind: 'text', isSecret: true }), '- API key (f.apiKey, text): (hidden)');
        assert.match(formatFieldLine({ reference: 'f.text', label: 'Text', value: 'x'.repeat(500), kind: 'text area' }), /… \(500 characters\)$/);
    });
    it('writes a table with row references and caps the rows', () => {
        const rows = Array.from({ length: 30 }, (_, index) => ({ reference: `row.${index + 1}`, cells: [String(index + 1), `Order ${index + 1}`] }));
        const lines = formatTable({ caption: null, columns: ['ID', 'Name'], rows, totalRows: 30 }, 2);
        assert.deepEqual(lines, ['Table: 30 rows on screen', '| ref | ID | Name |', '| row.1 | 1 | Order 1 |', '| row.2 | 2 | Order 2 |', '… 28 more rows on screen']);
    });
    it('writes buttons and cuts a long screen at a line', () => {
        assert.equal(formatButtons([{ reference: 'b.save', label: 'Save', disabled: true }]), 'Buttons: b.save "Save" (disabled)');
        assert.equal(formatButtons([]), null);
        const text = joinScreenParts(Array.from({ length: 100 }, (_, index) => `line ${index}`), 200);
        assert.ok(text.length < 300);
        assert.match(text, /screen cut at 200 of \d+ characters/);
    });
});
