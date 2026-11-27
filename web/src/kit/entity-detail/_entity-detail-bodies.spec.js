import { describe, expect, test } from "vitest";
import { getEntityDetailBody, readEntityDetailBodyDefinitions, registerEntityDetailBodies, } from "./entity-detail-bodies";
const StubInvoiceBody = () => null;
const StubOrderBody = () => null;
/** The body definitions of two stand-in body modules, as an app's folder glob hands them over. */
const bodyModules = {
    "./invoice/invoice.entity-detail-body.tsx": { entityDetailBody: { entityType: "specInvoice", component: StubInvoiceBody } },
    "./order/order.entity-detail-body.tsx": { entityDetailBody: { entityType: "specOrder", component: StubOrderBody } },
    "./order/order-helpers.tsx": {},
};
/** The registered entity detail bodies, one looked up by its entity type. */
describe("getEntityDetailBody", () => {
    test("finds the body an app registered for an entity type", () => {
        registerEntityDetailBodies(readEntityDetailBodyDefinitions(bodyModules));
        expect(getEntityDetailBody("specInvoice")).toEqual({ entityType: "specInvoice", component: StubInvoiceBody });
        expect(getEntityDetailBody("specOrder")?.component).toBe(StubOrderBody);
    });
    test("finds nothing for a type without a body, also for a name every object inherits", () => {
        expect(getEntityDetailBody("specUnregistered")).toBeUndefined();
        expect(getEntityDetailBody("constructor")).toBeUndefined();
        expect(getEntityDetailBody("toString")).toBeUndefined();
    });
});
