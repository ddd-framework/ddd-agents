import { describe, expect, it } from "vitest";
import { buildDriveFilesHydrationKey, DRIVE_FILES_MAX_FILES, driveFileCardToOpen, driveFileFamilyOf, driveFileTypeLabelOf, formatDriveFileSize, isDriveFileCardExpired, nodeIdOfReference, parseDriveFilesPayload, safeDriveFileOpenLink, } from "./shared/drive-files";
const KEY_FIELD = "agentSpaceId";
describe("parseDriveFilesPayload", () => {
    it("reads store, references, title and the key attribute", () => {
        const request = parseDriveFilesPayload(JSON.stringify({ store: "drive", references: ["drive/812", "drive/9"], title: " Briefings " }), KEY_FIELD, "31");
        expect(request).toEqual({ store: "drive", references: ["drive/812", "drive/9"], title: "Briefings", widgetSelfHydrationKey: 31 });
    });
    it("falls back to the key field of the payload and to no title", () => {
        const request = parseDriveFilesPayload(JSON.stringify({ store: "drive", references: ["drive/1"], agentSpaceId: 7 }), KEY_FIELD);
        expect(request?.widgetSelfHydrationKey).toBe(7);
        expect(request?.title).toBeNull();
    });
    it("drops blank, duplicate and non-string references and caps them", () => {
        const references = ["drive/1", " drive/1 ", "", 3, ...Array.from({ length: 30 }, (unused, index) => `drive/${index + 2}`)];
        const request = parseDriveFilesPayload(JSON.stringify({ store: "drive", references }), KEY_FIELD);
        expect(request?.references.length).toBe(DRIVE_FILES_MAX_FILES);
        expect(request?.references[0]).toBe("drive/1");
        expect(request?.references[1]).toBe("drive/2");
    });
    it("refuses a block without store or references, and broken JSON", () => {
        expect(parseDriveFilesPayload(JSON.stringify({ store: "", references: ["drive/1"] }), KEY_FIELD)).toBeNull();
        expect(parseDriveFilesPayload(JSON.stringify({ store: "drive", references: [] }), KEY_FIELD)).toBeNull();
        expect(parseDriveFilesPayload("{not json", KEY_FIELD)).toBeNull();
        expect(parseDriveFilesPayload(undefined, KEY_FIELD)).toBeNull();
    });
    it("ignores a key that is no positive integer", () => {
        const payload = JSON.stringify({ store: "drive", references: ["drive/1"] });
        expect(parseDriveFilesPayload(payload, KEY_FIELD, "abc")?.widgetSelfHydrationKey).toBeNull();
        expect(parseDriveFilesPayload(payload, KEY_FIELD, 0)?.widgetSelfHydrationKey).toBeNull();
    });
});
describe("buildDriveFilesHydrationKey", () => {
    it("differs per store, space and reference order, not per headline", () => {
        const base = { store: "drive", references: ["drive/1", "drive/2"], title: null, widgetSelfHydrationKey: 3 };
        const key = buildDriveFilesHydrationKey("DriveFiles", base);
        expect(key).not.toBe(buildDriveFilesHydrationKey("DriveFiles", { ...base, widgetSelfHydrationKey: 4 }));
        expect(key).not.toBe(buildDriveFilesHydrationKey("DriveFiles", { ...base, references: ["drive/2", "drive/1"] }));
        expect(key).toBe(buildDriveFilesHydrationKey("DriveFiles", { ...base, title: "Other headline" }));
    });
});
describe("references and links", () => {
    it("reads the node of a reference", () => {
        expect(nodeIdOfReference("drive/812")).toBe("812");
        expect(nodeIdOfReference("drivespaces/library/Logo.svg")).toBeNull();
        expect(nodeIdOfReference("812")).toBeNull();
    });
    it("keeps an app path and a same-origin URL, refuses everything else", () => {
        const origin = "https://admin.example.org";
        expect(safeDriveFileOpenLink("/admin/drive?space=3&path=%2FA&file=9", origin)).toBe("/admin/drive?space=3&path=%2FA&file=9");
        expect(safeDriveFileOpenLink("https://admin.example.org/drive?space=3", origin)).toBe("https://admin.example.org/drive?space=3");
        expect(safeDriveFileOpenLink("https://example.org/drive", origin)).toBeNull();
        expect(safeDriveFileOpenLink("//example.org/admin/drive", origin)).toBeNull();
        expect(safeDriveFileOpenLink("javascript:alert(1)", origin)).toBeNull();
        expect(safeDriveFileOpenLink("/\\example.org", origin)).toBeNull();
        expect(safeDriveFileOpenLink(null, origin)).toBeNull();
        expect(safeDriveFileOpenLink("https://admin.example.org/drive", null)).toBeNull();
    });
});
describe("file family, type label and size", () => {
    it("maps content types and extensions to a family", () => {
        expect(driveFileFamilyOf({ name: "a.png", contentType: "image/png" })).toBe("IMAGE");
        expect(driveFileFamilyOf({ name: "logo.svg", contentType: "image/svg+xml" })).toBe("VECTOR");
        expect(driveFileFamilyOf({ name: "Plan.pdf", contentType: null })).toBe("PDF");
        expect(driveFileFamilyOf({ name: "Codes.xlsx", contentType: "application/octet-stream" })).toBe("SPREADSHEET");
        expect(driveFileFamilyOf({ name: "notes", contentType: null })).toBe("TEXT");
    });
    it("names the extension in capitals", () => {
        expect(driveFileTypeLabelOf({ name: "Codes.xlsx" })).toBe("XLSX");
        expect(driveFileTypeLabelOf({ name: "README" })).toBeNull();
    });
    it("formats sizes in the locale without a trailing zero", () => {
        expect(formatDriveFileSize(512, "en")).toBe("512 B");
        expect(formatDriveFileSize(2048, "en")).toBe("2 KB");
        expect(formatDriveFileSize(1572864, "de")).toBe("1,5 MB");
    });
});
describe("isDriveFileCardExpired", () => {
    const now = Date.UTC(2026, 10, 23, 12, 0, 0);
    it("reads the offsetless UTC expiry with a margin", () => {
        expect(isDriveFileCardExpired({ urlsExpireAt: "2026-11-23 12:10:00" }, now)).toBe(false);
        expect(isDriveFileCardExpired({ urlsExpireAt: "2026-11-23 12:00:20" }, now)).toBe(true);
        expect(isDriveFileCardExpired({ urlsExpireAt: "2026-11-23 11:59:00" }, now)).toBe(true);
    });
    it("counts a card without an expiry as expired", () => {
        expect(isDriveFileCardExpired({ urlsExpireAt: null }, now)).toBe(true);
        expect(isDriveFileCardExpired({}, now)).toBe(true);
    });
});
describe("driveFileCardToOpen", () => {
    const file = (path, contentType = null) => ({
        name: path.split("/").pop() ?? path,
        path,
        contentType,
        isFolder: false,
    });
    const pptx = file("out/kampagnen-2026.pptx");
    const pdf = file("out/kampagnen-2026.pdf", "application/pdf");
    it("opens a file the browser shows as itself", () => {
        expect(driveFileCardToOpen(pdf, [pptx, pdf])).toBe(pdf);
        expect(driveFileCardToOpen(file("work/chart.png", "image/png"), [])?.path).toBe("work/chart.png");
    });
    it("opens the PDF twin of an office file in the same folder", () => {
        expect(driveFileCardToOpen(pptx, [pptx, pdf])).toBe(pdf);
    });
    it("opens nothing for an office file without a twin, a twin elsewhere or a folder", () => {
        expect(driveFileCardToOpen(pptx, [pptx])).toBeNull();
        expect(driveFileCardToOpen(pptx, [pptx, file("archiv/kampagnen-2026.pdf", "application/pdf")])).toBeNull();
        expect(driveFileCardToOpen({ ...pptx, isFolder: true }, [pdf])).toBeNull();
    });
});
