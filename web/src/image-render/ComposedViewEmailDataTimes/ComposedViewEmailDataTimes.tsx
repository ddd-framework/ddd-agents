import { Text } from "../../provider/components";
import { useMemo, useState } from "react";
import { useAgentsOwnerSettings } from "../../provider/AgentsProvider";
import { translateText } from "../../provider/environment";
import type { ComposedViewTree } from "../../renderer/widget-tree/composed-view-tree";
import { readComposedViewDataTimes } from "../composed-view-data-times";
import styles from "./ComposedViewEmailDataTimes.module.scss";

const FALLBACK_DATE_LOCALE = "en-GB";

/** A moment in the owner's date locale and zone (`25.10.2026, 08:00`); without a usable zone, the capture's own. */
export const formatDataTime = (moment: Date, dateLocale: string, timeZone: string | null | undefined): string => {
  const formatOptions: Intl.DateTimeFormatOptions = { dateStyle: "medium", timeStyle: "short" };
  try {
    return new Intl.DateTimeFormat(dateLocale, timeZone ? { ...formatOptions, timeZone } : formatOptions).format(
      moment,
    );
  } catch {
    return new Intl.DateTimeFormat(FALLBACK_DATE_LOCALE, formatOptions).format(moment);
  }
};

export interface ComposedViewEmailDataTimesProps {
  tree: ComposedViewTree;
}

/**
 * The time of each data path of an e-mailed `ComposedView`, as ONE caption line under the tree: a report
 * mail mixes the tool results written into the block at turn time (`$from`) with data read live while the mail is
 * captured (`$source`, embedded domain widgets) — the reader must know which numbers are from when. The turn time is
 * the `resolvedAt` the resolution wrote into the snapshot; the mail time is the capture moment, fixed at the first
 * render. Each phrase is its own translation key; the phrases are joined by a middle dot. A tree with inline data only
 * shows no line.
 */
export const ComposedViewEmailDataTimes = (props: ComposedViewEmailDataTimesProps) => {
  const { tree } = props;
  const dataTimes = useMemo(() => readComposedViewDataTimes(tree), [tree]);
  const [captureMoment] = useState(() => new Date());
  // The owner's settings come from the app, read defensively: the capture store is a partial copy of the app's.
  const { dateLocale: ownerDateLocale, locale: ownerLocale, timeZone } = useAgentsOwnerSettings();
  const dateLocale = ownerDateLocale || ownerLocale || FALLBACK_DATE_LOCALE;

  const phrases: string[] = [];
  if (dataTimes.hasTurnTimeSnapshot) {
    phrases.push(
      dataTimes.turnTimeResolvedAt === undefined
        ? translateText("Conversation data as of the time of the report")
        : translateText("Conversation data as of %dataTime%", {
            dataTime: formatDataTime(new Date(dataTimes.turnTimeResolvedAt), dateLocale, timeZone),
          }),
    );
  }
  if (dataTimes.hasMailTimeRead) {
    phrases.push(
      translateText("Live data as of %dataTime%", {
        dataTime: formatDataTime(captureMoment, dateLocale, timeZone),
      }),
    );
  }
  if (phrases.length === 0) return null;
  return (
    <div className={styles.dataTimes} data-testid="composed-view-email-data-times">
      <Text role="caption" color="var(--agents-fg-light)" translate={false}>
        {phrases.join(" · ")}
      </Text>
    </div>
  );
};
