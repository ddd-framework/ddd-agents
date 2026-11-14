export type WidgetHydrationStatus = "idle" | "loading" | "success" | "error";

export interface WidgetMemory<TData = unknown> {
  data: TData | null;
  status: WidgetHydrationStatus;
  error: string | null;
  loadingInstanceId: string | null;
  inFlight: boolean;
  requestId: number;
  lastLoadedAt: number | null;
}

export interface WidgetHydrationSliceState {
  widgets: Record<string, WidgetMemory>;
}

export interface WidgetHydratorContext {
  signal: AbortSignal;
}

export type WidgetHydrator<TPayload = unknown, TData = unknown> = (
  payload: TPayload,
  ctx: WidgetHydratorContext,
) => Promise<TData>;

export interface WidgetHydratorOptions<TPayload = unknown, TData = unknown> {
  hydrator: WidgetHydrator<TPayload, TData>;
  deriveKey?: (type: string, payload: TPayload) => string;
  isPayloadEqual?: (prev: TPayload | undefined, next: TPayload) => boolean;
  refetchOnNewInstance?: boolean;
  staleMs?: number;
}

export interface RegisteredWidget<TPayload = unknown, TData = unknown>
  extends Required<Omit<WidgetHydratorOptions<TPayload, TData>, "isPayloadEqual">> {
  isPayloadEqual: (prev: TPayload | undefined, next: TPayload) => boolean;
}
