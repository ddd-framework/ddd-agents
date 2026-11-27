import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { activateElement, DomScreenActor } from "./DomScreenActor";
import { DomScreenReader, ScreenReferenceRegistry } from "./DomScreenReader";
/**
 * The typed actions on the DOM: only an element that declares the action is acted on, a button only with the effect
 * `view`; a save or delete button is refused whatever the reference; unsaved changes and an open dialog block what
 * leaves the screen; the element is marked before the action runs.
 */
const LIST_PAGE = '<main><h1>Tickets</h1>' +
    '<nav aria-label="Sections"><a href="/tickets" data-agent-action="tab" data-agent-action-key="list" aria-current="page">List</a>' +
    '<button type="button" data-agent-action="tab" data-agent-action-key="archive" aria-pressed="false">Archive 12</button></nav>' +
    '<table><thead><tr><th>ID</th>' +
    '<th id="created-header"><button type="button" id="sort-created" data-agent-action="sort" data-agent-action-key="created">Created</button></th></tr></thead>' +
    '<tbody><tr data-row-key="13066" data-agent-action="open"><td>13066</td><td>3 Nov</td></tr>' +
    '<tr data-row-key="13065"><td>13065</td><td>2 Nov</td></tr></tbody></table>' +
    '<button type="button" id="more" data-agent-action="press" data-agent-action-key="load-more" data-agent-action-effect="view">Load more</button>' +
    '<button type="button" id="archive-all" data-agent-action="press" data-agent-action-key="archive-all" data-agent-action-effect="write">Archive all</button>' +
    '<button type="button" id="delete">Delete</button></main>';
