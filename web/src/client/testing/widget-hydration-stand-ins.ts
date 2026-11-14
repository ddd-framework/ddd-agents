import {
  getWidgetHydrator,
  listWidgetHydratorTypes,
  registerWidgetHydrator,
  unregisterWidgetHydrator,
} from "../../renderer/widget-hydration/widget-hydration.registry";
import type { RegisteredWidget, WidgetHydratorContext } from "../../renderer/widget-hydration/widget-hydration.types";

/**
 * What a stand-in answers for one hydration: the data itself (a copy is handed out), `{ __status: <code> }` for a
 * failed read (the widget observes `error`), or `{ __pending: true }` for a read that never ends (`loading`). The same
 * shape as a preview fixture's `hydration` entry.
 */
export type WidgetHydrationStandInAnswer = unknown | { __status: number; __body?: unknown } | { __pending: true };

const isPendingAnswer = (answer: unknown): boolean =>
  typeof answer === "object" && answer !== null && (answer as { __pending?: unknown }).__pending === true;

const isStatusAnswer = (answer: unknown): answer is { __status: number } =>
  typeof answer === "object" && answer !== null && typeof (answer as { __status?: unknown }).__status === "number";

/**
 * Hydration answered by a spec instead of mocking `useWidgetHydration`: every registered hydrator is replaced by one
 * that answers from `answers` — by the hydration's derived key first, then by its type — and otherwise runs the real
 * hydrator. A key that names no registered hydrator gets a stand-in hydrator of that type, so a widget hydrates even
 * when the spec did not import the app's hydrator module. The widget runs its live code path (`useWidgetHydration`
 * with the provider's state store); the answer arrives asynchronously, as a real read does. `restore()` puts the
 * real hydrators back and removes the added ones.
 */
export class WidgetHydrationStandIns {
  protected readonly replacedHydrators = new Map<string, RegisteredWidget<unknown, unknown>>();

  protected readonly addedTypes: string[] = [];

  constructor(protected readonly answers: Readonly<Record<string, WidgetHydrationStandInAnswer>>) {
    for (const type of listWidgetHydratorTypes()) {
      const registered = getWidgetHydrator<unknown, unknown>(type);
      if (registered) this.replaceHydrator(type, registered);
    }
    for (const key of Object.keys(answers)) {
      if (this.replacedHydrators.has(key)) continue;
      this.addedTypes.push(key);
      registerWidgetHydrator<unknown, unknown>(key, {
        hydrator: (payload, context) => this.answer(key, key, payload, context, undefined),
      });
    }
  }

  /** Puts the real hydrators back and removes the stand-ins added for types nothing had registered. */
  restore(): void {
    for (const type of this.addedTypes) unregisterWidgetHydrator(type);
    for (const [type, registered] of this.replacedHydrators) {
      unregisterWidgetHydrator(type);
      registerWidgetHydrator(type, registered);
    }
  }

  protected replaceHydrator(type: string, registered: RegisteredWidget<unknown, unknown>): void {
    this.replacedHydrators.set(type, registered);
    unregisterWidgetHydrator(type);
    registerWidgetHydrator<unknown, unknown>(type, {
      ...registered,
      hydrator: (payload, context) => this.answer(type, registered.deriveKey(type, payload), payload, context, registered),
    });
  }

  /** The answer for one read: the derived key's entry, else the type's, else the real hydrator (none: a failed read). */
  protected answer(
    type: string,
    derivedKey: string,
    payload: unknown,
    context: WidgetHydratorContext,
    registered: RegisteredWidget<unknown, unknown> | undefined,
  ): Promise<unknown> {
    const answerKey = Object.prototype.hasOwnProperty.call(this.answers, derivedKey)
      ? derivedKey
      : Object.prototype.hasOwnProperty.call(this.answers, type)
        ? type
        : undefined;
    if (answerKey === undefined) {
      return registered
        ? registered.hydrator(payload, context)
        : Promise.reject(new Error(`no hydration stand-in for ${derivedKey}`));
    }
    const answer = this.answers[answerKey];
    if (isPendingAnswer(answer)) return new Promise<never>(() => {});
    if (isStatusAnswer(answer)) return Promise.reject(new Error(`hydration stand-in ${answer.__status}`));
    return Promise.resolve(structuredClone(answer));
  }
}

/** Installs hydration stand-ins (`WidgetHydrationStandIns`); call `restore()` on the result after the spec. */
export const installWidgetHydrationStandIns = (
  answers: Readonly<Record<string, WidgetHydrationStandInAnswer>>,
): WidgetHydrationStandIns => new WidgetHydrationStandIns(answers);
