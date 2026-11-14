// @vitest-environment jsdom
import type { ReactNode } from "react";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ConversationWidgetRenderContext } from "../../renderer/ConversationWidgetRenderContext";
import {
  WidgetRenderReportContext,
  type WidgetRenderReportEntry,
} from "../../renderer/render-report/widget-render-report";
import { WritingDraftConversationWidget } from "./WritingDraftConversationWidget";
import { MAX_DRAFT_BODY_LENGTH, parseWritingDraftPayload } from "./shared/writing-draft-widget-payload";
import { convertDraftMarkdownToPlainText } from "./shared/draft-plain-text";
import { buildDraftMailLink, MAX_MAIL_LINK_LENGTH, readEmailAddresses } from "./shared/draft-mail-link";
import writingDraftPreviewFixtures from "./writing-draft.preview-fixtures.json";

const readFixturePayload = (fixtureName: string): string =>
  JSON.stringify(writingDraftPreviewFixtures.fixtures.find((fixture) => fixture.name === fixtureName)?.payload ?? {});

const EMAIL_PAYLOAD = JSON.stringify({
  kind: "email",
  title: "Anfrage an die Bäckerei Müller",
  recipient: "info@baeckerei-mueller.de",
  subject: "Angebot für 200 Brötchen",
  body: "Guten Tag,\n\nwir suchen **200 Brötchen** pro Woche:\n\n- Montag\n- Freitag\n\nViele Grüße",
});

const buildStore = () =>
  configureStore({
    reducer: {
      GlobalReducer: () => ({ languageCode: "de" }),
      Settings: () => ({ LocationSettings: { localeFormatted: "de-DE" } }),
    },
  });

const renderDraft = (payload: string, options: { email?: boolean } = {}) => {
  const reports: WidgetRenderReportEntry[] = [];
  const reporter = { report: (entry: WidgetRenderReportEntry) => reports.push(entry) };
  const wrap = (children: ReactNode) => (
    <Provider store={buildStore()}>
      <ConversationWidgetRenderContext.Provider value={options.email ? "email" : "conversation"}>
        <WidgetRenderReportContext.Provider value={reporter}>{children}</WidgetRenderReportContext.Provider>
      </ConversationWidgetRenderContext.Provider>
    </Provider>
  );
  const view = render(wrap(<WritingDraftConversationWidget payload={payload} interactive />));
  return { ...view, reports };
};

/**
 * The visible text field of a text input: the element that carries the test id, or the field inside it (a component
 * library may wrap the field, and an autosizing text area may render a hidden twin).
 */
const readTextField = (testId: string): HTMLInputElement | HTMLTextAreaElement => {
  const host = screen.getByTestId(testId);
  if (host instanceof HTMLInputElement || host instanceof HTMLTextAreaElement) return host;
  return within(host)
    .getAllByRole("textbox")
    .find((element) => element.getAttribute("aria-hidden") !== "true") as HTMLInputElement | HTMLTextAreaElement;
};

const readBodyTextarea = () => readTextField("writing-draft-body-input");

