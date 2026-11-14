import React from "react";

interface ComposedViewSlotErrorBoundaryProps {
  /** Where the node sits in the tree (`view.children[2]`), for the report. */
  nodePath: string;
  nodeType: string;
  onRenderFailed: (nodePath: string, nodeType: string) => void;
  children: React.ReactNode;
}

interface ComposedViewSlotErrorBoundaryState {
  hasFailed: boolean;
}

/**
 * One node of a `ComposedView` tree inside its own error boundary (per-slot context): a node that throws
 * renders nothing and is reported `failed` (`RENDER_FAILED`), the rest of the tree stays. React error boundaries
 * require a class.
 */
export class ComposedViewSlotErrorBoundary extends React.Component<
  ComposedViewSlotErrorBoundaryProps,
  ComposedViewSlotErrorBoundaryState
> {
  state: ComposedViewSlotErrorBoundaryState = { hasFailed: false };

  static getDerivedStateFromError(): ComposedViewSlotErrorBoundaryState {
    return { hasFailed: true };
  }

  componentDidCatch(error: Error): void {
    console.error(`[ComposedView] node ${this.props.nodeType} at ${this.props.nodePath} crashed`, error);
    this.props.onRenderFailed(this.props.nodePath, this.props.nodeType);
  }

  render(): React.ReactNode {
    return this.state.hasFailed ? null : this.props.children;
  }
}
