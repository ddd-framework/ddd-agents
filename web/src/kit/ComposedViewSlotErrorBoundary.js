import React from "react";
/**
 * One node of a `ComposedView` tree inside its own error boundary (per-slot context): a node that throws
 * renders nothing and is reported `failed` (`RENDER_FAILED`), the rest of the tree stays. React error boundaries
 * require a class.
 */
export class ComposedViewSlotErrorBoundary extends React.Component {
    state = { hasFailed: false };
    static getDerivedStateFromError() {
        return { hasFailed: true };
    }
    componentDidCatch(error) {
        console.error(`[ComposedView] node ${this.props.nodeType} at ${this.props.nodePath} crashed`, error);
        this.props.onRenderFailed(this.props.nodePath, this.props.nodeType);
    }
    render() {
        return this.state.hasFailed ? null : this.props.children;
    }
}
