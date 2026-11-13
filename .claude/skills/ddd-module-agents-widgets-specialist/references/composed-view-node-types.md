# The composed-view kit: the node types

**Reader:** host developer and package maintainer

The package registers 48 node types, one class `<Type>ComposedViewNode` each below `Widgets/Composed/Nodes/<Group>/`.
This table is read off the classes (`NODE_TYPE`, `CATEGORIES`, the `ComposedViewNodeSlot` attributes,
`PROVIDES_ITEM_SCOPE`, `SUMMARY`). The props of each type — names, types, required, enums — come from its
`ComposedViewNodeProp` attributes; `ComposedViewNodeSchema::buildJson()` prints them all, and that output is the
reference to read before writing a tree by hand. "No slot" means the node holds no other nodes.

| Group | Type | Categories | Slots | Purpose |
|---|---|---|---|---|
| Layout | `Stack` | layout | children | a row or a column of nodes with one gap step |
| Layout | `Grid` | layout | children (size class follows `columns`) | equal columns, 1 to 4, one child per cell |
| Layout | `Flow` | layout | children | small items that wrap like words |
| Layout | `Surface` | layout | media, header, children, footer | a titled surface grouping nodes |
| Layout | `Carousel` | layout | children; item scope | slides with previous / next buttons, or its children once per item |
| Layout | `Popover` | inline | content | a small button that opens a floating explanation |
| Layout | `Pressable` | input | children | makes its content one clickable area (sets state or runs an action) |
| Layout | `Quote` | block | children | a quotation with who said it and where |
| Layout | `Divider` | block | no slot | a hairline between two parts |
| Layout | `Spacer` | block | no slot | empty room, fixed or growing |
| Text | `Title` | block | no slot | a heading of the view or a section |
| Text | `Text` | inline, block | children (inline) | plain text, a paragraph or an inline run |
| Text | `Markdown` | block | no slot | a safe Markdown subset (no HTML, no images) |
| Text | `Caption` | inline, block | no slot | muted secondary text |
| Text | `Label` | inline | no slot | a short label above a value or input |
| Text | `Badge` | inline | no slot | a small status chip with a tone |
| Text | `Link` | inline | no slot | a link to an http(s) page in a new tab |
| Text | `Favicon` | inline | no slot | the site icon of a domain |
| Text | `SourceRef` | inline, block | no slot | the source line of the shown values; one anywhere sources the whole tree |
| Data | `KpiTile` | data | no slot | one headline number with unit and change |
| Data | `Chart` | data | no slot | line, area, bar, pie, donut, scatter, heatmap, funnel or radar chart over rows |
| Data | `Table` | data | children | columns and rows, or `TableRow` / `Repeat` children |
| Data | `TableRow` | none (only inside its parent) | children | one row of `TableCell`s |
| Data | `TableCell` | none (only inside its parent) | children | one formatted value, a share bar or heat tint, compact nodes |
| Data | `List` | block | children | a divided list of items or `ListItem` children |
| Data | `ListItem` | none (only inside its parent) | children, trailing | one list entry |
| Data | `Repeat` | data | children; item scope | its children once per item, `$item` bound |
| Data | `Icon` | inline | no slot | one design-system icon in a tone |
| Inputs | `Tabs` | input | no slot | a few short choices side by side, written to state |
| Inputs | `Select` | input | no slot | one of a longer list, written to state |
| Inputs | `RadioGroup` | input | no slot | one of a few explained choices |
| Inputs | `Checkbox` | input | no slot | true or false to state |
| Inputs | `Slider` | input | no slot | a number between min and max |
| Inputs | `Input` | input | no slot | one line of text or a number |
| Inputs | `Textarea` | input | no slot | several lines of text |
| Inputs | `DatePicker` | input | no slot | a day, written as Y-m-d |
| Inputs | `Button` | input | no slot | runs one action or writes a value to state |
| Form | `Form` | layout | children, header | a form around inputs; its submit is the owner's answer |
| Entity | `EntityLink` | inline | no slot | an entity's name that opens its detail through `openEntity` |
| Media | `Image` | media | no slot | one picture with alt text, caption and source |
| Media | `ImageGroup` | media | no slot | a gallery as grid, carousel or magazine |
| Media | `Video` | media | no slot | one video with title, thumbnail and length |
| Rich | `Code` | block | no slot | highlighted source code with copy |
| Rich | `Math` | block, inline | no slot | a LaTeX formula |
| Rich | `Svg` | data, media | no slot | a sanitised vector graphic |
| Rich | `Diagram` | heavy | no slot | a diagram from Mermaid source |
| Map | `Map` | heavy | no slot | an interactive map of labelled points |
| Embedding | `Widget` | heavy | no slot | an embeddable domain widget (`widget`, `fields`) |

A heavy node never sits in a compact slot unless that slot accepts heavy nodes when compact. The parts without a
category sit only in the slot of their parent, which names them in `acceptsNodeTypes`.