describe("parseWritingDraftPayload", () => {
  it("reads the canonical shape", () => {
    expect(parseWritingDraftPayload(EMAIL_PAYLOAD)).toEqual({
      isValidPayload: true,
      isBodyUnreadable: false,
      kind: "email",
      title: "Anfrage an die Bäckerei Müller",
      recipient: "info@baeckerei-mueller.de",
      subject: "Angebot für 200 Brötchen",
      body: "Guten Tag,\n\nwir suchen **200 Brötchen** pro Woche:\n\n- Montag\n- Freitag\n\nViele Grüße",
    });
  });

  it("accepts ChatGPT's WritingArtifact shape and the aliases a weak model writes", () => {
    expect(parseWritingDraftPayload(readFixturePayload("chatgpt-shape"))).toEqual({
      isValidPayload: true,
      isBodyUnreadable: false,
      kind: "email",
      recipient: "info@dentallabor-kassel.de",
      subject: "Termin nächste Woche",
      body: "Hallo Herr Weber,\n\npasst Ihnen Dienstag um 10 Uhr für die Abholung der Abformungen?\n\nBeste Grüße\nDr. Lena Hoffmann",
    });
    expect(parseWritingDraftPayload(JSON.stringify({ variant: "social_post", text: "Hallo" })).kind).toBe("post");
    expect(parseWritingDraftPayload(JSON.stringify({ kind: "Rundschreiben", text: "Hallo" })).kind).toBe("other");
  });

  it("treats a 'not specified' recipient as none and caps the body", () => {
    const parsedDraft = parseWritingDraftPayload(
      JSON.stringify({ recipient: "not specified", body: "x".repeat(MAX_DRAFT_BODY_LENGTH + 50) }),
    );
    expect(parsedDraft.recipient).toBeUndefined();
    expect(parsedDraft.body).toHaveLength(MAX_DRAFT_BODY_LENGTH);
  });

  it("never throws: malformed JSON and non-objects are invalid, a body that is no text is unreadable", () => {
    expect(parseWritingDraftPayload("{not json")).toEqual({ isValidPayload: false, body: "", isBodyUnreadable: false });
    expect(parseWritingDraftPayload("[1, 2]").isValidPayload).toBe(false);
    expect(parseWritingDraftPayload(JSON.stringify({ body: { paragraphs: ["a"] } }))).toMatchObject({
      isValidPayload: true,
      body: "",
      isBodyUnreadable: true,
    });
    expect(parseWritingDraftPayload(JSON.stringify({ body: "" }))).toMatchObject({ body: "", isBodyUnreadable: false });
  });
});

describe("convertDraftMarkdownToPlainText", () => {
  it("drops the Markdown syntax and keeps paragraphs, list markers and link targets", () => {
    expect(
      convertDraftMarkdownToPlainText(
        "## Angebot\n\nWir suchen **200 Brötchen** und _frische_ Croissants:\n\n* Montag\n* Freitag\n\n> Zitat\n\nMehr auf [unserer Seite](https://cafe-orbit.de) oder [info@cafe.de](mailto:info@cafe.de).",
      ),
    ).toBe(
      "Angebot\n\nWir suchen 200 Brötchen und frische Croissants:\n\n- Montag\n- Freitag\n\nZitat\n\nMehr auf unserer Seite (https://cafe-orbit.de) oder info@cafe.de.",
    );
  });

  it("leaves snake_case words and arithmetic alone", () => {
    expect(convertDraftMarkdownToPlainText("Datei mein_neuer_plan.pdf, 2 * 3 = 6")).toBe(
      "Datei mein_neuer_plan.pdf, 2 * 3 = 6",
    );
  });
});

describe("buildDraftMailLink", () => {
  it("keeps only real addresses, encodes subject and body with CRLF line breaks", () => {
    expect(readEmailAddresses("Anna <anna@cafe.de>; bob@example.org, Herr Müller, x@y")).toEqual([
      "anna@cafe.de",
      "bob@example.org",
    ]);
    expect(buildDraftMailLink("anna@cafe.de", "Termin & Preis?", "Hallo\nAnna")).toEqual({
      href: "mailto:anna@cafe.de?subject=Termin%20%26%20Preis%3F&body=Hallo%0D%0AAnna",
      includesBody: true,
    });
    expect(buildDraftMailLink("Herr Müller", undefined, "Hallo").href).toBe("mailto:?body=Hallo");
  });

  it("leaves a body out that would make the link too long", () => {
    const draftMailLink = buildDraftMailLink("anna@cafe.de", "Lang", "ä".repeat(MAX_MAIL_LINK_LENGTH));
    expect(draftMailLink).toEqual({ href: "mailto:anna@cafe.de?subject=Lang", includesBody: false });
  });
});

