import React, { useEffect, useRef } from "react";
import { conversationWidgetRegistry } from "../renderer/conversationWidgetRegistry";
import { resolveWidgetName } from "../renderer/widget-name-resolver";
import { buildWidgetAgentSpaceKeyProps } from "../renderer/_agent-space/widget-agent-space-key-attribute";
import { ReadOnlyConversationWidgetProvider } from "../renderer/ConversationWidgetContext";
import { ConversationWidgetRenderContext } from "../renderer/ConversationWidgetRenderContext";
import {
  WidgetImageRenderContext,
  WidgetImageLiveHydrationContext,
  WidgetImageReadinessContext,
} from "./WidgetImageRenderContext";
import type { WidgetImageRenderBootstrap } from "./widget-image-render.types";
import type { createWidgetImageReadiness } from "./widget-image-readiness";
import styles from "./WidgetImageRender.module.scss";

/**
 * Capture-only components by wire name: a frozen render shows the widget's visual from its complete snapshot, without
 * mounting the live fetch / OAuth / polling wrappers. An app adds its own with `registerStaticWidgetImageComponents`.
 */
export const staticWidgetImageComponents: Record<string, React.ComponentType<Record<string, unknown>>> = {};

/** Adds an app's capture-only components (wire name → component). Runs outside React, before the first render. */
export const registerStaticWidgetImageComponents = (
  components: Readonly<Record<string, React.ComponentType<Record<string, unknown>>>>,
): void => {
  Object.assign(staticWidgetImageComponents, components);
};

type Readiness = ReturnType<typeof createWidgetImageReadiness>;
type HostProps = { bootstrap: WidgetImageRenderBootstrap; readiness: Readiness };

// React error boundaries require a class. Never log customer payloads or send telemetry from capture.
export class WidgetImageCaptureBoundary extends React.Component<
  { children: React.ReactNode; fail: Readiness["fail"] },
  { failed: boolean }
> {
  state = { failed: false };
  static getDerivedStateFromError() {
    return { failed: true };
  }
  componentDidCatch() {
    this.props.fail("WIDGET_RENDER_FAILED");
  }
  render() {
    return this.state.failed ? null : this.props.children;
  }
}

/**
 * The static image render of one widget (the e-mail capture): the registered widget in its e-mail presentation, inside
 * the read-only widget context, with the capture's frozen or live hydration. A frozen render takes the widget's static
 * capture component when the app registered one (`registerStaticWidgetImageComponents`). The app mounts it inside its
 * own store and theme (`WidgetImageCaptureBoundary` around it).
 */
export const WidgetImageRender = (props: HostProps) => {
  const { bootstrap, readiness } = props;
  const ref = useRef<HTMLDivElement>(null);
  const name = resolveWidgetName(bootstrap.input.widgetName);
  const entry = conversationWidgetRegistry.widgets.find((widget) => widget.name === name);
  useEffect(() => {
    if (!entry) readiness.fail("UNKNOWN_WIDGET");
    else if (ref.current) return readiness.observe(ref.current);
  }, [entry, readiness]);
  if (!entry) return null;
  const Component =
    bootstrap.input.mode === "live" ? entry.component : (staticWidgetImageComponents[name ?? ""] ?? entry.component);
  return (
    <div ref={ref} className={styles.root} inert>
      <ConversationWidgetRenderContext.Provider value="email">
        <WidgetImageReadinessContext.Provider value={readiness}>
          <ReadOnlyConversationWidgetProvider agentSpaceKey={bootstrap.siteId}>
            <WidgetImageRenderContext.Provider value={bootstrap.input.hydration}>
              <WidgetImageLiveHydrationContext.Provider value={bootstrap.input.mode === "live"}>
                <Component
                  {...bootstrap.input.props}
                  {...buildWidgetAgentSpaceKeyProps(bootstrap.siteId)}
                  interactive={false}
                  fresh={false}
                  justDelivered={false}
                  openedByPage={false}
                />
              </WidgetImageLiveHydrationContext.Provider>
            </WidgetImageRenderContext.Provider>
          </ReadOnlyConversationWidgetProvider>
        </WidgetImageReadinessContext.Provider>
      </ConversationWidgetRenderContext.Provider>
    </div>
  );
};

/** The slice the capture reads its hydration state from; any store that carries it (the capture's, a spec's). */
interface StoreWithWidgetHydration {
  getState: () => { AIExtensionsWidgetHydration: { widgets: Record<string, { status: string }> } };
  subscribe: (listener: () => void) => () => void;
}

/**
 * Holds the capture's readiness open while any widget hydration is idle or loading, and fails it on a hydration error.
 * Every shared hydrator counts — a widget's own, and each `$source` binding of a `ComposedView` tree, which reads through
 * the same slice (`ComposedViewDataSource:<name>`), so the shot waits for the tree's mail-time rows.
 */
export const followWidgetHydrationReadiness = (store: StoreWithWidgetHydration, readiness: Readiness) =>
  store.subscribe(() => {
    const widgets = Object.values(store.getState().AIExtensionsWidgetHydration.widgets);
    if (widgets.some((widget) => widget.status === "error")) readiness.fail("WIDGET_HYDRATION_FAILED");
    readiness.setHydrationPending(widgets.some((widget) => widget.status === "loading" || widget.status === "idle"));
  });
