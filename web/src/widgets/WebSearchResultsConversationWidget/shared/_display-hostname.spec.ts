import { describe, expect, it } from "vitest";
import { decodePunycodeLabel, toDisplayHostname } from "./display-hostname";

describe("toDisplayHostname", () => {
  it("decodes an internationalised domain to the form a reader writes", () => {
    expect(toDisplayHostname("xn--bckerhandwerk-bfb.de")).toBe("bäckerhandwerk.de");
    expect(toDisplayHostname("xn--mnchen-3ya.de")).toBe("münchen.de");
    expect(toDisplayHostname("shop.xn--zahnrzte-kassel-3kb.de")).toBe("shop.zahnärzte-kassel.de");
  });

  it("leaves an ASCII hostname and an undecodable label as they are", () => {
    expect(toDisplayHostname("kassel.de")).toBe("kassel.de");
    expect(toDisplayHostname("xn--.de")).toBe("xn--.de");
    expect(decodePunycodeLabel("ä-abc")).toBeUndefined();
  });
});
