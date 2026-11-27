import { afterEach, describe, expect, test, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import { StandaloneConversationWidgetProvider } from "../../../renderer/ConversationWidgetContext";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";
import { buildComposedViewComponentRegistry, ComposedViewComponentsContext } from "../../composed-view-components";
import { findStoredFormAnswer } from "./compose-form-signal";
import { composedViewComponent as formComponent } from "./Form.composed";
/**
 * The Form node through the real renderer and the generated schema: the inputs write state, the
 * submit sends ONE FRONTEND_WIDGET_CLICK sentence with the field values, a required field blocks the submit until it is
 * filled, an undelivered signal keeps the form retryable, a decline sends its own sentence, a replay and the e-mail
 * capture offer no submit, and a name the signal cannot carry or a Form inside a Form reports `failed`. The inputs are
 * stand-ins that render native controls here.
 */
const StubInput = ({ node, props, runtime }) => (<input aria-label={String(props.label ?? "")} value={typeof props.value === "string" ? props.value : ""} onChange={(changeEvent) => runtime.runEvent(node.props.onChange, changeEvent.target.value)}/>);
const StubCheckbox = ({ node, props, runtime }) => (<input type="checkbox" aria-label={String(props.label ?? "")} checked={props.checked === true} onChange={(changeEvent) => runtime.runEvent(node.props.onChange, changeEvent.target.checked)}/>);
const StubTextarea = ({ node, props, runtime }) => (<textarea aria-label={String(props.label ?? "")} value={typeof props.value === "string" ? props.value : ""} onChange={(changeEvent) => runtime.runEvent(node.props.onChange, changeEvent.target.value)}/>);
const StubStack = ({ slots }) => <div>{slots.children}</div>;
const StubBadge = ({ props }) => <span data-testid="badge">{String(props.text)}</span>;
const components = buildComposedViewComponentRegistry([
    formComponent,
    { nodeType: "Input", component: StubInput },
    { nodeType: "Textarea", component: StubTextarea },
    { nodeType: "Checkbox", component: StubCheckbox },
    { nodeType: "Stack", component: StubStack },
    { nodeType: "Badge", component: StubBadge },
]);
const ComposedViewComponentsContextProvider = ({ children }) => (<ComposedViewComponentsContext.Provider value={components}>{children}</ComposedViewComponentsContext.Provider>);
const input = (label, stateName) => ({
    type: "Input",
    label,
    value: { $state: stateName },
    onChange: { set: stateName },
});
const contactForm = (formProps = {}) => ({
    state: { message: "", email: "", newsletter: false },
    view: {
        type: "Form",
        name: "contactRequest",
        title: "Your question to the team",
        description: "We answer within one day.",
        submitLabel: "Send question",
        ...formProps,
        children: [
            { ...input("Your question", "message"), type: "Textarea" },
            input("E-mail", "email"),
            { type: "Checkbox", label: "Newsletter", checked: { $state: "newsletter" }, onChange: { set: "newsletter" } },
        ],
    },
});
const renderForm = (widgetFields, options = {}) => {
    const { interactive = true, isEmailRender = false } = options;
    const injectSignal = vi.fn(options.injectSignal ?? (async () => true));
    const reports = [];
    const tree = readComposedViewTree(widgetFields, getComposedNodeSchema());
    const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
    render(<Provider store={store}>
      <StandaloneConversationWidgetProvider callbacks={{ injectSignal }}>
        <ComposedViewComponentsContextProvider>
          <ComposedViewRenderer tree={tree} interactive={interactive} isEmailRender={isEmailRender} agentSpaceKey={7} onReport={(entry) => reports.push(entry)}/>
        </ComposedViewComponentsContextProvider>
      </StandaloneConversationWidgetProvider>
    </Provider>);
    return { injectSignal, reports, tree };
};
const type = (label, value) => act(() => {
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
});
afterEach(cleanup);
describe("the Form node", () => {
    test("the reader keeps the form with its inputs and the block reports mounted", () => {
        const { tree, reports } = renderForm(contactForm());
        expect(tree.findings).toEqual([]);
        expect(screen.getByText("Your question to the team")).toBeTruthy();
        expect(screen.getByText("We answer within one day.")).toBeTruthy();
        expect(reports.at(-1)).toEqual({ status: "mounted" });
    });
    test("the header slot renders its inline items beside the title", () => {
        renderForm({
            ...contactForm(),
            view: { ...contactForm().view, slots: { header: [{ type: "Badge", text: "Live" }] } },
        });
        expect(screen.getByTestId("badge").textContent).toBe("Live");
    });
    test("the submit sends the field values once and the done pill replaces the buttons", async () => {
        let resolveSignal = () => { };
        const { injectSignal } = renderForm(contactForm(), {
            injectSignal: () => new Promise((resolve) => (resolveSignal = resolve)),
        });
        type("Your question", "Do you open on holidays?\nAnd on Sundays?");
        type("E-mail", "anna@example.com");
        act(() => {
            fireEvent.click(screen.getByLabelText("Newsletter"));
        });
        const sendButton = screen.getByRole("button", { name: "Send question" });
        act(() => {
            fireEvent.click(sendButton);
            fireEvent.click(sendButton);
        });
        expect(injectSignal).toHaveBeenCalledTimes(1);
        expect(injectSignal.mock.calls[0][0]).toBe('ComposedView form "contactRequest" submitted:\n- message: Do you open on holidays?\\nAnd on Sundays?\n- email: anna@example.com\n- newsletter: true');
        await act(async () => resolveSignal(true));
        expect(screen.getByText("Sent")).toBeTruthy();
        expect(screen.queryByRole("button", { name: "Send question" })).toBeNull();
        expect(screen.getByLabelText("E-mail").closest("fieldset").disabled).toBe(true);
    });
    test("a required field blocks the submit until it is filled", async () => {
        const { injectSignal } = renderForm(contactForm({ requiredFields: ["message"] }));
        act(() => {
            fireEvent.click(screen.getByRole("button", { name: "Send question" }));
        });
        expect(injectSignal).not.toHaveBeenCalled();
        expect(screen.getByText("Please fill in the required fields.")).toBeTruthy();
        type("Your question", "   ");
        expect(screen.getByText("Please fill in the required fields.")).toBeTruthy();
        type("Your question", "Parking?");
        expect(screen.queryByText("Please fill in the required fields.")).toBeNull();
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Send question" }));
        });
        expect(injectSignal).toHaveBeenCalledTimes(1);
    });
    test("an undelivered signal keeps the form and the retry sends again", async () => {
        const outcomes = [false, true];
        const { injectSignal } = renderForm(contactForm({ fields: ["email"] }), {
            injectSignal: async () => outcomes.shift() ?? true,
        });
        type("E-mail", "anna@example.com");
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Send question" }));
        });
        expect(screen.getByText("Something went wrong. Please try again.")).toBeTruthy();
        expect(injectSignal.mock.calls[0][0]).toBe('ComposedView form "contactRequest" submitted:\n- email: anna@example.com');
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Send question" }));
        });
        expect(injectSignal).toHaveBeenCalledTimes(2);
        await waitFor(() => expect(screen.getByText("Sent")).toBeTruthy());
    });
    test("the decline sends its own sentence", async () => {
        const { injectSignal } = renderForm(contactForm({ declineLabel: "Not now" }));
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Not now" }));
        });
        expect(injectSignal).toHaveBeenCalledWith('ComposedView form "contactRequest" declined.');
        expect(screen.getByText("Declined")).toBeTruthy();
    });
    test("a replay keeps the inputs but offers no submit; the e-mail capture shows no action row", () => {
        renderForm(contactForm(), { interactive: false });
        expect(screen.queryByRole("button")).toBeNull();
        // the options of a reply that can no longer send are inert, not only its submit
        expect(screen.getByLabelText("E-mail").closest("fieldset")?.disabled).toBe(true);
        expect(screen.getByText("This form can only be sent from the newest reply.")).toBeTruthy();
        cleanup();
        renderForm(contactForm(), { isEmailRender: true, interactive: false });
        expect(screen.queryByRole("button")).toBeNull();
        expect(screen.queryByText("This form can only be sent from the newest reply.")).toBeNull();
    });
    test("a name the signal cannot carry renders the body without a submit and reports failed", () => {
        const { reports } = renderForm(contactForm({ name: 'contact "form"' }));
        expect(screen.getByLabelText("E-mail")).toBeTruthy();
        expect(screen.queryByRole("button")).toBeNull();
        expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
    });
    test("a Form inside a Form gives its inputs to the outer form and reports failed", async () => {
        const { injectSignal, reports } = renderForm({
            state: { message: "", phone: "" },
            view: {
                type: "Form",
                name: "outer",
                children: [input("Message", "message"), { type: "Form", name: "inner", children: [input("Phone", "phone")] }],
            },
        });
        expect(screen.getAllByRole("button")).toHaveLength(1);
        expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_NODE" });
        type("Phone", "0251 123");
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Send" }));
        });
        expect(injectSignal).toHaveBeenCalledWith('ComposedView form "outer" submitted:\n- message:\n- phone: 0251 123');
    });
});
describe("findStoredFormAnswer", () => {
    const transcript = [
        { id: 10, role: "ASSISTANT", message: "pick a picture" },
        { id: 11, role: "USER", messageType: "SIGNAL", message: 'ComposedView form "picturePick" submitted:\n- picked: 16844' },
        { id: 12, role: "ASSISTANT", message: "done" },
    ];
    test("a submit signal after the host message restores Sent", () => {
        expect(findStoredFormAnswer(transcript, 10, "picturePick")).toBe("sent");
    });
    test("a decline signal restores Declined", () => {
        expect(findStoredFormAnswer([transcript[0], { id: 11, role: "USER", messageType: "SIGNAL", message: 'ComposedView form "picturePick" declined.' }], "10", "picturePick")).toBe("declined");
    });
    test("another form's signal, an owner message first, or an unknown host message restore nothing", () => {
        expect(findStoredFormAnswer(transcript, 10, "otherForm")).toBeUndefined();
        expect(findStoredFormAnswer([transcript[0], { id: 13, role: "USER", message: "never mind" }, transcript[1]], 10, "picturePick")).toBeUndefined();
        expect(findStoredFormAnswer(transcript, 99, "picturePick")).toBeUndefined();
        expect(findStoredFormAnswer(null, 10, "picturePick")).toBeUndefined();
    });
    test("a reloaded form shows Sent instead of the replay line", () => {
        const tree = readComposedViewTree(contactForm(), getComposedNodeSchema());
        const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
        render(<Provider store={store}>
        <StandaloneConversationWidgetProvider callbacks={{
                messages: [
                    { id: 5, role: "ASSISTANT" },
                    { id: 6, role: "USER", messageType: "SIGNAL", message: 'ComposedView form "contactRequest" submitted:\n- message: hi' },
                ],
            }}>
          <ComposedViewComponentsContextProvider>
            <ComposedViewRenderer tree={tree} interactive={false} isEmailRender={false} messageId={5} agentSpaceKey={7} onReport={() => undefined}/>
          </ComposedViewComponentsContextProvider>
        </StandaloneConversationWidgetProvider>
      </Provider>);
        expect(screen.getByText("Sent")).toBeTruthy();
        expect(screen.queryByText("This form can only be sent from the newest reply.")).toBeNull();
    });
});