const createActor = (blocker = null) => {
    const registry = new ScreenReferenceRegistry();
    const reader = new DomScreenReader({
        findRoot: () => document.querySelector("main"),
        currentLocation: () => ({ route: window.location.pathname, title: "Tickets" }),
        describeUnsavedChanges: () => "no",
        registry,
    });
    const marks = [];
    const actor = new DomScreenActor({
        registry,
        currentLocation: () => ({ route: window.location.pathname, title: "Tickets" }),
        describeBlockers: () => blocker,
        highlighter: {
            mark: (references, note) => {
                marks.push({ references, note });
                return { marked: {}, missing: [], outOfView: [], collapsed: [] };
            },
            clear: () => undefined,
        },
        describeAction: (action, label) => `${action}: ${label}`,
        markBeatMs: 0,
    });
    return { actor, reader, registry, marks };
};
const request = (action, reference, direction = null) => ({
    action,
    reference,
    direction,
});
describe("DomScreenActor", () => {
    beforeEach(() => {
        vi.spyOn(Element.prototype, "getClientRects").mockImplementation(() => [{}]);
        document.body.innerHTML = LIST_PAGE;
    });
    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = "";
    });
    it("opens a row that declares it, marked first, and refuses a row that does not", async () => {
        const { actor, reader, marks } = createActor();
        reader.read({ maxChars: 12000 });
        const clicked = [];
        for (const row of Array.from(document.querySelectorAll("tr[data-row-key]"))) {
            row.addEventListener("click", () => clicked.push(row.getAttribute("data-row-key") ?? ""));
        }
        const opened = await actor.act(request("openRow", "row.13066"));
        const refused = await actor.act(request("openRow", "row.13065"));
        expect(opened.outcome).toBe("OK");
        expect(opened.text).toContain("Opened row.13066");
        expect(refused.outcome).toBe("NOT_DECLARED");
        expect(clicked).toEqual(["13066"]);
        expect(marks.map(mark => mark.references)).toEqual([["row.13066"]]);
        expect(marks[0]?.note).toMatch(/^openRow: 13066/);
    });
    it("presses a button declared with the effect view and never one that writes or declares nothing", async () => {
        const { actor, reader } = createActor();
        reader.read({ maxChars: 12000 });
        const pressed = [];
        for (const button of Array.from(document.querySelectorAll("main > button")))
            button.addEventListener("click", () => pressed.push(button.id));
        const outcomes = [
            await actor.act(request("press", "act.load-more")),
            await actor.act(request("press", "act.archive-all")),
            await actor.act(request("press", "b.archive-all")),
            await actor.act(request("press", "b.delete")),
            await actor.act(request("press", "row.13066")),
            await actor.act(request("press", "b.nothing")),
        ];
        expect(outcomes.map(result => result.outcome)).toEqual(["OK", "WRITE_ACTION", "WRITE_ACTION", "NOT_DECLARED", "NOT_DECLARED", "NOT_ON_SCREEN"]);
        expect(pressed).toEqual(["more"]);
    });
    it("finds a declared element by its key without a read, and never an undeclared one", async () => {
        const { actor } = createActor();
        const pressed = [];
        document.getElementById("more")?.addEventListener("click", () => pressed.push("more"));
        expect((await actor.act(request("press", "act.load-more"))).outcome).toBe("OK");
        expect((await actor.act(request("press", "b.delete"))).outcome).toBe("NOT_ON_SCREEN");
        expect(pressed).toEqual(["more"]);
    });
    it("clicks a sort control until its column shows the direction", async () => {
        const { actor, reader } = createActor();
        reader.read({ maxChars: 12000 });
        const header = document.getElementById("created-header");
        const cycle = [null, "ascending", "descending"];
        let clicks = 0;
        document.getElementById("sort-created")?.addEventListener("click", () => {
            clicks += 1;
            const next = cycle[clicks % cycle.length];
            if (next)
                header.setAttribute("aria-sort", next);
            else
                header.removeAttribute("aria-sort");
        });
        const descending = await actor.act(request("sort", "col.created", "desc"));
        const again = await actor.act(request("sort", "col.created", "desc"));
        const unsorted = await actor.act(request("sort", "col.created", "none"));
        expect(descending.outcome).toBe("OK");
        expect(descending.text).toContain('col.created "Created" descending');
        expect(again.text).toContain("already");
        expect(unsorted.outcome).toBe("OK");
        expect(clicks).toBe(3);
        expect((await actor.act(request("sort", "col.created"))).outcome).toBe("MISSING_PARAMETER");
    });
    it("answers NO_EFFECT when a sort control never reaches the direction", async () => {
        const { actor } = createActor();
        expect((await actor.act(request("sort", "col.created", "asc"))).outcome).toBe("NO_EFFECT");
    }, 10000);
    it("switches a declared tab and says so when it is open already", async () => {
        const { actor, reader } = createActor();
        const snapshot = reader.read({ maxChars: 12000 });
        const switched = [];
        document.querySelector('[data-agent-action-key="archive"]')?.addEventListener("click", () => switched.push("archive"));
        expect(snapshot.markdown).toContain('Tabs (actOnScreen, action switchTab): t.list "List" (open) · t.archive "Archive 12"');
        expect((await actor.act(request("switchTab", "t.archive"))).text).toContain("Switched to tab t.archive");
        expect((await actor.act(request("switchTab", "t.list"))).text).toContain("open already");
        expect((await actor.act(request("switchTab", "act.load-more"))).outcome).toBe("NOT_DECLARED");
        expect(switched).toEqual(["archive"]);
    });
    it("keeps the user's work: unsaved changes block what leaves the screen, a dialog everything outside it", async () => {
        const clicked = [];
        document.querySelector('tr[data-row-key="13066"]')?.addEventListener("click", () => clicked.push("row"));
        document.getElementById("more")?.addEventListener("click", () => clicked.push("more"));
        document.querySelector('[data-agent-action-key="archive"]')?.addEventListener("click", () => clicked.push("tab"));
        const unsaved = createActor({ outcome: "UNSAVED_CHANGES", reason: "Unsaved changes: Edit ticket (title)." }).actor;
        expect((await unsaved.act(request("openRow", "row.13066"))).outcome).toBe("UNSAVED_CHANGES");
        expect((await unsaved.act(request("switchTab", "t.list"))).outcome).toBe("UNSAVED_CHANGES");
        expect((await unsaved.act(request("switchTab", "t.archive"))).outcome).toBe("OK");
        expect((await unsaved.act(request("press", "act.load-more"))).outcome).toBe("OK");
        document.body.insertAdjacentHTML("beforeend", '<div role="dialog"><button type="button" id="dialog-more" data-agent-action="press" data-agent-action-key="dialog-more" data-agent-action-effect="view">More</button></div>');
        const dialogOpen = createActor({ outcome: "DIALOG_OPEN", reason: "A dialog is open." }).actor;
        expect((await dialogOpen.act(request("press", "act.load-more"))).outcome).toBe("DIALOG_OPEN");
        expect((await dialogOpen.act(request("press", "act.dialog-more"))).outcome).toBe("OK");
        expect(clicked).toEqual(["tab", "more"]);
    });
    it("refuses a disabled element", async () => {
        const { actor } = createActor();
        document.getElementById("more")?.setAttribute("disabled", "");
        expect((await actor.act(request("press", "act.load-more"))).outcome).toBe("DISABLED");
    });
});
describe("activateElement", () => {
    afterEach(() => {
        document.body.innerHTML = "";
    });
    it("sends the pointer sequence a tab list selects on, and one click", () => {
        document.body.innerHTML = '<button type="button" role="tab">Details</button>';
        const tab = document.querySelector("button");
        const seen = [];
        for (const type of ["pointerdown", "mousedown", "pointerup", "mouseup", "click"])
            tab.addEventListener(type, () => seen.push(type));
        activateElement(tab);
        expect(seen).toEqual(["pointerdown", "mousedown", "pointerup", "mouseup", "click"]);
    });
});
