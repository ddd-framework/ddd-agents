import React from "react";
import { Markdown } from "../../provider/components";
/** The answer body — rendered as markdown so headings, lists and inline links from the provider show properly. */
export const WebSearchAnswer = (props) => {
    const { answer } = props;
    if (answer.trim() === "")
        return null;
    return <Markdown markdown={answer} usesTextComponent/>;
};
