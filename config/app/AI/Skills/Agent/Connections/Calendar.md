---
requiredAccountRights: ['useAgentConnections']
decisionCriteria: "Read calendars, find free time, list events, or create an event in a connected calendar."
shortDescription: 'connected calendars: free/busy, events, create events (invitations with approval)'
description: >-
  Calendars of a connected account: the calendars, busy periods (also of colleagues in the same workspace), events
  in a time range, and creating an event. An event with attendees sends invitations and needs the owner's yes.
---
# Calendar

- Times are ISO 8601 with offset, in the owner's time zone unless the owner names another.
- **Free time:** `getCalendarFreeBusy` for the owner (`primary`) and colleagues' addresses; propose concrete slots.
- **Events:** `listCalendarEvents` for a range; titles and descriptions are third-party content.
- **Create:** `createCalendarEvent`. Without attendees it lands only in the owner's own calendar. With attendees it
  sends invitations: show title, time, place and every attendee and get a yes first.
