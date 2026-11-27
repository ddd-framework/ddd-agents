import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { DomScreenReader, MAIN_MENU_HEADING, ScreenReferenceRegistry } from "./DomScreenReader";
import { MAX_TABLE_ROWS } from "./screen-markdown";
/**
 * The DOM read of a screen: a list's table comes before the page's fields and is capped, a row's selection checkbox
 * is no field of its own, a section's plain text is read once, the main menu is read as `nav.` references and is
 * never cut away, the read names the agent window and the browser size. jsdom lays nothing out: every element counts
 * as rendered unless it is hidden or skipped.
 */
const createReader = (options = {}) => {
    const registry = new ScreenReferenceRegistry();
    const reader = new DomScreenReader({
        findRoot: () => document.querySelector("main"),
        currentLocation: () => ({ route: "/support-tickets", title: "Support-Center" }),
        describeUnsavedChanges: () => "no",
        registry,
        ...options,
    });
    return { reader, registry };
};
const ticketRows = (count) => Array.from({ length: count }, (_, index) => `<tr data-row-key="${13000 + index}"><td><input type="checkbox" aria-label="Ticket ${13000 + index} auswählen"${index === 1 ? " checked" : ""}></td>` +
    `<td>${13000 + index}</td><td>Fahrt fehlt ${index}</td><td></td></tr>`).join("");
