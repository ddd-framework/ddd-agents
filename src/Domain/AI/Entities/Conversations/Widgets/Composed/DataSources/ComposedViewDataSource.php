<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources;

/**
 * A registered data source a `ComposedView` node may load itself from (the second data path):
 * `{"$source": {"name": "<NAME>", "params": {…}}}`. One class `<Name>ComposedViewDataSource` per source — in the AI
 * core's `Widgets/Composed/DataSources/` or a domain's `Entities/AI/Conversations/DataSources/` folder, found by folder
 * ({@see ComposedViewDataSourceClasses}) — declares the CLIENT endpoint the frontend reads (the endpoint's own entity
 * rights apply server-side; nothing here grants anything), where the rows sit in its response, the parameters
 * ({@see ComposedViewDataSourceParam}), the columns a `Table` / `Chart` may bind ({@see ComposedViewDataSourceColumn})
 * and how long a read stays fresh. The frontend twin is a hydrator registered under the same name
 * (`*.composed-data-source.ts`); skills declare the sources they bring (`providesDataSources`). Exported into
 * `composed-node-schema.json`.
 *
 * @phpstan-consistent-constructor
 */
abstract class ComposedViewDataSource
{
    /** @var string The source name the binding carries (lower camel). */
    public const string NAME = '';

    /** @var string One line for the model spec: what the rows are. */
    public const string SUMMARY = '';

    /**
     * @var string The client endpoint the frontend reads, method and path with a placeholder for the space's key and
     * `{param}` placeholders.
     */
    public const string CLIENT_ENDPOINT = '';

    /** @var string Where the rows sit in the endpoint's response (`$` = the response is the list, `$.rankings` = a field). */
    public const string ROWS_PATH = '$';

    /** @var int Seconds a read stays fresh before the frontend reads again. */
    public const int FRESHNESS_SECONDS = 300;
}
