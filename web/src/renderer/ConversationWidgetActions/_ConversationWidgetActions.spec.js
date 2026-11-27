import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { ConversationWidgetRenderContext } from "../ConversationWidgetRenderContext";
import { ConversationWidgetActions } from "./ConversationWidgetActions";
import { WidgetFooter } from "../../widgets/_shared/WidgetFooter";
describe("email widget actions", () => {
    it("preserves ordinary conversation actions without adding a DOM wrapper", () => {
        expect(renderToStaticMarkup(<ConversationWidgetActions>
          <button>Edit</button>
        </ConversationWidgetActions>)).toBe("<button>Edit</button>");
    });
    it("omits the complete action area and empty footer but keeps content and status", () => {
        const markup = renderToStaticMarkup(<ConversationWidgetRenderContext.Provider value="email">
        <article>
          <p>Review text</p>
          <ConversationWidgetActions>
            <footer>
              <button>Reply</button>
            </footer>
          </ConversationWidgetActions>
          <WidgetFooter>
            <button>Edit</button>
          </WidgetFooter>
          <WidgetFooter actionOnly={false}>
            <span>Published</span>
          </WidgetFooter>
        </article>
      </ConversationWidgetRenderContext.Provider>);
        expect(markup).toContain("Review text");
        expect(markup).toContain("Published");
        expect(markup).not.toMatch(/button|footer|Reply|Edit/);
        expect((markup.match(/<div/g) ?? []).length).toBe(1);
    });
});
