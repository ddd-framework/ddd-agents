import { KeyboardEvent, PointerEvent as ReactPointerEvent, RefObject, useEffect, useRef, useState } from "react";
import { flushSync } from "react-dom";
import {
  CANVAS_MIN_WIDTH_PX,
  CHAT_COLUMN_MIN_WIDTH_PX,
} from "../../renderer/canvas/canvas-behaviours";

const KEY_STEP_PX = 24;
const NEAR_PX = 40;
const RUBBER_MAX_PX = 44;
const RUBBER_K = 70;
const MAGNET_PX = 6;
const MAGNET_X = 0.4;
const MAGNET_Y = 0.12;
const DRAG_TRAVEL_PX = 12;
const LEAN_PX = DRAG_TRAVEL_PX;
const CLOSE_PULL_PX = 64;
const ARM_DELAY_MS = 350;
const LEAN_REST_MS = 150;

/** `idle` (hidden until the pointer comes near), `hover`, `drag`, `armed` (pulled past the narrowest and held: release closes). */
export type CanvasResizeTab = "idle" | "hover" | "drag" | "armed";

export const rubberBand = (distance: number): number =>
  Math.sign(distance) * RUBBER_MAX_PX * (1 - Math.exp(-Math.abs(distance) / RUBBER_K));

export const canvasWidthLimits = (rootWidth: number, inset: number): { min: number; max: number } => {
  const min = CANVAS_MIN_WIDTH_PX + inset * 2;
  return { min, max: Math.max(min, rootWidth - CHAT_COLUMN_MIN_WIDTH_PX) };
};

export const clampCanvasWidth = (width: number, rootWidth: number, inset: number): number => {
  const { min, max } = canvasWidthLimits(rootWidth, inset);
  return Math.round(Math.min(max, Math.max(min, width)));
};

interface UseCanvasResizeOptions {
  /** The split's root: the canvas pane's right edge is its right edge, and `--agents-canvas-width-drag` is written onto it while dragging. */
  rootRef: RefObject<HTMLElement | null>;
  /** The canvas card's inset from its pane edge — the handle sits on the card's edge. */
  inset: number;
  /** The pane width the customer set (px), or `null` for the canvas's default split. */
  width: number | null;
  onWidthChange: (width: number | null) => void;
  onClose: () => void;
}

/**
 * The canvas's left edge drags. The edge follows the pointer through a CSS variable on the split's root (no render per
 * move); the width is committed once on release. Pulled `CLOSE_PULL_PX` past the narrowest and held, the tab arms and
 * release closes the canvas.
 */
