import { Button } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { useEffect, useState } from "react";
import { ConversationWidgetActions } from "../../../renderer/ConversationWidgetActions/ConversationWidgetActions";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { loadHighlighterWithLanguage, resolveHighlightLanguage } from "./rich-libraries";
import { RichNodeBadge, RichNodeContentSkeleton, RichNodeFrame } from "./RichNodeFrame";
import styles from "./Code.module.scss";

/** Lines shown before "Show all lines"; the rest stays one click away. */
export const CODE_COLLAPSED_LINE_COUNT = 24;

const COPIED_CONFIRMATION_MS = 2000;

/** How a language name reads in the header — the common names in their usual spelling, the rest as written. */
const LANGUAGE_DISPLAY_NAMES: Record<string, string> = {
  bash: "Bash",
  cpp: "C++",
  csharp: "C#",
  css: "CSS",
  dockerfile: "Dockerfile",
  graphql: "GraphQL",
  html: "HTML",
  javascript: "JavaScript",
  js: "JavaScript",
  json: "JSON",
  kotlin: "Kotlin",
  markdown: "Markdown",
  php: "PHP",
  python: "Python",
  scss: "SCSS",
  sql: "SQL",
  ts: "TypeScript",
  typescript: "TypeScript",
  xml: "XML",
  yaml: "YAML",
};

const readLanguageDisplayName = (language: unknown): string | undefined => {
  if (typeof language !== "string" || language.trim() === "") return undefined;
  const writtenLanguage = language.trim();
  const lowercasedLanguage = writtenLanguage.toLowerCase();
  return Object.prototype.hasOwnProperty.call(LANGUAGE_DISPLAY_NAMES, lowercasedLanguage)
    ? LANGUAGE_DISPLAY_NAMES[lowercasedLanguage]
    : writtenLanguage;
};

type CodeHighlighting = { status: "plain" } | { status: "highlighted"; html: string };

/**
 * The `Code` node (`CodeComposedViewNode.php`): source code in a tile with the
 * language (or the title) in its header, a copy button and a line-wrap toggle. The plain code shows at once; the
 * highlight.js grammar of the language loads on first use and colours it (highlight.js escapes the source, so the
 * coloured markup holds no markup of the code's own). An unknown language stays plain text. Long code shows its first
 * {@link CODE_COLLAPSED_LINE_COUNT} lines with "Show all lines". The e-mail capture shows no controls and wraps lines.
 */
const CodeNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const { reportNodeStatus, isEmailRender } = runtime;
  const code = typeof nodeProps.code === "string" ? nodeProps.code.replace(/\n+$/, "") : "";
  const grammarName = resolveHighlightLanguage(nodeProps.language);
  const languageDisplayName = readLanguageDisplayName(nodeProps.language);
  const title = typeof nodeProps.title === "string" && nodeProps.title.trim() !== "" ? nodeProps.title : undefined;
  const [highlighting, setHighlighting] = useState<CodeHighlighting>({ status: "plain" });
  const [isWrapped, setIsWrapped] = useState(nodeProps.wrap === true);
  const [isExpanded, setIsExpanded] = useState(false);
  const [isCopied, setIsCopied] = useState(false);
  const lineCount = code === "" ? 0 : code.split("\n").length;
  const isCollapsible = lineCount > CODE_COLLAPSED_LINE_COUNT;

  useEffect(() => {
    setHighlighting({ status: "plain" });
    if (grammarName === undefined || code === "") return;
    let isCurrent = true;
    loadHighlighterWithLanguage(grammarName)
      .then((highlighter) => {
        if (!isCurrent) return;
        const highlightedHtml = highlighter.highlight(code, { language: grammarName, ignoreIllegals: true }).value;
        setHighlighting({ status: "highlighted", html: highlightedHtml });
      })
      // A grammar that does not load leaves the plain code, which is complete — nothing is lost but the colours.
      .catch(() => undefined);
    return () => {
      isCurrent = false;
    };
  }, [code, grammarName]);

  useEffect(() => {
    if (isPending) reportNodeStatus("pending");
    else if (code === "") reportNodeStatus("empty", "NO_DATA", "the code block is empty");
    else reportNodeStatus("mounted");
  }, [code, isPending, reportNodeStatus]);

  useEffect(() => {
    if (!isCopied) return;
    const confirmationTimer = setTimeout(() => setIsCopied(false), COPIED_CONFIRMATION_MS);
    return () => clearTimeout(confirmationTimer);
  }, [isCopied]);

  const copyCode = () => {
    void navigator.clipboard
      ?.writeText(code)
      .then(() => setIsCopied(true))
      .catch(() => undefined);
  };

  // The code is bound to data that has not arrived yet: the tile with its header and a code-shaped skeleton.
  if (isPending) {
    return (
      <RichNodeFrame sizeClass={sizeClass} title={title ?? languageDisplayName} isTileWhenNested testId={testId}>
        <RichNodeContentSkeleton height={160} testId={`${testId}-skeleton`} />
      </RichNodeFrame>
    );
  }
  // No code: the node renders nothing and reports `empty` (one muted line or nothing).
  if (code === "") return null;

  const linesWrap = isWrapped || isEmailRender;
  const isClipped = isCollapsible && !isExpanded;
  const actions = isEmailRender ? undefined : (
    <ConversationWidgetActions>
      <div className={styles.actions}>
        <Button
          variant="muted"
          size="small"
          icon="text"
          pressed={isWrapped}
          testId={`${testId}-wrap`}
          onClick={() => setIsWrapped((wasWrapped) => !wasWrapped)}
        >
          {"Wrap lines"}
        </Button>
        <Button
          variant="muted"
          size="small"
          icon={isCopied ? "check" : "duplicate"}
          onClick={copyCode}
          testId={`${testId}-copy`}
        >
          {isCopied ? "Copied" : "Copy"}
        </Button>
      </div>
    </ConversationWidgetActions>
  );

  return (
    <RichNodeFrame
      sizeClass={sizeClass}
      title={title ?? languageDisplayName ?? undefined}
      badge={title && languageDisplayName ? <RichNodeBadge>{languageDisplayName}</RichNodeBadge> : undefined}
      actions={actions}
      isTileWhenNested
      testId={testId}
    >
      <div className={classNames(styles.codeArea, isClipped && styles.clipped)}>
        {highlighting.status === "highlighted" ? (
          <pre className={classNames(styles.code, linesWrap && styles.wrapped)}>
            <code className="hljs" dangerouslySetInnerHTML={{ __html: highlighting.html }} />
          </pre>
        ) : (
          <pre className={classNames(styles.code, linesWrap && styles.wrapped)}>
            <code>{code}</code>
          </pre>
        )}
      </div>
      {isCollapsible && !isEmailRender ? (
        <div className={styles.expandRow}>
          <Button
            variant="muted"
            size="small"
            rounded
            icon={isExpanded ? "caretUp" : "caretDown"}
            replacements={{ lineCount }}
            testId={`${testId}-expand`}
            onClick={() => setIsExpanded((wasExpanded) => !wasExpanded)}
          >
            {isExpanded ? "Show fewer lines" : "Show all %lineCount% lines"}
          </Button>
        </div>
      ) : null}
    </RichNodeFrame>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Code", component: CodeNode };