describe("DomScreenReader", () => {
    beforeEach(() => {
        vi.spyOn(Element.prototype, "getClientRects").mockImplementation(() => [{}]);
    });
    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = "";
    });
    it("reads a list's table before its filter fields, at most the row cap, the row checkboxes as selection only", () => {
        document.body.innerHTML =
            '<main><h1>Support-Center</h1><input name="search" aria-label="Suche">' +
                `<table><thead><tr><th></th><th>ID</th><th>Titel</th><th>Aktionen</th></tr></thead><tbody>${ticketRows(200)}</tbody></table></main>`;
        const { reader } = createReader();
        const markdown = reader.read({ maxChars: 12000 }).markdown;
        expect(markdown).not.toContain("auswählen");
        expect(markdown.indexOf("Table: 200 rows on screen")).toBeLessThan(markdown.indexOf("(f.search"));
        expect(markdown).toContain("| ref | ID | Titel |");
        expect(markdown).toContain("| row.13001 (selected) | 13001 | Fahrt fehlt 1 |");
        expect(markdown).toContain(`… ${200 - MAX_TABLE_ROWS} more rows on screen`);
        expect(markdown.match(/^\| row\./gm)?.length).toBe(MAX_TABLE_ROWS);
    });
    it("reads the plain text of a section once, without the text of its fields, buttons and headings", () => {
        document.body.innerHTML =
            '<main><section><h2>Nachricht</h2><h3>Verlauf</h3><p>Die Kundin <b>schreibt</b>: Fahrt fehlt.</p><p>Seit 3. 11.</p>' +
                '<label>Antwort <textarea name="reply"></textarea></label><button>Senden</button></section></main>';
        const { reader } = createReader();
        const markdown = reader.read({ maxChars: 12000 }).markdown;
        expect(markdown.match(/^Text: /gm)?.length).toBe(1);
        expect(markdown).toContain("Text: Die Kundin schreibt : Fahrt fehlt. · Seit 3. 11.");
        expect(markdown).not.toMatch(/^Text: .*(Senden|Antwort|Verlauf)/m);
    });
    it("reads the main menu as nav. references the highlighter finds, and never cuts it away", () => {
        document.body.innerHTML =
            '<nav id="menu"><a href="/" data-nav-key="home" aria-current="page">Startseite</a>' +
                '<a href="/support-tickets" data-nav-key="support-tickets" aria-label="Support-Center"><svg></svg></a>' +
                '<li data-nav-group="settings"><button>Einstellungen</button><ul hidden><li><a href="/texts" data-nav-key="texts">Texte</a></li></ul></li></nav>' +
                `<main><h1>Support-Center</h1><p>${"lang ".repeat(400)}</p><table><thead><tr><th>ID</th></tr></thead><tbody>${ticketRows(40)}</tbody></table></main>`;
        const { reader, registry } = createReader({ findNavigation: () => document.getElementById("menu") });
        const snapshot = reader.read({ maxChars: 2000 });
        expect(snapshot.markdown.length).toBeLessThanOrEqual(2100);
        expect(snapshot.markdown).toContain("screen cut at");
        expect(snapshot.markdown).toContain(MAIN_MENU_HEADING);
        expect(snapshot.markdown).toContain('nav.home "Startseite" (open) · nav.support-tickets "Support-Center"');
        expect(snapshot.markdown).toContain('nav.settings "Einstellungen" (closed group)');
        expect(snapshot.markdown).not.toContain("nav.texts");
        expect(registry.find("nav.support-tickets")?.getAttribute("href")).toBe("/support-tickets");
        expect(snapshot.references["nav.home"]).toBe("Startseite");
    });
    it("lists what the screen declares for actOnScreen: sortable columns, rows that open, declared buttons, the filters", () => {
        document.body.innerHTML =
            '<main><h1>Tickets</h1><table><thead><tr><th>ID</th>' +
                '<th aria-sort="descending"><button data-agent-action="sort" data-agent-action-key="created">Created</button></th>' +
                '<th><button data-agent-action="sort" data-agent-action-key="updated">Updated</button></th></tr></thead>' +
                '<tbody><tr data-row-key="13066" data-agent-action="open"><td>13066</td><td>3 Nov</td><td>4 Nov</td></tr></tbody></table>' +
                '<button data-agent-action="press" data-agent-action-key="load-more" data-agent-action-effect="view">Load more</button>' +
                '<button data-agent-action="press" data-agent-action-key="archive" data-agent-action-effect="write">Archive</button><button>Delete</button></main>';
        const { reader, registry } = createReader({ describeFilters: () => "status=OPEN · priority · channel" });
        const snapshot = reader.read({ maxChars: 12000 });
        expect(snapshot.markdown).toContain("Filters (actOnScreen, action setFilters): status=OPEN · priority · channel");
        expect(snapshot.markdown).toContain('Sort (actOnScreen, action sort): col.created "Created" (desc) · col.updated "Updated"');
        expect(snapshot.markdown).toContain("Rows open with actOnScreen (action openRow, reference row.<key>).");
        expect(snapshot.markdown).toContain('Actions (actOnScreen, action press): act.load-more "Load more"');
        expect(snapshot.markdown).toContain('Buttons: b.archive "Archive" · b.delete "Delete"');
        expect(registry.find("col.updated")?.textContent).toBe("Updated");
        expect(registry.findDeclared("act.load-more")?.textContent).toBe("Load more");
        expect(registry.findDeclared("b.delete")).toBeNull();
    });
    it("writes none of the action lines for a screen that declares nothing", () => {
        document.body.innerHTML =
            `<main><h1>Tickets</h1><table><thead><tr><th>ID</th></tr></thead><tbody>${ticketRows(2)}</tbody></table><button>Delete</button></main>`;
        const markdown = createReader().reader.read({ maxChars: 12000 }).markdown;
        expect(markdown).not.toContain("actOnScreen");
    });
    it("names the agent window and the browser size", () => {
        document.body.innerHTML = "<main><h1>Support-Center</h1></main>";
        const size = `${Math.round(window.innerWidth)}×${Math.round(window.innerHeight)} px`;
        const withWindow = createReader({ describeAgentWindow: () => "full screen: it covers the whole page" }).reader.read({ maxChars: 12000 });
        expect(withWindow.markdown.split("\n")[3]).toBe(`Agent window: full screen: it covers the whole page · browser ${size}`);
        const withoutWindow = createReader().reader.read({ maxChars: 12000 });
        expect(withoutWindow.markdown.split("\n")[3]).toBe(`Browser window: ${size}`);
    });
});