export const useCanvasResize = (options: UseCanvasResizeOptions) => {
  const { rootRef, inset, width, onWidthChange, onClose } = options;
  const handleRef = useRef<HTMLDivElement>(null);
  const [isHovered, setIsHovered] = useState(false);
  const [isDragging, setIsDragging] = useState(false);
  const [isArmed, setIsArmed] = useState(false);
  const [tabX, setTabX] = useState(0);
  const isDraggingRef = useRef(false);
  const timers = useRef({ arm: 0, lean: 0 });
  const latest = useRef({ inset, onWidthChange, onClose });
  latest.current = { inset, onWidthChange, onClose };

  useEffect(() => {
    const root = rootRef.current;
    const pendingTimers = timers.current;
    return () => {
      window.clearTimeout(pendingTimers.arm);
      window.clearTimeout(pendingTimers.lean);
      // Unmounted mid-drag (the canvas closed): the split must not keep following a pointer nobody holds.
      delete root?.dataset.resizing;
      root?.style.removeProperty("--agents-canvas-width-drag");
    };
  }, [rootRef]);

  const magnetise = (clientX: number | null, clientY: number | null, reach = MAGNET_PX) => {
    const handle = handleRef.current;
    if (!handle) return;
    const rect = handle.getBoundingClientRect();
    const cap = (value: number) => Math.max(-reach, Math.min(reach, value));
    const x = clientX === null ? 0 : cap((clientX - (rect.left + rect.width / 2)) * MAGNET_X);
    const y = clientY === null ? 0 : cap((clientY - (rect.top + rect.height / 2)) * MAGNET_Y);
    handle.style.setProperty("--agents-resize-magnet-x", `${x.toFixed(2)}px`);
    handle.style.setProperty("--agents-resize-magnet-y", `${y.toFixed(2)}px`);
  };

  const setNear = (isNear: boolean) => {
    const handle = handleRef.current;
    if (handle && (handle.dataset.near === "true") !== isNear) {
      handle.dataset.near = String(isNear);
    }
  };

  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;
    const onPointerMove = (event: PointerEvent) => {
      if (isDraggingRef.current) return;
      const rect = handleRef.current?.getBoundingClientRect();
      const isNear =
        !!rect &&
        event.clientY >= rect.top &&
        event.clientY <= rect.bottom &&
        Math.abs(event.clientX - (rect.left + rect.width / 2)) <= NEAR_PX;
      setNear(isNear);
      if (isNear) magnetise(event.clientX, event.clientY);
      else magnetise(null, null);
    };
    const onPointerLeave = () => {
      if (isDraggingRef.current) return;
      setNear(false);
      magnetise(null, null);
    };
    root.addEventListener("pointermove", onPointerMove);
    root.addEventListener("pointerleave", onPointerLeave);
    return () => {
      root.removeEventListener("pointermove", onPointerMove);
      root.removeEventListener("pointerleave", onPointerLeave);
    };
  }, [rootRef]);

  const rootWidth = () => rootRef.current?.clientWidth ?? 0;
  const currentWidth = () => {
    const root = rootRef.current;
    const handle = handleRef.current;
    if (!root || !handle) return CANVAS_MIN_WIDTH_PX;
    const handleCenter = handle.getBoundingClientRect().left + handle.offsetWidth / 2;
    return root.getBoundingClientRect().right - handleCenter + latest.current.inset;
  };

  const startDrag = (event: ReactPointerEvent<HTMLElement>) => {
    const root = rootRef.current;
    if (event.button !== 0 || !root) return;
    event.preventDefault();
    const handle = event.currentTarget;
    try {
      handle.setPointerCapture(event.pointerId);
    } catch {
      // A pointer the browser does not know (synthetic): the strip still gets its moves while over it.
    }
    const { min, max } = canvasWidthLimits(rootWidth(), latest.current.inset);
    const grabOffset = event.clientX - handle.getBoundingClientRect().left - handle.offsetWidth / 2;
    let lastX = event.clientX;
    let lean = 0;
    let isArmedNow = false;
    let draggedWidth: number | null = null;
    isDraggingRef.current = true;
    setIsDragging(true);
    // Resize mode reads `--agents-canvas-width-drag`: seed it, or a press without a move leaves the split without a width.
    root.style.setProperty("--agents-canvas-width-drag", `${clampCanvasWidth(currentWidth(), rootWidth(), latest.current.inset)}px`);
    root.dataset.resizing = "true";

    const disarm = () => {
      window.clearTimeout(timers.current.arm);
      timers.current.arm = 0;
      if (isArmedNow) {
        isArmedNow = false;
        setIsArmed(false);
      }
    };

    magnetise(null, event.clientY, DRAG_TRAVEL_PX);
    const move = (moveEvent: PointerEvent) => {
      magnetise(null, moveEvent.clientY, DRAG_TRAVEL_PX);
      const desired = root.getBoundingClientRect().right - (moveEvent.clientX - grabOffset) + latest.current.inset;
      const next = clampCanvasWidth(desired, rootWidth(), latest.current.inset);
      draggedWidth = next;
      root.style.setProperty("--agents-canvas-width-drag", `${next}px`);

      // Past the narrowest the edge stops and the tab stretches after the pointer; past the widest it holds its lean.
      const beyond = Math.min(max, Math.max(min, desired)) - desired;
      const moved = moveEvent.clientX - lastX;
      if (moved !== 0) lean = Math.sign(moved) * LEAN_PX;
      lastX = moveEvent.clientX;
      const tabFor = (currentLean: number) =>
        beyond > 0 ? Math.max(LEAN_PX, rubberBand(beyond)) : beyond < 0 ? -LEAN_PX : currentLean;
      setTabX(tabFor(lean));
      window.clearTimeout(timers.current.lean);
      timers.current.lean = window.setTimeout(() => {
        lean = 0;
        setTabX(tabFor(0));
      }, LEAN_REST_MS);

      if (next === min && beyond >= CLOSE_PULL_PX) {
        if (!isArmedNow && !timers.current.arm) {
          timers.current.arm = window.setTimeout(() => {
            timers.current.arm = 0;
            isArmedNow = true;
            setIsArmed(true);
          }, ARM_DELAY_MS);
        }
      } else {
        disarm();
      }
    };

    const finish = () => {
      handle.removeEventListener("pointermove", move);
      handle.removeEventListener("pointerup", finish);
      handle.removeEventListener("pointercancel", finish);
      window.clearTimeout(timers.current.lean);
      const closes = isArmedNow;
      disarm();
      isDraggingRef.current = false;
      setIsDragging(false);
      setTabX(0);
      magnetise(null, null);
      delete root.dataset.resizing;
      // Committed in the same frame the drag variable goes, so the edge never flashes back to the old width.
      flushSync(() => latest.current.onWidthChange(closes ? null : (draggedWidth ?? width)));
      root.style.removeProperty("--agents-canvas-width-drag");
      if (closes) {
        setNear(false);
        setIsHovered(false);
        latest.current.onClose();
      }
    };
    handle.addEventListener("pointermove", move);
    handle.addEventListener("pointerup", finish);
    handle.addEventListener("pointercancel", finish);
  };

  const onKeyDown = (event: KeyboardEvent<HTMLElement>) => {
    const { min, max } = canvasWidthLimits(rootWidth(), inset);
    const next =
      event.key === "ArrowLeft"
        ? currentWidth() + KEY_STEP_PX
        : event.key === "ArrowRight"
          ? currentWidth() - KEY_STEP_PX
          : event.key === "Home"
            ? max
            : event.key === "End"
              ? min
              : null;
    if (next === null) return;
    event.preventDefault();
    onWidthChange(clampCanvasWidth(next, rootWidth(), inset));
  };

  const tab: CanvasResizeTab = isArmed ? "armed" : isDragging ? "drag" : isHovered ? "hover" : "idle";
  const { min, max } = canvasWidthLimits(rootWidth(), inset);
  return {
    tab,
    tabX,
    handleProps: {
      ref: handleRef,
      role: "separator",
      "aria-orientation": "vertical" as const,
      "aria-valuemin": min,
      "aria-valuemax": Math.round(max),
      "aria-valuenow": width ?? undefined,
      tabIndex: 0,
      onPointerDown: startDrag,
      onPointerEnter: () => setIsHovered(true),
      onPointerLeave: () => setIsHovered(false),
      onKeyDown,
      onDoubleClick: () => onWidthChange(null),
    },
  };
};
