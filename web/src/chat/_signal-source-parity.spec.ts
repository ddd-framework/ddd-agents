import { describe, expect, it } from "vitest";
import { PACKAGE_WIDGET_CLICK_SIGNAL_SOURCES } from "../renderer/widget-click-signal-sources";
import {
  PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES,
  SIGNAL_SOURCES_THAT_OWE_NO_WORK,
} from "./last-wire-message-is-unanswered-user-message";
import signalSourceParityFixture from "./signal-source-parity.json";

/**
 * The frontend's signal-source string lists against the backend registry, written out
 * by the PHPUnit `ConversationSignalSourceFrontendParityTest` into `signal-source-parity.json` (generated, never edited
 * by hand — its `regenerate` field names the command). A backend membership change fails here until the list follows.
 * The fixture holds the package's own sources only, so the package's own lists are compared — without an app's further
 * widget click sources (an app pins those against its own fixture).
 */
const sorted = (values: readonly string[]): string[] => [...values].sort();

describe("signal-source parity with the backend registry", () => {
  it("the widget-click list is the sources that carry an owner statement", () => {
    expect(sorted(PACKAGE_WIDGET_CLICK_SIGNAL_SOURCES)).toEqual(signalSourceParityFixture.lists.widgetClickSignalSources);
  });

  it("the phone-call lifecycle list is the backend's lifecycle sources (call marker chip, unanswered walk)", () => {
    expect(sorted(PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES)).toEqual(
      signalSourceParityFixture.lists.phoneCallLifecycleSignalSources,
    );
    // ConversationCallMarkerChip reads the end marker by its literal
    expect(signalSourceParityFixture.lists.phoneCallLifecycleSignalSources).toContain("PHONE_CALL_ENDED");
  });

  it("the signal rows the unanswered walk sees through are the backend's sources that owe no work", () => {
    expect(sorted(SIGNAL_SOURCES_THAT_OWE_NO_WORK)).toEqual(signalSourceParityFixture.lists.signalSourcesThatOweNoWork);
  });

  it("every listed source is a registered stored value", () => {
    const registeredValues = Object.keys(signalSourceParityFixture.signalSources);
    for (const listedValue of [
      ...PACKAGE_WIDGET_CLICK_SIGNAL_SOURCES,
      ...PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES,
      ...SIGNAL_SOURCES_THAT_OWE_NO_WORK,
    ]) {
      expect(registeredValues).toContain(listedValue);
    }
  });
});
