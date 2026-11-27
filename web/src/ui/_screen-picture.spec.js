import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, it, vi } from "vitest";
import { computePictureByteBudget, computePictureDimensions, computeRetryScale, convertPixelsToGrey, createFrameDefaultStyleSource, PICTURE_LIMITATIONS, prepareScreenCloneForPicture, SCREEN_PICTURE_PRESETS, } from "./screen-picture";
describe('computePictureDimensions', () => {
    it('keeps a small source at its size', () => {
        assert.deepEqual(computePictureDimensions(800, 600, { pictureSize: 'medium', renderMode: 'colour' }), { width: 800, height: 600, scale: 1 });
    });
    it('keeps the long edge and the pixels of every preset', () => {
        for (const pictureSize of ['small', 'medium', 'large']) {
            const preset = SCREEN_PICTURE_PRESETS[pictureSize];
            const dimensions = computePictureDimensions(1920, 6000, { pictureSize, renderMode: 'colour' });
            assert.ok(Math.max(dimensions.width, dimensions.height) <= preset.maxLongEdge, pictureSize);
            assert.ok(dimensions.width * dimensions.height <= preset.maxPixels, pictureSize);
        }
    });
    it('takes 75 % of the size in the machine mode', () => {
        const colour = computePictureDimensions(1600, 1000, { pictureSize: 'medium', renderMode: 'colour' });
        const machine = computePictureDimensions(1600, 1000, { pictureSize: 'medium', renderMode: 'machine' });
        assert.equal(machine.width, Math.floor(colour.width * 0.75));
    });
    it('applies a retry factor to the source size', () => {
        const first = computePictureDimensions(1000, 1000, { pictureSize: 'large', renderMode: 'colour' });
        const retry = computePictureDimensions(1000, 1000, { pictureSize: 'large', renderMode: 'colour' }, 0.5);
        assert.equal(retry.width, Math.floor(first.width * 0.5));
    });
});
describe('budgets and retries', () => {
    it('halves the byte budget in the machine mode and stays below the server cap', () => {
        assert.equal(computePictureByteBudget('medium', 'machine'), SCREEN_PICTURE_PRESETS.medium.maxBytes / 2);
        assert.ok(computePictureByteBudget('large', 'colour') <= 614400);
    });
    it('shrinks the scale with the square root of the overshoot, at least by a tenth', () => {
        assert.ok(Math.abs(computeRetryScale(1, 400000, 100000) - 0.45) < 1e-9);
        assert.ok(computeRetryScale(1, 101000, 100000) <= 0.9);
        assert.equal(computeRetryScale(1, 100500, 100000), 0.9 * Math.sqrt(100000 / 100500));
    });
});
describe('convertPixelsToGrey', () => {
    it('turns colours grey and quantizes to the given levels', () => {
        const pixels = new Uint8ClampedArray([255, 0, 0, 255, 10, 200, 30, 255]);
        convertPixelsToGrey(pixels, 64);
        assert.equal(pixels[0], pixels[1]);
        assert.equal(pixels[1], pixels[2]);
        const levels = new Set(Array.from({ length: 64 }, (_, level) => Math.round((level * 255) / 63)));
        assert.ok(levels.has(pixels[4]));
        assert.equal(pixels[3], 255);
    });
});
describe('prepareScreenCloneForPicture', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });
    it('writes passwords as dots, leaves skipped elements out and blanks secret ones', () => {
        document.body.innerHTML =
            '<main><input type="password" id="pw"><input id="name"><div data-agent-skip>chat</div><p data-agent-secret>IBAN DE00</p><script>x()</script></main>';
        document.getElementById('pw').value = 'hunter2';
        document.getElementById('name').value = 'Ada';
        const clone = prepareScreenCloneForPicture(document.body, false);
        const html = clone.outerHTML;
        assert.ok(html.includes('value="•••••••"'));
        assert.ok(!html.includes('hunter2'));
        assert.ok(html.includes('value="Ada"'));
        assert.ok(!html.includes('chat'));
        assert.ok(!html.includes('IBAN'));
        assert.ok(!html.includes('x()'));
    });
    it('expands a scroll container when the whole page is copied, keeps it in the window part', () => {
        document.body.innerHTML = '<div id="list" style="height:100px;overflow-y:auto"><p>row</p></div>';
        const list = document.getElementById('list');
        Object.defineProperty(list, 'scrollHeight', { value: 900 });
        Object.defineProperty(list, 'clientHeight', { value: 100 });
        const pageClone = prepareScreenCloneForPicture(document.body, { copyStyles: false, visibleBox: null });
        const listClone = pageClone.querySelector('#list');
        assert.equal(listClone.style.overflowY, 'visible');
        assert.equal(listClone.style.height, 'auto');
        const windowClone = prepareScreenCloneForPicture(document.body, false);
        assert.equal(windowClone.querySelector('#list').style.overflowY, 'auto');
    });
    /** Places each `[data-top]` element at its top, 30 px high and 800 px wide (jsdom lays nothing out). */
    const placeRows = () => {
        for (const element of Array.from(document.querySelectorAll('[data-top]'))) {
            const top = Number(element.dataset.top);
            const height = Number(element.dataset.height ?? 30);
            element.getBoundingClientRect = () => ({ top, bottom: top + height, left: 0, right: 800, width: 800, height, x: 0, y: top, toJSON: () => ({}) });
        }
    };
    it('copies only the rows in the window and in the visible part of their scroll container', () => {
        const rows = Array.from({ length: 200 }, (_, index) => `<tr data-top="${100 + index * 30}"><td>Ticket ${index + 1}</td></tr>`).join('');
        document.body.innerHTML =
            `<table data-top="100" data-height="6000"><tbody data-top="100" data-height="6000">${rows}</tbody></table>` +
                '<div id="scroller" data-top="0" data-height="200" style="overflow-y:auto"><p data-top="50">in view</p><p data-top="400">clipped</p></div>';
        placeRows();
        const clone = prepareScreenCloneForPicture(document.body, { copyStyles: false, visibleBox: { top: 0, left: 0, bottom: 700, right: 1024 } });
        // rows 1 to 20 start above 700 (the 20th at 670)
        assert.equal(clone.querySelectorAll('tr').length, 20);
        assert.ok(clone.textContent?.includes('Ticket 20'));
        assert.ok(!clone.textContent?.includes('Ticket 21'));
        assert.ok(clone.textContent?.includes('in view'));
        assert.ok(!clone.textContent?.includes('clipped'));
    });
    it('never copies the height of a table part: the copied rows keep their own height', () => {
        document.body.innerHTML =
            '<table id="table" style="height:17769px"><tbody id="body" style="height:17721px"><tr id="row" style="height:30px"><td>Ticket</td></tr></tbody></table>' +
                '<div id="box" style="height:40px">box</div>';
        const clone = prepareScreenCloneForPicture(document.body, { visibleBox: null, defaultStyles: null });
        const styleOf = (id) => clone.querySelector(`#${id}`)?.getAttribute('style') ?? '';
        for (const id of ['table', 'body', 'row'])
            assert.ok(!/(^|;)\s*height:/.test(styleOf(id)), `${id}: ${styleOf(id)}`);
        assert.match(styleOf('box'), /(^|;)\s*height:\s*40px/);
    });
    it('never copies the width of a table part: the copied columns keep their own width', () => {
        document.body.innerHTML =
            '<table id="table" style="width:2637px"><thead id="head" style="width:2637px"><tr id="row" style="width:2637px">' +
                '<th id="status" style="width:217px">Status</th></tr></thead></table>';
        const clone = prepareScreenCloneForPicture(document.body, { visibleBox: null, defaultStyles: null });
        const styleOf = (id) => clone.querySelector(`#${id}`)?.getAttribute('style') ?? '';
        for (const id of ['table', 'head', 'row'])
            assert.ok(!/(^|;)\s*(width|inline-size):/.test(styleOf(id)), `${id}: ${styleOf(id)}`);
        assert.match(styleOf('status'), /(^|;)\s*width:\s*217px/);
    });
    it('copies a declaration only where it differs from the tag default or the parent: the copy stays within elements × bytes', () => {
        const elementCount = 200;
        document.body.innerHTML = `<div id="list">${Array.from({ length: elementCount - 1 }, (_, index) => `<p class="${index % 2 === 0 ? 'even' : 'odd'}">row ${index}</p>`).join('')}</div>`;
        // a browser's computed style: ~350 properties, nearly all at their default
        const propertyNames = Array.from({ length: 350 }, (_, index) => `property-${index}`);
        const fakeStyleOf = (element) => {
            const values = new Map(propertyNames.map(name => [name, 'initial-value']));
            values.set('display', 'block');
            values.set('color', element.classList.contains('odd') ? 'rgb(200, 0, 0)' : 'rgb(0, 0, 0)');
            values.set('padding-left', '12px');
            // a page's design tokens: inherited by every element, never needed by a copy of resolved values
            for (let index = 0; index < 50; index += 1)
                values.set(`--tw-token-${index}`, `token value ${index}`);
            const names = [...values.keys()];
            return {
                length: names.length,
                display: 'block',
                overflowX: 'visible',
                overflowY: 'visible',
                position: 'static',
                item: (index) => names[index],
                getPropertyValue: (name) => values.get(name) ?? '',
            };
        };
        const getComputedStyle = vi.spyOn(window, 'getComputedStyle').mockImplementation(element => fakeStyleOf(element));
        const defaultStyles = { valueOf: (_tag, _namespace, name) => (name === 'display' ? 'block' : name === 'color' ? 'rgb(0, 0, 0)' : 'initial-value'), dispose: () => undefined };
        try {
            const diffed = new XMLSerializer().serializeToString(prepareScreenCloneForPicture(document.getElementById('list'), { visibleBox: null, defaultStyles }));
            const full = new XMLSerializer().serializeToString(prepareScreenCloneForPicture(document.getElementById('list'), { visibleBox: null, defaultStyles: null }));
            assert.ok(diffed.length <= elementCount * 120, `diffed copy ${diffed.length} bytes`);
            assert.ok(full.length >= elementCount * 5000, `full copy ${full.length} bytes`);
            // the red rows carry their colour; a black row under the default parent carries none
            assert.match(diffed, /<p class="odd" style="[^"]*color:rgb\(200, 0, 0\)/);
            assert.ok(!/<p class="even" style="[^"]*color/.test(diffed));
            assert.ok(!diffed.includes('--tw-token'));
        }
        finally {
            getComputedStyle.mockRestore();
        }
    });
    it('copies a border width that matches the default: it computes to 0 only under border-style none', () => {
        document.body.innerHTML = '<div id="cell" style="border-style:solid;border-top-width:0px">cell</div>';
        const defaultStyles = { valueOf: (_tag, _namespace, name) => (name === 'border-top-width' ? '0px' : name === 'display' ? 'block' : ''), dispose: () => undefined };
        const clone = prepareScreenCloneForPicture(document.body, { visibleBox: null, defaultStyles });
        assert.match(clone.querySelector('#cell')?.getAttribute('style') ?? '', /border-top-width:\s*0px/);
    });
    it('reads the browser defaults of a tag from an empty frame', () => {
        document.head.insertAdjacentHTML('beforeend', '<style id="page-style">div { display: inline; }</style>');
        const defaults = createFrameDefaultStyleSource(document);
        try {
            assert.equal(defaults.valueOf('div', null, 'display'), 'block');
        }
        finally {
            defaults.dispose();
            document.getElementById('page-style')?.remove();
        }
        assert.equal(document.querySelectorAll('iframe').length, 0);
    });
});
describe('PICTURE_LIMITATIONS', () => {
    it('names the same sentences as the server', () => {
        const packageFile = resolve(dirname(fileURLToPath(import.meta.url)), '../../../src/Presentation/Mcp/Client/Agents/AgentUIMCPPackage.php');
        const phpSource = readFileSync(packageFile, 'utf8');
        const block = /PICTURE_LIMITATIONS = \[([\s\S]*?)\];/.exec(phpSource)?.[1] ?? '';
        const serverSentences = Array.from(block.matchAll(/'((?:[^'\\]|\\.)*)'/g), match => match[1].replace(/\\'/g, "'"));
        assert.deepEqual(serverSentences, [...PICTURE_LIMITATIONS]);
    });
});
