<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Mandates;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandates;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Base\Dtos\RequestDto;

/**
 * The owner's mandates (see {@see AgentMandate}).
 *
 * @property AgentMandate[] $elements
 * @method AgentMandate getByUniqueKey(string $uniqueKey)
 * @method AgentMandate[] getElements()
 * @method AgentMandate first()
 * @method static AgentMandatesService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentMandates::class)]
class AgentMandates extends EntitySet implements LlmMarkdownRenderable
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AgentMandatesService::class;

    /** Newest grant first when the caller asked for no order — the id is monotonic per creation and never null. */
    public const string DEFAULT_ORDER_BY = 'id desc';

    /**
     * Takes the caller's QueryOptions off the request (`filters`, `orderBy`, `top`, `skip`) and falls back to
     * {@see self::DEFAULT_ORDER_BY} — the mandates-tab infinite scroll pages with `top`/`skip` and filters on
     * `status`/`agentSpaceId`.
     * The scope is never a parameter: read rights restrict every query to the auth account's own mandates
     * ({@see \DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandate::applyReadRightsQuery()}).
     * The options land on the set class the container resolves (an application's same-path subclass): its set repo
     * reads the default QueryOptions of ITS class.
     */
    public static function applyQueryOptionsFromRequestDto(RequestDto $requestDto): void
    {
        /** @var AgentMandates $agentMandatesClass */
        $agentMandatesClass = DDDService::instance()->getContainerServiceClassNameForClass(static::class);
        $queryOptions = $agentMandatesClass::getDefaultQueryOptions();
        $queryOptions->setQueryOptionsFromRequestDto($requestDto);
        if ($queryOptions->getOrderBy()) {
            return;
        }
        $defaultOrderBy = OrderByOptions::fromString(self::DEFAULT_ORDER_BY);
        $queryOptions->setOrderBy($defaultOrderBy, validateAgainstDefinitions: false);
    }

    /** Compact list rendering for the agent: one line per mandate, empty state honest. */
    public function toLlmMarkdown(): string
    {
        if ($this->count() === 0) {
            return 'No mandates. The agent holds no standing authorizations for this ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . '.';
        }
        $lines = ['Mandates (' . $this->count() . '):'];
        foreach ($this->getElements() as $agentMandate) {
            $lines[] = $agentMandate->toLlmMarkdown();
        }
        return implode("\n", $lines);
    }
}
