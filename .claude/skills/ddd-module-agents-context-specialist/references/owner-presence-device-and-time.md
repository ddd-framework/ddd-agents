# Owner presence, device and time

**Reader:** host developer and package maintainer

Contents: 1. Owner presence · 2. The device of a message · 3. The surface dimension · 4. One clock · 5. Which zone
a conversation reads · 6. The conversion boundary · 7. Rules for tool authors

## 1. Owner presence

`OwnerPresence` says who reads the call. It is derived per call and never stored
(`AIConversation::getOwnerPresence()` → `AIConversationOrigin::buildOwnerPresence()`), and rendered as the
OWNER_PRESENCE system segment right after the time rule, for roots; a delegation child gets it (with
`surface=phone`) only as `PRESENT_ON_THE_PHONE` while its root's owner is on the phone line. It states one fact, no
rules (`OwnerPresence::toSystemSegmentText()`), except for the voice channels (phone line, in-app call) and an external
agent, whose rules must hold for every skill.

| Presence | When (in this order) |
|---|---|
| `ABSENT_PLATFORM_RUN` | a purpose hidden from the owner (`AIConversationPurpose::isCustomerHiddenPurposeName()`), or an initiator type in `AIConversation::getPlatformOwnRunInitiatorTypes()` |
| `PRESENT_ON_THE_PHONE` | a purpose held as a phone call (`AIConversationPurpose::isPurposeNameHeldAsAPhoneCall()`) and no initiator |
| live call | `INITIATOR_TYPE_PHONE_CALL` with a live answered call: `PRESENT_IN_APP_VOICE_CALL` for the app channel, else `PRESENT_ON_THE_PHONE` |
| `PRESENT_IN_CHAT` / `ABSENT_UNKNOWN_ORIGIN` | `INITIATOR_TYPE_PHONE_CALL` after the call: in chat when the newest owner text row came from a screen device, else unknown |
| `WORKING_FOR_AN_EXTERNAL_AGENT` | `INITIATOR_TYPE_EXTERNAL_AGENT` |
| `ABSENT_SCHEDULED_RUN` | `INITIATOR_TYPE_SCHEDULE`, `INITIATOR_TYPE_ADMIN_TASK`, or the purpose `AIConversationPurpose::SCHEDULE_RUN` without initiator |
| live call or `PRESENT_IN_CHAT` | no initiator |
| `ABSENT_UNKNOWN_ORIGIN` | any other initiator |

The live-call look-up runs at most once per `AIConversation::LIVE_CALL_PROBE_TTL_SECONDS` (20) per conversation
object (`AIConversationLiveCallProbe`), so the steps of a turn share it. The segment changes only at a call's start and
end. The presence also decides whether the composed-view kit is taught
(`AgentModelInput::OWNER_PRESENCES_TAUGHT_THE_COMPOSED_VIEW_KIT`) and whether a scheduled run writes the e-mail variant.
Voice channels are the host's (`AgentVoiceChannelService`, entry skill).

## 2. The device of a message

The device is a property of the owner's MESSAGE (`AIConversationMessage::$ownerDevice`, value object `OwnerDevice`),
never of the conversation: an owner switches devices mid-conversation.

- Read per request by `AgentConversationControllerHooksTrait::readOwnerDeviceFromRequest()`:
  `OwnerDevice::fromRequestHints()` takes the client header named by `OwnerDevice::CLIENT_HEADER_NAME` first, the user
  agent otherwise. A header may only claim a client kind; the platform kinds (`KIND_PHONE_CALL`, `KIND_EXTERNAL_AGENT`,
  `KIND_ADMIN_CONSOLE`) come from `OwnerDevice::phoneCall()`, `externalAgent()`, `adminConsole()`.
- Rendered in the wire bracket `[time | via …]` (`AIConversationMessage::buildWireBracket()`, tag
  `OwnerDevice::toModelTag()`); the reply-language cue points at the same bracket. A row without a device renders the
  time only; signal rows carry no bracket.
- The app version is stored, never rendered.

**What `OwnerDevice` holds:** `$kind` (one of `OwnerDevice::KINDS`), `$operatingSystem` (`OS_*`),
`$operatingSystemVersion`, `$deviceClass` (`DEVICE_CLASS_PHONE`, `DEVICE_CLASS_TABLET`, `DEVICE_CLASS_DESKTOP`),
`$deviceModel` (the identifier the app sent), `$browser`, `$appVersion` and `$source` (`SOURCE_HEADER`,
`SOURCE_USER_AGENT`, `SOURCE_PLATFORM`).

**The client header** (`OwnerDevice::fromClientHeader()`): `<kind>; os=…; osVersion=…; device=…; browser=…;
appVersion=…`. The kind token must be one of `OwnerDevice::HEADER_KIND_TOKENS` (mobileApp, app, web, desktopWeb,
mobileWeb); any other first token — a platform kind included — makes the whole header unreadable, and the user agent
is classified instead. Unknown attributes are ignored. Each value keeps only letters, digits, spaces and the characters . , _ - / ( ) +
and is cut to 48 characters. A device identifier starting with "iPad" sets the iPadOS family. Where the header leaves
the operating system, its version or the browser blank, the user agent fills them; the header's values win.

