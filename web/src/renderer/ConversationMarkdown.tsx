import React, { useContext, useMemo } from "react";
import { type AgentsMarkdownProps, Markdown } from "../provider/components";
import remarkDirective from "remark-directive";
import remarkWidgetFencePlugin from "./remarkWidgetFencePlugin";
import conversationWidgetAstPlugin from "./conversationWidgetAstPlugin";
import restoreTextDirectivesPlugin from "./restoreTextDirectivesPlugin";
import { conversationWidgetRegistry, getConversationWidgetRegistryRevision } from "./conversationWidgetRegistry";
import { ConversationWidgetHost, ConversationWidgetRenderProps } from "./ConversationWidgetHost";
import { MarkdownLink } from "./MarkdownLink";

export interface ConversationMarkdownProps {
  markdown: string;
  /**
   * Forwarded into every widget instance as `interactive` (and its deprecated alias `fresh`): the widget's controls
   * may be used. True for a live-delivered message and for the newest assistant reply after a reload.
   */
  interactive?: boolean;
  /**
   * Forwarded into every widget instance as `justDelivered`: the message was delivered live right now. The only gate
   * for one-shot side effects (canvas open, draft creation); never true after a reload or on replay.
   */
  justDelivered?: boolean;
  /** Id of the host message, forwarded so a widget can identify the canvas it opens. */
  messageId?: string | number;
  /**
   * When true, widgets are replaced with a lightweight placeholder.
   * Used for old messages to keep the chat scroll cheap.
   */
  hideWidgets?: boolean;
}

/**
 * Render flags delivered via context so the widget component map can live at module
 * scope. The map must keep stable component identities across renders: react-markdown
 * reconciles by element type, so a per-render `Wrapped` closure makes React unmount and
 * remount every widget on each streaming tick — resetting one-shot refs (duplicate
 * drawer opens / POSTs) and re-triggering hydration.
 */
const ConversationWidgetRenderContext = React.createContext<{
  interactive: boolean;
  justDelivered: boolean;
  hideWidgets: boolean;
  messageId?: string | number;
}>({
  interactive: false,
  justDelivered: false,
  hideWidgets: false,
});

// Map every widget name to a component that delegates rendering to ConversationWidgetHost,
// which owns the per-widget concerns (interactive / justDelivered, feature-flag gate, hidden placeholder, block spacing).
// Built lazily: the registry imports every widget and a widget can import AgentChat, which renders this file.
const buildConversationWidgetComponents = () =>
  conversationWidgetRegistry.widgets.reduce<Record<string, React.ComponentType<ConversationWidgetRenderProps>>>(
    (acc, widget) => {
      const Wrapped: React.ComponentType<ConversationWidgetRenderProps> = (innerProps) => {
        const { interactive, justDelivered, hideWidgets, messageId } = useContext(ConversationWidgetRenderContext);

        return (
          <ConversationWidgetHost
            widget={widget}
            interactive={interactive}
            justDelivered={justDelivered}
            messageId={messageId}
            hideWidgets={hideWidgets}
            conversationWidgetRenderProps={innerProps}
          />
        );
      };
      Wrapped.displayName = `ConversationWidget(${widget.name})`;
      acc[widget.name] = Wrapped;
      return acc;
    },
    {},
  );

let markdownComponents: AgentsMarkdownProps["components"] | undefined;
let markdownComponentsRevision = -1;

// Rebuilt only when an app registered more widgets since (`registerConversationWidgets`): the identities stay stable.
const getMarkdownComponents = (): AgentsMarkdownProps["components"] => {
  if (markdownComponents === undefined || markdownComponentsRevision !== getConversationWidgetRegistryRevision()) {
    markdownComponents = {
      ...buildConversationWidgetComponents(),
      a: MarkdownLink,
    } as AgentsMarkdownProps["components"];
    markdownComponentsRevision = getConversationWidgetRegistryRevision();
  }
  return markdownComponents;
};

// remarkWidgetFencePlugin rewrites ```widget fenced blocks into the node type remark-directive parses `::Name{}` into,
// BEFORE conversationWidgetAstPlugin maps them to components — so the model's ```widget egress and the legacy
// `::Name{}` egress share one render path (ConversationWidgetHost + feature-gate + hydration).
// restoreTextDirectivesPlugin runs LAST: remark-directive also parses the `:00` inside "08:00–16:30" as a text
// directive (digits are valid names) and swallowed it in the customer chat — text directives are never widgets.
const remarkPlugins = [
  remarkDirective,
  remarkWidgetFencePlugin,
  conversationWidgetAstPlugin,
  restoreTextDirectivesPlugin,
];

export const ConversationMarkdown = (props: ConversationMarkdownProps) => {
  const { markdown, interactive = false, justDelivered = false, hideWidgets = false, messageId } = props;

  const renderContext = useMemo(
    () => ({ interactive, justDelivered, hideWidgets, messageId }),
    [interactive, justDelivered, hideWidgets, messageId],
  );

  return (
    <ConversationWidgetRenderContext.Provider value={renderContext}>
      <Markdown remarkPlugins={remarkPlugins} components={getMarkdownComponents()} markdown={markdown} />
    </ConversationWidgetRenderContext.Provider>
  );
};