describe("WritingDraftConversationWidget", () => {
  const writeText = vi.fn(() => Promise.resolve());
  const assign = vi.fn();
  const originalLocation = window.location;

  beforeEach(() => {
    writeText.mockClear();
    assign.mockClear();
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    Object.defineProperty(window, "location", { value: { ...originalLocation, assign }, configurable: true });
  });

  afterEach(() => {
    Object.defineProperty(window, "location", { value: originalLocation, configurable: true });
  });

  it("renders the e-mail draft: kind label, headline, recipient and subject rows, the formatted text", () => {
    const { reports, container } = renderDraft(EMAIL_PAYLOAD);
    expect(screen.getByText("E-mail draft")).toBeTruthy();
    expect(screen.getByTestId("writing-draft-widget-kind").textContent).toBe("E-mail draft");
    expect(screen.getByTestId("writing-draft-widget-title").textContent).toBe("Anfrage an die Bäckerei Müller");
    expect(within(screen.getByTestId("writing-draft-recipient")).getByText("info@baeckerei-mueller.de")).toBeTruthy();
    expect(within(screen.getByTestId("writing-draft-subject")).getByText("Angebot für 200 Brötchen")).toBeTruthy();
    expect(container.querySelector("[data-testid='writing-draft-body'] strong")?.textContent).toBe("200 Brötchen");
    expect(container.querySelectorAll("[data-testid='writing-draft-body'] li")).toHaveLength(2);
    expect(screen.getByTestId("writing-draft-open-in-mail")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  it("keeps a single line break of the draft — a signature stays on two lines", () => {
    renderDraft(JSON.stringify({ kind: "letter", body: "Viele Grüße\nAnna Schmidt" }));
    const draftBody = screen.getByTestId("writing-draft-body");
    expect(draftBody.querySelectorAll("br")).toHaveLength(1);
    expect(draftBody.textContent?.replace(/\s+/g, " ")).toBe("Viele Grüße Anna Schmidt");
  });

  it("shows the subject once, in the mail header, when the model wrote no title, and an empty recipient as not set", () => {
    renderDraft(readFixturePayload("default"));
    expect(screen.getAllByText("Kurze Anfrage")).toHaveLength(1);
    expect(screen.getByTestId("writing-draft-widget-title").textContent).toBe("E-mail draft");
    expect(within(screen.getByTestId("writing-draft-recipient")).getByText("No recipient yet")).toBeTruthy();
  });

  it("keeps the model's text safe: no image, no raw HTML, no javascript link, model markup shown as written", () => {
    const { container } = renderDraft(
      JSON.stringify({
        kind: "post",
        title: '<b onmouseover="x()">Titel</b>',
        body: "Hallo ![pixel](https://tracker.example/p.gif) <script>alert(1)</script> [klick](javascript:alert(1)) [Seite](https://cafe-orbit.de)",
      }),
    );
    const draftBody = screen.getByTestId("writing-draft-body");
    expect(draftBody.querySelector("img")).toBeNull();
    expect(draftBody.querySelector("script")).toBeNull();
    const links = Array.from(draftBody.querySelectorAll("a"));
    expect(links.map((link) => link.getAttribute("href"))).toEqual(["https://cafe-orbit.de/"]);
    expect(links[0]?.getAttribute("target")).toBe("_blank");
    expect(container.querySelector("b[onmouseover]")).toBeNull();
    expect(screen.getByText('<b onmouseover="x()">Titel</b>')).toBeTruthy();
  });

  it("edits in place, says the edit stays local, and goes back to the original draft", () => {
    renderDraft(EMAIL_PAYLOAD);
    fireEvent.click(screen.getByTestId("writing-draft-edit"));
    expect(screen.queryByTestId("writing-draft-copy")).toBeNull();
    expect(screen.getByText("Your changes stay on this screen — the assistant does not see them.")).toBeTruthy();
    fireEvent.change(readBodyTextarea(), { target: { value: "Neuer **Text**" } });
    fireEvent.click(screen.getByTestId("writing-draft-done"));
    expect(screen.getByTestId("writing-draft-body").querySelector("strong")?.textContent).toBe("Text");
    expect(screen.getByTestId("writing-draft-edited")).toBeTruthy();

    fireEvent.click(screen.getByTestId("writing-draft-reset"));
    expect(screen.queryByTestId("writing-draft-edited")).toBeNull();
    expect(screen.getByTestId("writing-draft-body").querySelector("strong")?.textContent).toBe("200 Brötchen");
  });

  it("copies the edited text as plain text and opens the mail program with recipient, subject and text", async () => {
    renderDraft(EMAIL_PAYLOAD);
    fireEvent.click(screen.getByTestId("writing-draft-edit"));
    fireEvent.change(readTextField("writing-draft-recipient-input"), { target: { value: "anna@cafe.de" } });
    fireEvent.change(readBodyTextarea(), { target: { value: "Hallo **Anna**,\n\nbis Montag." } });
    fireEvent.click(screen.getByTestId("writing-draft-done"));

    fireEvent.click(screen.getByTestId("writing-draft-copy"));
    expect(writeText).toHaveBeenCalledWith("Hallo Anna,\n\nbis Montag.");
    expect(await screen.findByText("Copied")).toBeTruthy();

    fireEvent.click(screen.getByTestId("writing-draft-open-in-mail"));
    expect(assign).toHaveBeenCalledWith(
      "mailto:anna@cafe.de?subject=Angebot%20f%C3%BCr%20200%20Br%C3%B6tchen&body=Hallo%20Anna%2C%0D%0A%0D%0Abis%20Montag.",
    );
  });

  it("offers no mail action and no address rows for a post", () => {
    renderDraft(readFixturePayload("post"));
    expect(screen.getByText("Post draft")).toBeTruthy();
    expect(screen.queryByTestId("writing-draft-open-in-mail")).toBeNull();
    expect(screen.queryByTestId("writing-draft-recipient")).toBeNull();
    expect(screen.queryByTestId("writing-draft-subject")).toBeNull();
    expect(screen.getByTestId("writing-draft-character-count").textContent).toMatch(/^\d+ character/);
  });

  it("tells the owner to copy a text too long for a mail link", () => {
    renderDraft(JSON.stringify({ kind: "email", recipient: "anna@cafe.de", body: "Lang ".repeat(800) }));
    expect(
      screen.getByText("The text is too long for a mail link: copy it and paste it into the e-mail."),
    ).toBeTruthy();
  });

  it("renders read-only without any action in an e-mail capture", () => {
    renderDraft(EMAIL_PAYLOAD, { email: true });
    expect(screen.getByText("Anfrage an die Bäckerei Müller")).toBeTruthy();
    expect(screen.queryByTestId("writing-draft-copy")).toBeNull();
    expect(screen.queryByTestId("writing-draft-edit")).toBeNull();
    expect(screen.queryByTestId("writing-draft-open-in-mail")).toBeNull();
  });

  it("reports empty for a block without a text and failed for an unreadable one", () => {
    const emptyRender = renderDraft(readFixturePayload("empty"));
    expect(screen.getByText("This draft has no text yet.")).toBeTruthy();
    expect(emptyRender.reports.at(-1)).toEqual({
      status: "empty",
      code: "NO_DATA",
      detail: "The WritingDraft widget carries no text (`body`).",
    });
    emptyRender.unmount();

    const unreadableRender = renderDraft(readFixturePayload("unreadable-body"));
    expect(screen.getByText("This draft could not be shown.")).toBeTruthy();
    expect(unreadableRender.reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
    unreadableRender.unmount();

    const malformedRender = renderDraft("{not json");
    expect(malformedRender.reports.at(-1)).toEqual({
      status: "failed",
      code: "INVALID_PROP",
      detail: "The WritingDraft payload is not a JSON object.",
    });
  });
});
