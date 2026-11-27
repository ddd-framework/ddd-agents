import { useLinkComponent } from "@mgamadeus/ddd-admin-ui/primitives";
/** `#<conversationId>` as the host's router link (no full page load inside the admin app), or as text without a link. */
export const AgentVoiceCallConversationLink = ({ conversationId, href, linkComponent }) => {
    const Link = useLinkComponent(linkComponent);
    if (!href)
        return <span className="font-mono text-xs">#{conversationId}</span>;
    return (<Link href={href} className="font-mono text-xs underline-offset-2 hover:underline">
      #{conversationId}
    </Link>);
};