**The user-agent classifier** (`OwnerDevice::fromUserAgent()`, no library): the app's HTTP stacks first — CFNetwork
(iOS app), okhttp (Android app), a leading "Dart/" (app, no operating system) — then iPhone, iPad, Android (phone
with "Mobile", else tablet), CrOS, Macintosh, Windows NT and Linux; the browser from Edge, Samsung Internet, Firefox,
Chrome and Safari tokens in that order. Anything else is `KIND_UNKNOWN`, which renders no tag. Known gaps: an iPad
that asks for the desktop site reads as a Mac, and any macOS client using CFNetwork or any JVM client using okhttp
reads as the app.

**The tag says only what is known.** `via <device> app`, where the device is a name from `OwnerDeviceAppleModels`
(Apple identifiers mapped to product names), else the system family (iPhone, iPad, Android, Android tablet, mobile)
— a client's free text never reaches the model; `via web, <browser> on <platform>` and `via mobile web, …` with
whatever of browser and platform is known; `via phone call`, `via external agent`, `via admin console`.

## 3. The surface dimension

The newest owner TEXT row with a device in the context decides a transient variant dimension
`OwnerDevice::SURFACE_DIMENSION` = `mobileApp` | `mobileWeb` | `desktopWeb` | `phone`, merged into a copy of the
variants for this call (the working state never stores it). Skill sections and the base prompt's sections select on
it (`[surface=screen]` covers the three screen values). No device row: no dimension; `[surface=screen]` sections render by default (screen is the default surface,
`OwnerDevice::matchesDefaultedSurfaceSelector()`), `[surface=phone]` sections do not. How a
skill file declares such a section belongs to the skills-and-purposes skill.

## 4. One clock

The model reads and writes every moment in the agent space's local time; the platform converts at the tool boundary.
The model never does zone arithmetic and never sees a UTC moment.

| Kind | Rule |
|---|---|
| `DateTime` (a moment) | inbound: written without offset = wall-clock time in the conversation's zone, an explicit offset wins; stored as the UTC instant; outbound: local time with its offset (`DateTime::formatForModel()`) |
| `Date` (a day) | never converted |
| a recurrence | the digits are local; the evaluator applies the schedule's zone (autonomy skill) |

What the model reads about time:

- the TIME_RULE segment "Times are the … local time: <zone>." (stable; under `onceWhenChanged` the epoch record's zone);
- the tail entry `[SIGNAL:clock] now = … (weekday)` with ONE instant captured per call
  (`AgentModelInput::$clockInstant`, recorded as `RequestSnapshot::$clockAt`, re-used by a rebuild);
- owner brackets in the zone in effect at their position;
- `[SIGNAL:time-zone]` when the zone is only a country default (`everyCall`; a note under `onceWhenChanged`): ask once
  before persisting a clock time; a schedule-bound run is told the zone is the schedule's.

The base prompt carries no clock: a clock in the prefix breaks the cache on every call.

## 5. Which zone a conversation reads

- **A conversation owns no zone.** `AIConversation::refreshAgentSpaceTimezone()` reads the space's zone
  (`AgentSpacesService::findTimezoneOfConversation()` → `AgentSpaceTimezone`) at every model request and before every
  tool call, so a zone written a step earlier is what the next request sees. `AIConversation::getTimezone()` and
  `isTimezoneUnverified()` read that snapshot.
- **The exception: schedule-bound conversations** (`AIConversation::isScheduleBound()`): a schedule run freezes its
  schedule's zone (`freezeTimezone()`), its delegation children and supervisor tandem inherit it.
- No space and no zone from the host: no time rule, no clock signal, no conversion.
- `RequestSnapshot::$timezoneIdentifier` / `$timezoneUnverified` record the zone per call; a rebuild renders the zone
  the call actually had. A call recorded before snapshots carried a zone renders in the space's zone of today.
- **The current zone wins over the time of writing.** A delegation child of a normal conversation, or an argument
  written before a zone change and hydrated later, is read in the zone in effect when it is used.

Where a space's zone comes from (its column, a country default flagged unverified) is the host's `AgentSpacesService`
(entry skill).

## 6. The conversion boundary

`MCPToolExecutorService` (tools skill) runs two regions with the zone of the call
(`AgentToolCallProvenance::getTimezone()`):

1. argument hydration inside `SerializerRegistry::withInputTimezone()`: every `DateTime` at any depth is parsed in the
   zone (a nonexistent local time throws `NonexistentLocalTimeException`); `DateTime` filter literals are normalized
   too (`FiltersOptions::normalizeMomentLiterals()`);
2. the result render inside `SerializerRegistry::withModelFacingTimezone()`: a `DateTime` renders local with offset,
   only under the `MODEL_FACING_DATETIME` serializer flag, so persistence, REST, cache and Argus never enter it.

The tool body itself runs with no zone set: its loads and writes see UTC.

## 7. Rules for tool authors

1. A moment parameter is `DateTime`, never `Date` and never a string.
2. Never convert inside a tool body.
3. A moment a tool renders into text itself goes through `AgentClock::formatMoment()` or `AgentClock::formatDay()`;
   outside the render region pass the zone explicitly.
4. The zone of a call is `AgentToolCallProvenance::getTimezone()`, never `AIConversation::$timezone` (null unless
   schedule-bound) and never the server's default zone.
5. A moment the model may write back renders with seconds, so it round-trips to the same instant.
6. A filter on a `DateTime` property is a moment filter and is converted from the call's zone; a `Date` filter is a
   day and is not. The kind comes from reflection (`FiltersDefinition::$temporalKind`); where reflection cannot see
   the type (a property that only means a moment, a definition built in code), declare it through the associative
   form of `FiltersDefinitions::__construct()`, else its literals are compared unconverted (`ddd-query-options-specialist`).
