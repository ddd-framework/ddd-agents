import { useIsEmailWidgetRender } from "../ConversationWidgetRenderContext";
/** Wrap the entire action area, including its footer/divider, to omit it from email images. */
export const ConversationWidgetActions = (props) => {
    const { children } = props;
    const isEmailRender = useIsEmailWidgetRender();
    return isEmailRender ? null : <>{children}</>;
};
