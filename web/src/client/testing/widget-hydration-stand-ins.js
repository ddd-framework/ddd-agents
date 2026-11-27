import { getWidgetHydrator, listWidgetHydratorTypes, registerWidgetHydrator, unregisterWidgetHydrator, } from "../../renderer/widget-hydration/widget-hydration.registry";
import { abandonWidgetHydrationReads } from "../../renderer/widget-hydration/use-widget-hydration";
const isPendingAnswer = (answer) => typeof answer === "object" && answer !== null && answer.__pending === true;
const isStatusAnswer = (answer) => typeof answer === "object" && answer !== null && typeof answer.__status === "number";
/**
 * Hydration answered by a spec instead of mocking `useWidgetHydration`: every registered hydrator is replaced by one
 * that answers from `answers` — by the hydration's derived key first, then by its type — and otherwise runs the real
 * hydrator. A key that names no registered hydrator gets a stand-in hydrator of that type, so a widget hydrates even
 * when the spec did not import the app's hydrator module. The widget runs its live code path (`useWidgetHydration`
 * with the provider's state store); the answer arrives asynchronously, as a real read does. `restore()` puts the
 * real hydrators back, removes the added ones and abandons every read started through the stand-ins that has not
 * settled yet: the hydration hook keeps one in-flight read per key beyond a store and a mount, so a read left pending
 * would otherwise hold every later mount of the same key in loading.
 */
export class WidgetHydrationStandIns {
    answers;
    replacedHydrators = new Map();
    addedTypes = [];
    /** The hydration key of every read started through the stand-ins that has not settled yet, by its rejection. */
    unsettledReadKeys = new Map();
    constructor(answers) {
        this.answers = answers;
        for (const type of listWidgetHydratorTypes()) {
            const registered = getWidgetHydrator(type);
            if (registered)
                this.replaceHydrator(type, registered);
        }
        for (const key of Object.keys(answers)) {
            if (this.replacedHydrators.has(key))
                continue;
            this.addedTypes.push(key);
            registerWidgetHydrator(key, {
                hydrator: (payload, context) => this.answer(key, key, payload, context, undefined),
            });
        }
    }
    /**
     * Puts the real hydrators back, removes the stand-ins added for types nothing had registered, and aborts and
     * forgets the reads still pending, so the next mount of their keys reads anew.
     */
    restore() {
        abandonWidgetHydrationReads(new Set(this.unsettledReadKeys.values()));
        for (const rejectRead of this.unsettledReadKeys.keys())
            rejectRead(new Error("hydration stand-ins restored"));
        this.unsettledReadKeys.clear();
        for (const type of this.addedTypes)
            unregisterWidgetHydrator(type);
        for (const [type, registered] of this.replacedHydrators) {
            unregisterWidgetHydrator(type);
            registerWidgetHydrator(type, registered);
        }
    }
    replaceHydrator(type, registered) {
        this.replacedHydrators.set(type, registered);
        unregisterWidgetHydrator(type);
        registerWidgetHydrator(type, {
            ...registered,
            hydrator: (payload, context) => this.answer(type, registered.deriveKey(type, payload), payload, context, registered),
        });
    }
    /** One read, abandoned by `restore()` while it has not settled. */
    answer(type, derivedKey, payload, context, registered) {
        const read = this.readAnswer(type, derivedKey, payload, context, registered);
        return new Promise((resolve, reject) => {
            this.unsettledReadKeys.set(reject, derivedKey);
            read.then(resolve, reject).finally(() => this.unsettledReadKeys.delete(reject));
        });
    }
    /** The answer for one read: the derived key's entry, else the type's, else the real hydrator (none: a failed read). */
    readAnswer(type, derivedKey, payload, context, registered) {
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
        if (isPendingAnswer(answer))
            return new Promise(() => { });
        if (isStatusAnswer(answer))
            return Promise.reject(new Error(`hydration stand-in ${answer.__status}`));
        return Promise.resolve(structuredClone(answer));
    }
}
/** Installs hydration stand-ins (`WidgetHydrationStandIns`); call `restore()` on the result after the spec. */
export const installWidgetHydrationStandIns = (answers) => new WidgetHydrationStandIns(answers);
