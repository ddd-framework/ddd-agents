import { getAgentsEnvironment, setAgentsEnvironment } from "../provider/environment";

/** What a widget handed to the app during a preview: the action and its target as one line. */
export type WidgetPreviewAppActionLogger = (text: string) => void;

let previewAppActionLogger: WidgetPreviewAppActionLogger | null = null;

/**
 * Stand-ins for the app hooks a widget needs to render at all (`startConversationInPersonalSpace`,
 * `openConnectionPermissions`): outside the chat no app opens a space or its settings, so the preview logs the action
 * instead. A hook the app set stays the app's. Idempotent; the preview page keeps them while it lives.
 */
export const installWidgetPreviewAppHooks = (onAppAction: WidgetPreviewAppActionLogger): void => {
  previewAppActionLogger = onAppAction;
  const environment = getAgentsEnvironment();
  if (environment.startConversationInPersonalSpace && environment.openConnectionPermissions) return;
  setAgentsEnvironment({
    ...environment,
    startConversationInPersonalSpace:
      environment.startConversationInPersonalSpace ??
      ((ownerText: string) => previewAppActionLogger?.(`start in personal space: ${ownerText}`)),
    openConnectionPermissions:
      environment.openConnectionPermissions ??
      ((target) => previewAppActionLogger?.(`open connection permissions: ${JSON.stringify(target)}`)),
  });
};
