export interface WidgetImageRenderInput {
  mode?: "live" | "frozen";
  widgetName: string;
  props: Record<string, unknown>;
  /** Hydrator type (or exact derived cache key) → already resolved, authorized result. */
  hydration: Record<string, unknown>;
  /** Optional frozen GET responses for older widgets that do not use the shared hydrator. */
  responses?: Record<string, unknown>;
  settings: Record<string, unknown>;
  locale: string;
  translations?: Record<string, string>;
  width?: number;
  maxHeight?: number;
}

export interface WidgetImageRenderBootstrap {
  snapshotId: string;
  attemptId: string;
  inputSha256: string;
  renderVersion: string;
  userId: number;
  siteId: number;
  expiresAt: number;
  /** Account bearer token for live hydration; frozen renders have no API credentials. */
  authToken: string | null;
  input: WidgetImageRenderInput;
}

export interface WidgetImageReadiness {
  version: 1;
  state: "loading" | "ready" | "failed";
  snapshot_id?: string;
  attempt_id?: string;
  input_sha256?: string;
  render_version?: string;
  rect?: { x: number; y: number; width: number; height: number };
  error_code?: string;
}

declare global {
  interface Window {
    /** The image capture's readiness signal (`publishWidgetImageReadiness` also writes it under the app's declared names). */
    __AGENTS_WIDGET_IMAGE_V1__?: WidgetImageReadiness;
  }
}
