<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Usage;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageScopeBookedCosts;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use DDD\Infrastructure\Libs\Arr;
use stdClass;

/**
 * The stored forms of {@see AIUsageBookedCosts} this package reads and writes.
 *
 * Two forms exist:
 * - the OBJECT form: {@see AIUsageBookedCosts::toObject()}, the per-scope amounts as the elements list of
 *   {@see AIUsageScopeBookedCosts} (`{"totalCostInUSD":…,"bookingCount":…,"scopeBookedCosts":{"elements":[{"usageScope":…,"costInUSD":…}]}}`).
 *   New tool calls and new recordings are written in it.
 * - the KEYED form: the per-scope amounts as a map keyed by usage scope
 *   (`{"totalCostInUSD":…,"bookingCount":…,"costInUSDByUsageScope":{"<scope>":…}}`). Rows and recordings written before
 *   the object form hold it, and the conversation's overhead column keeps it as its storage format, because the
 *   atomic increment of that column adds to one JSON path per scope
 *   ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::addOverheadCostsToConversation()}).
 *
 * Every reader accepts both forms; no stored row is rewritten.
 */
class AIUsageBookedCostsStoredForm
{
    /** The key of the per-scope map in the keyed form. */
    public const string KEY_COST_IN_USD_BY_USAGE_SCOPE = 'costInUSDByUsageScope';

    /** The key of the per-scope set in the object form. */
    public const string KEY_SCOPE_BOOKED_COSTS = 'scopeBookedCosts';

    /**
     * The booked costs a stored form describes, in either form (array or object).
     *
     * @param array|object|null $storedForm
     * @return AIUsageBookedCosts|null Null when the form is missing or holds no booking
     */
    public static function buildBookedCostsFromStoredForm(array|object|null $storedForm): ?AIUsageBookedCosts
    {
        if ($storedForm === null) {
            return null;
        }
        $storedFormObject = is_array($storedForm) ? Arr::toObject($storedForm) : $storedForm;
        if (!is_object($storedFormObject) || (int)($storedFormObject->bookingCount ?? 0) <= 0) {
            return null;
        }
        $objectForm = static::buildObjectFormFromStoredForm($storedFormObject);
        $aIUsageBookedCosts = new AIUsageBookedCosts();
        $aIUsageBookedCosts->setPropertiesFromObject($objectForm, false);
        return $aIUsageBookedCosts;
    }

    /**
     * The object form of a stored form: a keyed form gets its per-scope map turned into the elements list, an object
     * form is returned as it is. The given object is not changed.
     *
     * @param object $storedForm
     * @return object
     */
    public static function buildObjectFormFromStoredForm(object $storedForm): object
    {
        $keyedScopeAmounts = $storedForm->{static::KEY_COST_IN_USD_BY_USAGE_SCOPE} ?? null;
        if ($keyedScopeAmounts === null || isset($storedForm->{static::KEY_SCOPE_BOOKED_COSTS})) {
            return $storedForm;
        }
        $objectForm = clone $storedForm;
        unset($objectForm->{static::KEY_COST_IN_USD_BY_USAGE_SCOPE});
        $scopeBookedCostElements = [];
        foreach ((array)$keyedScopeAmounts as $usageScope => $costInUSD) {
            $scopeBookedCostElement = new stdClass();
            $scopeBookedCostElement->usageScope = (string)$usageScope;
            $scopeBookedCostElement->costInUSD = (float)$costInUSD;
            $scopeBookedCostElements[] = $scopeBookedCostElement;
        }
        $scopeBookedCosts = new stdClass();
        $scopeBookedCosts->elements = $scopeBookedCostElements;
        $objectForm->{static::KEY_SCOPE_BOOKED_COSTS} = $scopeBookedCosts;
        return $objectForm;
    }

    /**
     * The keyed form of booked costs: the storage format of the conversation's overhead column.
     *
     * @param AIUsageBookedCosts $aIUsageBookedCosts
     * @return array{totalCostInUSD: float, bookingCount: int, costInUSDByUsageScope: object}
     */
    public static function buildKeyedForm(AIUsageBookedCosts $aIUsageBookedCosts): array
    {
        $costInUSDByUsageScope = [];
        foreach ($aIUsageBookedCosts->scopeBookedCosts->getElements() as $aIUsageScopeBookedCost) {
            $costInUSDByUsageScope[$aIUsageScopeBookedCost->usageScope] = $aIUsageScopeBookedCost->costInUSD;
        }
        return [
            'totalCostInUSD' => $aIUsageBookedCosts->totalCostInUSD,
            'bookingCount' => $aIUsageBookedCosts->bookingCount,
            // an object, so an empty map is stored as {} like the atomic increment writes it
            static::KEY_COST_IN_USD_BY_USAGE_SCOPE => (object)$costInUSDByUsageScope,
        ];
    }
}
