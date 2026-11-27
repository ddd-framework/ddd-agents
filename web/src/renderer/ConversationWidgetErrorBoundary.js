import React from "react";
import { reportWidgetCrash } from "../provider/environment";
/**
 * Per-widget error boundary. Widget props originate from LLM output, so a
 * malformed payload can throw anywhere in a widget's render/effects — without a
 * boundary one bad widget unmounts the entire chat message. On error the
 * widget renders nothing (the surrounding prose stays intact) and the failure
 * is logged with the widget name.
 */
export class ConversationWidgetErrorBoundary extends React.Component {
    state = { hasError: false };
    static getDerivedStateFromError() {
        return { hasError: true };
    }
    componentDidCatch(error, errorInfo) {
        console.error(`[ConversationMarkdown] widget "${this.props.widgetName}" crashed`, error, errorInfo);
        reportWidgetCrash(this.props.widgetName, error, errorInfo);
        this.props.onCrash?.();
    }
    render() {
        if (this.state.hasError) {
            return null;
        }
        return this.props.children;
    }
}
