import { useMemo } from "react";
import { useAgentsEnvironment } from "./AgentsProvider";
/** Opens a link the package's default way: a new tab, without opener or referrer. */
const openInNewTab = (href) => {
    window.open(href, "_blank", "noopener,noreferrer");
};
/**
 * Opens a link through the environment's `onNavigate` (the host decides: an in-app route, a new tab, nothing), else in
 * a new tab. `event` is the click that asked for it, when there is one.
 */
export const openLinkThroughEnvironment = (environment, href, event) => {
    if (environment.onNavigate?.(href, event) === true)
        return;
    openInNewTab(href);
};
const readNativeEvent = (event) => event === undefined ? null : "nativeEvent" in event ? event.nativeEvent : event;
/** The link navigation of this subtree (`AgentsEnvironment.onNavigate`). */
export const useAgentsLinkNavigation = () => {
    const { onNavigate } = useAgentsEnvironment();
    return useMemo(() => ({
        openLink: (href, event) => openLinkThroughEnvironment({ onNavigate }, href, readNativeEvent(event)),
        handleLinkClick: (event, href) => {
            const target = href ?? event.currentTarget.getAttribute("href") ?? "";
            if (!target || !onNavigate)
                return;
            if (onNavigate(target, event.nativeEvent) === true)
                event.preventDefault();
        },
    }), [onNavigate]);
};
/**
 * The anchor every package link renders: a new tab without opener or referrer, unless the host's `onNavigate` takes
 * the click (an in-app route). A host's own widget uses it for the same behaviour.
 */
export const AgentsLink = (props) => {
    const { node, href, onClick, children, ...anchorProps } = props;
    const { handleLinkClick } = useAgentsLinkNavigation();
    return (<a target="_blank" rel="noopener noreferrer" {...anchorProps} href={href} onClick={(event) => {
            onClick?.(event);
            if (!event.defaultPrevented && href)
                handleLinkClick(event, href);
        }}>
      {children}
    </a>);
};
