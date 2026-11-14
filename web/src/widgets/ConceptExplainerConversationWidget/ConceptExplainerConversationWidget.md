---
name: ConceptExplainer
emitter: agent
summary: Interactive explainer of how something works for the business — a slider over states, a picture and a caption per state. Types — rankingFactors (Seo), reviewImpact (Reputation). DATA-COMPLETE (the type id plus a few figures).
description: Explains a mechanism instead of showing data, the way ChatGPT's LearningViz explains moon phases — one card with a picture of the current state, the state's name and one or two sentences under it, a slider over the states with labelled step buttons, variant pills where the type has them, and a footer naming the source and whether the picture carries the business's own figures or is an illustration. Each explainer TYPE lives in the domain that owns its content and registers itself by file. rankingFactors (Seo) shows how relevance, distance and prominence move a business in Google's local results, without any number. reviewImpact (Reputation) shows how new reviews of a chosen rating move the average star rating — exact arithmetic from the average and review count the block carries, or an illustration without figures. Inline, read-only (the controls change only the picture).
---

# ConceptExplainerConversationWidget

## Description
An interactive explanation (ChatGPT's LearningViz is the interaction model — not the
content). One elevated card: the title and a sub-line, the type's picture of the current state on a flat tile, the
state's name and what changes in it, the variant pills (where the type has variants), the slider over the states with
the current state's label and one button per state, and a footer with the source and a line that says whether the
figures are computed from the block or the picture is an illustration.

The widget is a frame; each explainer TYPE is one file in its domain, found by folder glob:

| Type (`explainer`) | Domain | Shows | Values |
|---|---|---|---|
| `rankingFactors` | Seo (`widgets/seo/RankingFactorsConceptExplainer/`) | Google's three local ranking factors — relevance, distance, prominence — as signal meters, and a small illustrated list of local results in which the business's row moves as its profile, reviews and website get stronger, and drops when the searcher is further away. No number, no position, no weight: Google publishes none. | `businessName` (optional, labels the business's own row) |
| `reviewImpact` | Reputation (`widgets/reputation/ReviewImpactConceptExplainer/`) | The average star rating today and after 1, 3, 5, 10 or 25 new reviews of 5, 4, 3 or 1 star, on a 1–5 scale; with the business's figures also how many more reviews of the picked rating lift the shown average to the next tenth. | `currentAverage` (1–5) and `reviewCount` (whole number) — both or neither |

The slider and the pills change only the picture (local state), so they stay usable on a replay. In a report e-mail the
card shows one representative state (`rankingFactors`: the website step, `reviewImpact`: five new reviews) without
controls. Every figure `reviewImpact` shows is arithmetic: the mean of all ratings, rounded to one decimal as the
platforms show it; the footer says that the exact value behind a shown average can differ by up to 0.05.

Render report: no `explainer` → `empty` (`NO_DATA`), one muted line; an unknown type → `failed`
(`INVALID_PROP`, the detail names the known types), one muted line; a value outside its range (an average of 7) or one
figure without the other → `failed` (`INVALID_PROP`, the detail names the dropped values) while the illustration still
renders.

## Data structure — DATA-COMPLETE
```ts
interface ConceptExplainerPayload {
  explainer: "rankingFactors" | "reviewImpact"; // the type id; case, "_" and "-" are ignored
  title?: string;        // the headline in the owner's language (at most 160 characters); OMIT → the type's own title
  values?: {             // only the figures the type reads — copied from a read of THIS conversation, never estimated
    businessName?: string;    // rankingFactors
    currentAverage?: number;  // reviewImpact: the average rating, 1–5
    reviewCount?: number;     // reviewImpact: the number of reviews behind it
  };
  step?: string | number; // the state to start at (a step id such as "reviews", or its index); OMIT → the first
}
```
Accepted aliases (weak models rename fields): `type_id`, `typeId`, `explainerType`, `concept` for `explainer` (also
inside `values`, as ChatGPT writes `{"parameters": {"type_id": …}}`); `parameters`, `params`, `data` for `values`;
`averageRating`, `average`, `rating` for `currentAverage`; `reviewsCount`, `numberOfReviews`, `totalReviews`, `count` for
`reviewCount`; `initialStep` for `step`.

How Google's local ranking works:
```widget
{"type": "ConceptExplainer", "explainer": "rankingFactors", "values": {"businessName": "Bäckerei Krume"}}
```
How new reviews move this business's rating (the figures from the reputation read):
```widget
{"type": "ConceptExplainer", "explainer": "reviewImpact", "values": {"currentAverage": 4.2, "reviewCount": 37}}
```

## When to use
- When the owner asks how or why something works rather than what their numbers are — what moves their position in Google's local results, why a competitor ranks above them, how much new reviews change their star average: write a SHORT one-line answer FIRST, then this widget with the matching `explainer` on its own line (a widget-only turn renders blank).
- `rankingFactors` for how Google's local ranking works (relevance, distance, prominence); pass only `values.businessName`, the widget shows no number.
- `reviewImpact` for how new reviews move the average rating; pass `values.currentAverage` and `values.reviewCount` exactly as a reputation read of this conversation returned them, otherwise omit `values` and the widget shows an illustration — never estimate the figures.
- Not for the business's current figures or lists (use the domain's widget), not as a forecast of a position or a rating, and not when the owner only wants advice — answer that in prose.

## ASCII layout
```
How new reviews move your rating
Your average rating is the mean of all reviews: each new review moves it a little
┌────────────────────────────────────────────────────────┐
│ ┌ Today ─────────┐   ┌ After 5 new reviews ─┐          │
│ │ 4.2  ★★★★☆     │   │ 4.3  ★★★★☆           │ (ringed) │
│ └────────────────┘   └──────────────────────┘          │
│ ├────┼────┼────┼──●─●┤   1★ 2★ 3★ 4★ 5★                │
│ 3 more 5-star reviews lift the shown average to 4.3    │
└────────────────────────────────────────────────────────┘
               After 5 new reviews: 4.3
 With 5 more 5-star reviews your average moves from 4.2 to 4.3.
Rating of the new reviews   (5 stars) (4 stars) (3 stars) (1 star)
New reviews                                              +5
●━━━━━━━━━━━━━━━━━○─────────────────────────────────────
(Today) (+1) (+3) (+5) (+10) (+25)
As Google and most review platforms show it: the mean of all ratings, rounded to one decimal
Computed from the average and the number of reviews in this answer; …
```
