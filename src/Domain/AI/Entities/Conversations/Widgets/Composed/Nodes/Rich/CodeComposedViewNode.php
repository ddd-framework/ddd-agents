<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A block of source code with syntax highlighting: a header with the
 * language (or the title), a copy button and a line-wrap toggle, the code below. The frontend highlights it with
 * highlight.js, loading only the grammar of the named language; an unknown language shows plain text. No string in
 * a tree may hold three backticks, so neither may the code. Component: `components/rich/Code.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'code', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the source code, line breaks as \\n')]
#[ComposedViewNodeProp(name: 'language', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, description: 'the language for the highlighting: php, javascript, typescript, json, html, css, scss, sql, bash, python, java, kotlin, swift, go, rust, ruby, csharp, cpp, c, yaml, xml, markdown, diff, dockerfile, graphql, ini, plaintext; another name shows plain text')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a file name or a short label shown in the header instead of the language name')]
#[ComposedViewNodeProp(name: 'wrap', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'true wraps long lines from the start (the owner can toggle it); default false scrolls them')]
class CodeComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Code';

    /** The node's text prop is `code`. */
    public const string TEXT_CONTENT_PROP_NAME = 'code';

    public const array CATEGORIES = [ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'a block of source code with syntax highlighting, a copy button and a line-wrap toggle';
}
