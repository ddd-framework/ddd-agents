<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Symfony\Component\Validator\Constraints\Length;

/**
 * AN AGENT SPACE: what an agent works for.
 *
 * Every conversation belongs to an account — WHO talks to the agent. Most applications know a second anchor: WHAT the
 * agent works for. A workshop, a restaurant, a team, a work order. The conversations, schedules, mandates and
 * memories the agent keeps for one such thing belong together and are kept apart from those of the next one, and the
 * thing answers the facts the agent works by: its time zone, its language, who owns it, who is billed.
 *
 * The package cannot know what that thing is in an application, and it carries no column of an application. It owns
 * the general form instead: one AgentSpace row per thing the agent works for.
 *
 * - PARTITION. Conversation, schedule and mandate reference their space through `agentSpaceId`. An empty reference
 *   means ACCOUNT LEVEL: the row belongs to the account alone. An application without spaces never sets one and runs
 *   the core unchanged. Every "of the same space" read of the core filters on this column — an empty reference selects
 *   the account-level rows, never "all spaces of the account".
 * - FACTS. The `find…` methods below answer what the core asks about a space. Each default says "not known here" (or
 *   answers from the account); none assumes a kind of space. They walk only this object's own relations — a fact
 *   that needs a query or another service is answered in {@see AgentSpacesService}, which is also the one place the
 *   core asks (it hands the question to these methods by default).
 * - THE APPLICATION'S SIDE. An application that has spaces puts a subclass at the same path under its own root
 *   namespace (with its own set and repositories), adds the reference to its own entity there — its column, its
 *   relation, its unique key —, overrides the facts it can answer, and creates the rows: the package never creates,
 *   copies or deletes a space. The space is the entity an application extends to tell the agent core what it works
 *   for: the core reads `agentSpaceId` and the facts of the space, nothing else. An application may derive
 *   conversation, schedule, mandate or the row of an operator list at the same path as well, to keep a column of
 *   its own next to that reference (its own id of the thing, for its own reads and its clients); the core never
 *   reads such a column, and the application keeps it equal to the space.
 *   Every such subclass of a filterable class (entity and set) repeats `use QueryOptionsTrait;`: the framework
 *   looks for the trait on the class itself, and a set without it is read unfiltered and uncut.
 *
 * A space is the agent's own record of the thing it works for, not a second copy of the application's entity: it
 * holds the link and the answers, none of the application's own data.
 *
 * OWNERSHIP. {@see self::$accountId} is set when a space belongs to one account for its whole life (an application
 * whose spaces are things of an account). An application that anchors its spaces on an entity of its own — whose
 * owner may change — leaves it empty, declares its own parent relation in the subclass and answers
 * {@see self::findOwnerAccount()} and the read rights of its repository from that entity. A copy of the owner that
 * can go stale would delete the space (and with it the conversations of its present owner) together with a former
 * owner's account.
 *
 * READ RIGHTS. A direct read of a space, a list and an expand join follow the read rights of its repository. The
 * relation of a conversation, a schedule or a mandate to its space does not: whoever may read the row may read the
 * space it names, with every column of it ({@see DBAgentSpace::lazyload()}). The space is the row's own work context;
 * under the read rights a row whose space the caller may not read any more would read "no space", which means
 * account level. An application decides knowingly what it puts on a space and what its responses show of it.
 *
 * @method static AgentSpacesService getService()
 * @method static DBAgentSpace getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentSpace::class)]
class AgentSpace extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var int|null Id of the account this space belongs to; empty when the application anchors its spaces on an entity of its own (see the class docblock) */
    public ?int $accountId = null;

    /** @var Account|null The account this space belongs to; deleting the account deletes its spaces */
    #[LazyLoad(addAsParent: true)]
    public ?Account $account;

    /**
     * @var string|null The IANA time zone the agent works in for this space (e.g. `Europe/Berlin`): schedules fire in
     * it, day boundaries and the time rule of the prompt follow it. A stored zone counts as confirmed. Empty = not
     * known here. An application whose own entity knows the zone leaves this empty and overrides
     * {@see self::findTimezone()}.
     */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $timezone = null;

    /**
     * The id a row references `$agentSpace` with, or a lock is named by: null without a space (a row at account
     * level).
     *
     * @param AgentSpace|null $agentSpace
     * @return int|null
     * @throws InternalErrorException when the space is not stored: no row references a space without an id, and
     *                                reading it as "no space" would store the row at account level
     */
    public static function getIdOfStoredAgentSpace(?AgentSpace $agentSpace): ?int
    {
        if ($agentSpace === null) {
            return null;
        }
        if (!isset($agentSpace->id)) {
            throw new InternalErrorException('An agent space without an id cannot be referenced by a row.');
        }
        return (int)$agentSpace->id;
    }

    /**
     * The time zone the agent works in for this space, with its provenance: the stored zone, confirmed.
     *
     * @return AgentSpaceTimezone|null null = not known here, or the stored name is no known zone (the caller keeps the
     *                                 conversation's own zone, else none)
     */
    public function findTimezone(): ?AgentSpaceTimezone
    {
        $timezoneIdentifier = $this->timezone ?? null;
        if ($timezoneIdentifier === null || $timezoneIdentifier === '') {
            return null;
        }
        return AgentSpaceTimezone::ofKnownIdentifier($timezoneIdentifier, false);
    }

    /**
     * The account the agent acts for in this space: it receives the reports, grants the mandates and is billed unless
     * {@see self::findBillingEntity()} names something narrower.
     *
     * @return Account|null null = no owner known (callers refuse or fall back as for a conversation without a space)
     */
    public function findOwnerAccount(): ?Account
    {
        return $this->account ?? null;
    }

    /**
     * The entity within the account a model call for this space is billed to. May load a relation of this object.
     *
     * @return Entity|null null = the account is billed
     */
    public function findBillingEntity(): ?Entity
    {
        return null;
    }

    /**
     * The billing entity ONLY when it is already loaded — never a load (asked on every model call).
     *
     * @return Entity|null null = not loaded or none
     */
    public function findLoadedBillingEntity(): ?Entity
    {
        return null;
    }

    /**
     * The language code the owner of this space works in (the interface language).
     *
     * @return string|null null = not known here (the caller uses the language the conversation confirmed, else the
     *                     application's default language)
     */
    public function findLanguageCode(): ?string
    {
        return null;
    }

    /**
     * The ICU locale string dates are formatted in for this space (e.g. `de_DE`).
     *
     * @return string|null null = not known here (the caller uses the language code)
     */
    public function findLocaleString(): ?string
    {
        return null;
    }

    /**
     * The key a widget block of a conversation of this space hydrates itself from (the block carries it so that the
     * client can read the widget's content without the conversation). The default: the id of the space. An
     * application whose widgets read by an id of its own entity returns that id.
     *
     * @return int|null null = the blocks carry no key
     */
    public function getWidgetSelfHydrationKey(): ?int
    {
        return isset($this->id) ? (int)$this->id : null;
    }
}
