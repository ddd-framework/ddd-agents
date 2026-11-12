<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Form;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * A form in a `ComposedView` tree: a container of the kit's inputs (`Input`, `Textarea`,
 * `Select`, `Checkbox`, …) with a submit button and, when `declineLabel` is set, a decline button. The inputs
 * write the tree's local `state`; the submit sends the values of the form's fields to the agent as ONE
 * FRONTEND_WIDGET_CLICK signal (no signal source of its own), the sentence {@see ComposedViewConversationWidget} reads:
 *
 *     ComposedView form "<name>" submitted:
 *     - <field>: <value>
 *
 * or `ComposedView form "<name>" declined.` The named slot `header` holds up to two inline items beside the heading (a
 * badge, a method popover — ChatGPT's "Filter & State · Methodik"). The fields are the state names in `fields`, or — without it — every state
 * name the form's inputs bind (`{"$state": …}` values, `{"set": …}` events), in tree order. `name` identifies the form
 * in its reaction (a tree may carry several), so it must fit the signal's quoted span: {@see FORM_NAME_PATTERN}; a form
 * with another name renders but cannot submit. A form inside a form is no form of its own: the frontend renders its
 * body inside the outer form. Inputs change local state only; nothing is sent before the owner submits. Reads nothing.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: ComposedViewNodeCategory::ALL_BUT_HEAVY, description: 'the form body: inputs and the text and layout around them')]
#[ComposedViewNodeSlot(name: 'header', acceptsCategories: [ComposedViewNodeCategory::INLINE], maxChildren: 2, sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT, description: 'at most two small items at the end of the heading: a badge, a popover that explains the method')]
#[ComposedViewNodeProp(name: 'name', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, isBindable: false, description: 'the form id its submit names: letters, digits, `_` or `-`, at most 64, unique in the tree (e.g. "holidayHours")')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], description: "the form heading, in the owner's language")]
#[ComposedViewNodeProp(name: 'description', types: [ComposedViewNodeProp::TYPE_STRING], description: 'one line under the heading: what the answer is used for')]
#[ComposedViewNodeProp(name: 'submitLabel', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, description: "the submit button label, in the owner's language (default \"Send\")")]
#[ComposedViewNodeProp(name: 'declineLabel', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, description: 'a decline button label (e.g. "Not now"); without it the form has no decline')]
#[ComposedViewNodeProp(name: 'fields', types: [ComposedViewNodeProp::TYPE_ARRAY], isBindable: false, description: "the state names the submit sends, in this order; default: every state name the form's inputs bind")]
#[ComposedViewNodeProp(name: 'requiredFields', types: [ComposedViewNodeProp::TYPE_ARRAY], isBindable: false, description: 'state names that must be filled (not empty, a checkbox ticked) before the form submits')]
class FormComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Form';

    public const array CATEGORIES = [ComposedViewNodeCategory::LAYOUT];

    public const bool SUBMITS_THE_STATE_OF_ITS_INPUTS = true;

    public const string SUMMARY = "a form around inputs: its submit sends the field values to you as the owner's answer (optional decline)";

    /** @var string the names a form may carry — they fit the quoted span of its signal ({@see ComposedViewConversationWidget::FORM_SIGNAL_PATTERN}) */
    public const string FORM_NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** The form's `name` when the signal can carry it ({@see FORM_NAME_PATTERN}), else null — such a form cannot submit. */
    public function getFormName(): ?string
    {
        $formName = $this->props['name'] ?? null;
        return is_string($formName) && preg_match(self::FORM_NAME_PATTERN, $formName) === 1 ? $formName : null;
    }
}
