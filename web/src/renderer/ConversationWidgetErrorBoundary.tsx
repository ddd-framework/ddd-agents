import React from "react";
import { reportWidgetCrash } from "../provider/environment";

interface Props {
  /** Canonical widget name, for the error log. */
  widgetName: string;
  /** Called once when the widget threw — the render report marks the block `failed`. */
  onCrash?: () => void;
  children: React.ReactNode;
}

interface State {
  hasError: boolean;
}

/**
 * Per-widget error boundary. Widget props originate from LLM output, so a
 * malformed payload can throw anywhere in a widget's render/effects — without a
 * boundary one bad widget unmounts the entire chat message. On error the
 * widget renders nothing (the surrounding prose stays intact) and the failure
 * is logged with the widget name.
 */
export class ConversationWidgetErrorBoundary extends React.Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(): State {
    return { hasError: true };
  }

  componentDidCatch(error: Error, errorInfo: React.ErrorInfo): void {
    console.error(`[ConversationMarkdown] widget "${this.props.widgetName}" crashed`, error, errorInfo);
    reportWidgetCrash(this.props.widgetName, error, errorInfo);
    this.props.onCrash?.();
  }

  render(): React.ReactNode {
    if (this.state.hasError) {
      return null;
    }

    return this.props.children;
  }
}
