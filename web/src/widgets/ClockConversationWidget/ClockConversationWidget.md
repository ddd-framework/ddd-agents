---
name: Clock
emitter: agent
summary: Live clock of one place, or a world clock of several, from the IANA time zones the agent passes. Digital and analog time, city label, date, UTC offset, a day/night hint and the difference to the owner's time; ticks in the browser, no network.
description: Shows the current time of one place, or a world clock of up to eight places, from the IANA time zone ids the agent passes (with a city label each). Every clock shows the time as a large digital value and an analog dial with a second hand, the place with its zone abbreviation, whether it is today, yesterday or tomorrow there together with the hour difference to the owner's own time zone (the location's zone, else the browser's), the date, the UTC offset and a sun or moon for day or night. The time ticks client-side from the browser's time-zone data — nothing is fetched. A zone id the browser does not know is dropped and reported. In a report e-mail the clocks show the time of the capture with an "As of" line. Inline only, read-only.
---

# ClockConversationWidget

## Description
The current time somewhere else, the way a world clock app shows it: per place a large digital time, an analog dial,
the place with its zone abbreviation, "Today / Yesterday / Tomorrow" with the hour difference to the owner's time, the
date, the UTC offset and a sun or moon for day or night. One place renders one clock; several places render one widget
with one row per place (a world clock). The time ticks every second in the browser from its own time-zone data
(`Intl`), so the widget needs no tool and fetches nothing; daylight-saving changes come with the browser's data.

The owner's own time zone is the location's time zone (derived from the address), else the browser's. A zone id the
browser does not know is dropped and reported to the model (render report `failed`, code `INVALID_PROP`, naming the
dropped ids); the other clocks still render. No valid zone at all renders one muted line and reports `failed` too; an
empty `clocks` list reports `empty`. In a report e-mail the clocks are frozen at the capture time and an "As of …" line
says when that was.

## Data structure — DATA-COMPLETE (zone ids and labels only)
```ts
interface ClockPayload {
  title?: string;                  // optional headline in the owner's language — "Your team's time zones"
  timeFormat?: "24h" | "12h";      // OMIT → the location's preference
  clocks: {
    timeZone: string;              // an IANA time zone id: "Europe/Berlin", "America/New_York", "Asia/Tokyo"
    label?: string;                // the place in the owner's language — "Münster, Germany"; OMIT → the city of the id
    abbreviation?: string;         // OMIT → the browser's short zone name ("CET", "EDT")
  }[];                             // 1 to 8 clocks, in the order to show
}
```
Accepted aliases (weak models rename fields): `zones` / `timeZones` for `clocks`; `tz`, `tz_name`, `zone`, `timezone`
for `timeZone`; `city`, `location`, `name` for `label`; `tz_alias` for `abbreviation`; `time_format` for `timeFormat`;
a clock written as a plain zone id string; one clock written at the top level (`{"timeZone": …, "label": …}`).

Single clock:
```widget
{ "type": "Clock", "clocks": [ { "timeZone": "Europe/Berlin", "label": "Münster, Deutschland" } ] }
```
World clock:
```widget
{ "type": "Clock", "title": "Berlin, New York, Tokio", "clocks": [ { "timeZone": "Europe/Berlin", "label": "Berlin, Deutschland" }, { "timeZone": "America/New_York", "label": "New York, NY, USA" }, { "timeZone": "Asia/Tokyo", "label": "Tokio, Japan" } ] }
```

## When to use
- When the owner asks what time it is somewhere ("Wie spät ist es in Tokio?"), or how far ahead or behind a place is: one clock with the place's IANA time zone and its name as `label`.
- When the answer compares the time of several places (a team, customers or suppliers abroad, a call across time zones): ONE Clock widget with one entry per place, never one widget per place.
- Write a SHORT one-line prose intro FIRST that already states the time or the difference ("In Tokio ist es jetzt 10:58, acht Stunden später als bei dir:"), then the widget on its own line — a widget-only turn renders blank.
- Pass real IANA ids only (`Asia/Tokyo`, not `JST` or `GMT+9`); never invent a time — the widget computes it.
- Not for a historical time, a date calculation, a countdown or finding a meeting slot in a calendar — answer those in prose.
