import { useLayoutEffect, useState } from "react";

/**
 * A row of buttons that turns into a full-width column once it would wrap: `data-stacked` goes on the row (its CSS lays
 * the column out). The row is measured as a row whenever its width or `items` change, so it goes back as soon as the
 * buttons fit. Attach the returned callback ref to the row, which may mount later than the hook's host.
 */
export const useStackWhenWrapped = <T extends HTMLElement>(items: unknown) => {
  const [row, setRow] = useState<T | null>(null);

  useLayoutEffect(() => {
    if (!row) return;
    let width = -1;
    const measure = () => {
      // Only the row's width decides; its own height changing (stacked or not) must not loop.
      if (row.clientWidth === width) return;
      width = row.clientWidth;
      delete row.dataset.stacked;
      const buttons = Array.from(row.children) as HTMLElement[];
      if (buttons.some((button) => button.offsetTop !== buttons[0].offsetTop)) row.dataset.stacked = "";
    };
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(row);
    return () => observer.disconnect();
  }, [row, items]);

  return setRow;
};
