<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The settings that gate every DECISION-MODEL call in the agent loop (the owner's hard requirement:
 * removable without effect). Read from the COMMITTED config `config/app/AI/decisionModel.php` through
 * {@see current()} — no environment variable reads into it (an eval's arm is a CLI option written as the
 * conversation's row, {@see RunSettings}). The pure {@see fromValues()} is what the unit tests exercise.
 *
 * - `mode` = `off` (not one HTTP call, not one changed byte) | `shadow` (call, stamp the verdict, act on today's
 *   logic) | `on` (act on the verdict).
 * - `sites` = site → mode; a site not listed stays off, except the two preload sites ({@see DEFAULT_ON_SITES}).
 * - `timeoutSeconds` = the per-call HTTP timeout (2 s — ~3× the measured p95, with cold-start headroom).
 * - `breakerSeconds` = how long the shared circuit breaker keeps the model out after a transport failure.
 * - `turnClosingMode` = `deterministicCounter` (the deterministic checked-stop ladder first, the decision model only where it
 *   stayed silent) | `decisionModel` (the decision model judges every askable final text, the ladder is the FALLBACK when the decision model
 *   cannot answer) | `counterThenDecisionModel` (the counter proposes, the decision model confirms — the decision model is asked only
 *   on a text the counter wants to bounce; the bounce happens when the decision model agrees or cannot answer). A TURN-CLOSING mode,
 *   not a site mode — meaningful only while the `turnClosing` site is `on` (`counterThenDecisionModel` also under
 *   `shadow`, where the sample of the decision model's confirmations is recorded and the counter's bounce always stands).
 * - the effort a call is sent with when the `reasoningEffort` site does not raise is NOT a setting here: it is the
 *   model catalog's agentic effort (`models.php`), replaced by a purpose's own `reasoningEffort`.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionModelSettings extends ValueObject
{
    public const string MODE_OFF = 'off';
    public const string MODE_SHADOW = 'shadow';
    public const string MODE_ON = 'on';

    /** Site B only: the account's arm decides — `CRC32(accountId) % 3` → control / shadow / on. */
    public const string MODE_COHORT = 'cohort';

    public const string ARM_CONTROL = 'control';
    public const string ARM_SHADOW = 'shadow';
    public const string ARM_ON = 'on';

    /** The reply-language decision at the turn start. */
    public const string SITE_REPLY_LANGUAGE = 'replyLanguage';

    /** The turn-closing decision on a final answer with zero work tool calls (announce vs deliver). */
    public const string SITE_TURN_CLOSING = 'turnClosing';

    /** The skill preload at the turn start, decided by the model. */
    public const string SITE_SKILL_PRELOAD = 'skillPreload';

    /** The skill preload from the next plan item's `skillNames` (deterministic, no model). */
    public const string SITE_PLAN_ITEM = 'planItemSkillPreload';

    /** The reasoning effort of the owner turn, decided at the turn start. */
    public const string SITE_REASONING_EFFORT = 'reasoningEffort';

    /** The stall-recovery move at the SECOND stall of a turn. */
    public const string SITE_STALL_RECOVERY = 'stallRecovery';

    /**
     * The task-plan dynamics site — whether an owner turn needs a working plan, asked at the turn start when
     * no plan with open items is active, re-asked mid-turn on a middle band, and the retirement of a finished plan.
     * Configured by TWO SWITCHES instead of a mode word: `askDecisionModel` (is the decision model
     * asked and its verdict recorded) and `modelReceivesVerdict` (does the chat model get the verdict as a directive,
     * with the plan retirement, the tick-with-delivery rule and the rewritten trigger). Internally the pair maps to the
     * three-step scale every site shares: not asked = `off`, asked without receiving = `shadow`, both = `on`.
     */
    public const string SITE_PLAN_DYNAMICS = 'planDynamics';

    /** The injection check of the CURRENT owner message at the turn start (state = that message only). */
    public const string SITE_OWNER_MESSAGE_INJECTION = 'ownerMessageInjection';

    /**
     * The background screening of untrusted tool results (text a tool fetched from outside: a web page, a third party's
     * message or comment).
     */
    public const string SITE_TOOL_RESULT_INJECTION = 'toolResultInjection';

    /**
     * Inside the five tools that need the owner's personal yes — did the owner explicitly agree to exactly this
     * step? FAIL-CLOSED, unlike every other site: `off` (global, site or the row kill switch) is NOT a consent.
     */
    public const string SITE_OWNER_CONSENT = 'ownerConsent';

    /**
     * At the turn start, is the owner's message answered by a widget the model writes directly
     * (a clock, the weather, a draft, a layout the owner named) — folded into the turn-start request, applied only when
     * the skill preload abstained ({@see AgentDirectWidgetDecision}).
     */
    public const string SITE_DIRECT_WIDGET = 'directWidget';

    /**
     * The turn-start line site: at the turn start, does the assistant have to read, search or change something before it can
     * answer — then the activity stack opens with one generated line on what it does now ({@see AgentTurnStartLineDecision}).
     */
    public const string SITE_TURN_START_LINE = 'turnStartLine';

    /** The config keys of the two plan-dynamics switches under `sites.planDynamics`. */
    public const string PLAN_DYNAMICS_ASK_DECISION_MODEL = 'askDecisionModel';
    public const string PLAN_DYNAMICS_MODEL_RECEIVES_VERDICT = 'modelReceivesVerdict';

    /**
     * Every site of the module, canonical spelling, in the order the panels show them. An application adds the sites
     * of its own decisions in a class at the same path (it extends this one and redeclares the list, its own sites
     * where its panels show them); the code reads {@see getSites()}, never this constant.
     */
    public const array SITES = [
        self::SITE_REPLY_LANGUAGE,
        self::SITE_SKILL_PRELOAD,
        self::SITE_PLAN_ITEM,
        self::SITE_TURN_CLOSING,
        self::SITE_REASONING_EFFORT,
        self::SITE_STALL_RECOVERY,
        self::SITE_PLAN_DYNAMICS,
        self::SITE_OWNER_MESSAGE_INJECTION,
        self::SITE_TOOL_RESULT_INJECTION,
        self::SITE_OWNER_CONSENT,
        self::SITE_DIRECT_WIDGET,
        self::SITE_TURN_START_LINE,
    ];


    /** The config key of the versioned baseline (`config/app/AI/decisionModel.php`). */
    public const string CONFIG_KEY = 'AI.decisionModel';

    /**
     * Sites that run `on` WITHOUT being listed in the config's `sites` (skill preload is the
     * default). The global `mode: off` still vetoes them; listing one with `off` / `shadow` overrides the default.
     *
     * @var string[]
     */
    public const array DEFAULT_ON_SITES = [self::SITE_SKILL_PRELOAD, self::SITE_PLAN_ITEM];

    /** 2.0 s: the measured warm p95 is 0.7 s, but the FIRST call of a cold PHP process (DNS + TLS) hit 1.5 s live. */
    public const float DEFAULT_TIMEOUT_SECONDS = 2.0;
    public const int DEFAULT_BREAKER_SECONDS = 60;


    /** The checked-stop turn-closing modes of the `turnClosing` site (the third: the counter, then the decision model). */
    public const string TURN_CLOSING_MODE_DETERMINISTIC_COUNTER = 'deterministicCounter';
    public const string TURN_CLOSING_MODE_DECISION_MODEL = 'decisionModel';
    public const string TURN_CLOSING_MODE_COUNTER_THEN_DECISION_MODEL = 'counterThenDecisionModel';

    /** @var string[] Every turn-closing mode, canonical spelling. */
    public const array TURN_CLOSING_MODES = [
        self::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER,
        self::TURN_CLOSING_MODE_DECISION_MODEL,
        self::TURN_CLOSING_MODE_COUNTER_THEN_DECISION_MODEL,
    ];

    /** The reasoning-effort values the effort site may decide, in raising order. */
    public const string REASONING_EFFORT_LOW = 'low';
    public const string REASONING_EFFORT_MEDIUM = 'medium';
    public const string REASONING_EFFORT_HIGH = 'high';

    /** @var string[] The three efforts, lowest first — the order the raise-only rule and the ceiling clamp read. */
    public const array REASONING_EFFORTS = [self::REASONING_EFFORT_LOW, self::REASONING_EFFORT_MEDIUM, self::REASONING_EFFORT_HIGH];

    public string $mode = self::MODE_OFF;

    /** @var string[] The decision sites the config lists, lowercase, deduplicated. */
    public array $enabledSites = [];

    /**
     * @var array<string, string> Per-site modes: the config's `sites` (`replyLanguage: on`, `skillPreload: shadow`).
     * Lowercase site → mode. A bare site name keeps meaning the global mode; an unknown mode makes the site OFF.
     */
    public array $siteModes = [];

    public float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS;

    public int $breakerSeconds = self::DEFAULT_BREAKER_SECONDS;

    /** @var string The environment's turn-closing mode of the `turnClosing` site: one of {@see TURN_CLOSING_MODES}, default `deterministicCounter`. */
    public string $turnClosingMode = self::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;

    /** @var bool Plan-dynamics switch 1: the decision model is asked the plan question (and recorded). False = the site is off. */
    public bool $planDynamicsAskDecisionModel = false;

    /** @var bool Plan-dynamics switch 2: the chat model receives the verdict (directive, retirement, tick rule, trigger). Inert while switch 1 is false. */
    public bool $planDynamicsModelReceivesVerdict = false;


    /**
     * The settings the loop runs with (a setting, not a database row — the
     * committed config alone): `config/app/AI/decisionModel.php`; the conversation's row comes last
     * ({@see siteModeForConversation()}). Every site reads THIS. Pure apart from the config read; never throws.
     */
    public static function current(): self
    {
        return self::$forCurrentProcess ?? self::fromConfigured(self::readConfiguredArray());
    }

    /** @var self|null A test seam: the settings {@see current()} answers with in this process instead of the config file. */
    protected static ?self $forCurrentProcess = null;

    /**
     * Makes {@see current()} answer with these settings for the rest of the process (null = the config file again).
     * For unit tests that exercise a site under a mode the committed config does not run — production code never calls it.
     */
    public static function useForCurrentProcess(?self $settings): void
    {
        self::$forCurrentProcess = $settings;
    }

    /** The config file — the panel's "configuration" column; the same object as {@see current()}. */
    public static function fromConfiguration(): self
    {
        return self::current();
    }

    /**
     * The config array (the shape of `decisionModel.php`) as settings. Public and pure for the tests.
     *
     * @param array<string, mixed> $configured `mode`, `sites` (site → mode), `turnClosingMode`, `timeoutSeconds`, `breakerSeconds`
     */
    public static function fromConfigured(array $configured): self
    {
        $sitesFromConfig = [];
        $planDynamicsSwitches = null;
        foreach ((array) ($configured['sites'] ?? []) as $site => $mode) {
            if (is_string($site) && trim($site) !== '' && strtolower(trim($site)) === strtolower(self::SITE_PLAN_DYNAMICS) && is_array($mode)) {
                // the plan-dynamics site is configured by its two switches, not a mode word
                $planDynamicsSwitches = $mode;
                continue;
            }
            if (is_string($site) && is_string($mode) && trim($site) !== '' && trim($mode) !== '') {
                $sitesFromConfig[] = trim($site) . ':' . trim($mode);
            }
        }
        return self::fromValues(
            $configured['mode'] ?? null,
            $sitesFromConfig === [] ? null : implode(',', $sitesFromConfig),
            $configured['timeoutSeconds'] ?? null,
            $configured['breakerSeconds'] ?? null,
            $configured['turnClosingMode'] ?? null,
            $planDynamicsSwitches[self::PLAN_DYNAMICS_ASK_DECISION_MODEL] ?? null,
            $planDynamicsSwitches[self::PLAN_DYNAMICS_MODEL_RECEIVES_VERDICT] ?? null
        );
    }

    /** @return array<string, mixed> The config file's array ([] when absent or unreadable — then everything is off). */
    protected static function readConfiguredArray(): array
    {
        try {
            $configured = Config::get(self::CONFIG_KEY);
        } catch (Throwable) {
            return [];
        }
        return is_array($configured) ? $configured : [];
    }

    /**
     * The class that declares the sites: the application's class at the same path, else this one.
     *
     * @return class-string<AgentDecisionModelSettings>
     */
    public static function getSettingsClassOfTheApplication(): string
    {
        /** @var class-string<AgentDecisionModelSettings> $settingsClassOfTheApplication */
        $settingsClassOfTheApplication = DDDService::instance()->getContainerServiceClassNameForClass(self::class) ?? self::class;
        return $settingsClassOfTheApplication;
    }

    /** @return string[] Every site, canonical spelling, as the application declares them ({@see SITES}) */
    public static function getSites(): array
    {
        return (self::getSettingsClassOfTheApplication())::SITES;
    }

    /** The canonical spelling of a site name, case-insensitive; null for anything unknown. */
    public static function canonicalSite(string $site): ?string
    {
        $normalized = strtolower(trim($site));
        foreach (self::getSites() as $canonicalSite) {
            if ($normalized === strtolower($canonicalSite)) {
                return $canonicalSite;
            }
        }
        return null;
    }

    /** @return array<string, string> Every site → its effective mode (the panels' summary). */
    public function siteModesSummary(): array
    {
        $summary = [];
        foreach (self::getSites() as $site) {
            $summary[$site] = $this->siteMode($site);
        }
        return $summary;
    }


    /** A switch value from the config: true / 1 / yes / on → true; anything else (incl. null) → false. */
    public static function parseSwitch(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)($value ?? ''))), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The two switches as the site's mode on the shared scale — not asked = `off`, asked without the model
     * receiving = `shadow`, both = `on`. The pure mapping the config and the conversation row both use.
     */
    public static function planDynamicsModeFromSwitches(bool $askDecisionModel, bool $modelReceivesVerdict): string
    {
        if (!$askDecisionModel) {
            return self::MODE_OFF;
        }
        return $modelReceivesVerdict ? self::MODE_ON : self::MODE_SHADOW;
    }


    /**
     * Pure parse of the four raw values — an unknown mode, an empty site list, or an unparsable number fall back to
     * the SAFE default (off / none / 2 s / 60 s). Never throws: a mistyped flag must not break the turn.
     */
    public static function fromValues(mixed $mode, mixed $sites, mixed $timeoutSeconds, mixed $breakerSeconds, mixed $turnClosingMode = null, mixed $planDynamicsAskDecisionModel = null, mixed $planDynamicsModelReceivesVerdict = null): static
    {
        $settings = new static();
        $normalizedMode = strtolower(trim((string)($mode ?? '')));
        $settings->mode = in_array($normalizedMode, [self::MODE_SHADOW, self::MODE_ON], true) ? $normalizedMode : self::MODE_OFF;
        $enabledSites = [];
        $siteModes = [];
        foreach (explode(',', (string)($sites ?? '')) as $siteEntry) {
            // `name:mode` is split BEFORE normalizing — an older parser lowercased the whole entry and
            // would have read `replyLanguage:on` as an unknown site, silently switching the language site off.
            [$siteName, $siteMode] = array_pad(explode(':', trim($siteEntry), 2), 2, null);
            $siteName = strtolower(trim((string) $siteName));
            if ($siteName === '') {
                continue;
            }
            $enabledSites[$siteName] = true;
            if ($siteMode !== null) {
                $siteMode = strtolower(trim($siteMode));
                $siteModes[$siteName] = in_array($siteMode, [self::MODE_OFF, self::MODE_SHADOW, self::MODE_ON, self::MODE_COHORT], true)
                    ? $siteMode
                    : self::MODE_OFF; // an unknown site mode = off for that site, never the global mode
            }
        }
        $settings->enabledSites = array_keys($enabledSites);
        $settings->siteModes = $siteModes;
        $parsedTimeout = is_numeric($timeoutSeconds) ? (float)$timeoutSeconds : self::DEFAULT_TIMEOUT_SECONDS;
        $settings->timeoutSeconds = $parsedTimeout > 0 ? $parsedTimeout : self::DEFAULT_TIMEOUT_SECONDS;
        $parsedBreaker = is_numeric($breakerSeconds) ? (int)$breakerSeconds : self::DEFAULT_BREAKER_SECONDS;
        $settings->breakerSeconds = $parsedBreaker > 0 ? $parsedBreaker : self::DEFAULT_BREAKER_SECONDS;
        // case-insensitive (the values are camel-case); anything unknown = today
        $settings->turnClosingMode = self::canonicalTurnClosingMode($turnClosingMode) ?? self::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;
        // the two switches; a legacy `planDynamics:<mode>` site entry is honoured as the equivalent pair
        $settings->planDynamicsAskDecisionModel = self::parseSwitch($planDynamicsAskDecisionModel);
        $settings->planDynamicsModelReceivesVerdict = self::parseSwitch($planDynamicsModelReceivesVerdict);
        $legacyPlanDynamicsMode = $siteModes[strtolower(self::SITE_PLAN_DYNAMICS)] ?? null;
        if ($planDynamicsAskDecisionModel === null && $planDynamicsModelReceivesVerdict === null && $legacyPlanDynamicsMode !== null) {
            $settings->planDynamicsAskDecisionModel = $legacyPlanDynamicsMode !== self::MODE_OFF;
            $settings->planDynamicsModelReceivesVerdict = $legacyPlanDynamicsMode === self::MODE_ON;
        }
        return $settings;
    }

    /** The canonical spelling of a turn-closing mode, case-insensitive; null for anything unknown. */
    public static function canonicalTurnClosingMode(mixed $turnClosingMode): ?string
    {
        $normalized = strtolower(trim((string)($turnClosingMode ?? '')));
        foreach (self::TURN_CLOSING_MODES as $canonicalMode) {
            if ($normalized === strtolower($canonicalMode)) {
                return $canonicalMode;
            }
        }
        return null;
    }

    /** The canonical spelling of a reasoning effort (`low` / `medium` / `high`), case-insensitive; null for anything else. */
    public static function canonicalReasoningEffort(mixed $reasoningEffort): ?string
    {
        $normalized = strtolower(trim((string)($reasoningEffort ?? '')));
        return in_array($normalized, self::REASONING_EFFORTS, true) ? $normalized : null;
    }

    /** The rank of an effort in {@see REASONING_EFFORTS} (0 = low); -1 for a value outside the three. */
    public static function reasoningEffortRank(?string $reasoningEffort): int
    {
        $canonical = self::canonicalReasoningEffort($reasoningEffort);
        return $canonical === null ? -1 : (int)array_search($canonical, self::REASONING_EFFORTS, true);
    }

    /**
     * The EFFECTIVE mode of one site for ONE conversation: the environment's site mode
     * ({@see siteMode()} — global `off` and an unlisted or `off` site are the absolute veto), then the conversation's
     * row: the kill switch (`RunSettings::$decisionModelOff`) silences EVERY site here, the skill-preload row lifts
     * `shadow` to `on` or silences the site, the turn-closing row `off` silences the site (its
     * `deterministicCounter` / `decisionModel` values are TURN-CLOSING modes, read by {@see turnClosingModeForConversation()}, never a site
     * mode). `cohort` is resolved through the account's arm for the skill-preload site. ONE helper for every site,
     * so no site can miss the kill switch.
     */
    public function siteModeForConversation(string $site, AIConversation $conversation): string
    {
        $site = strtolower(trim($site));
        $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : null;
        $siteMode = $site === strtolower(self::SITE_SKILL_PRELOAD)
            ? $this->resolveSkillPreloadModeForAccountId($conversation->accountId ?? null)
            : $this->siteMode($site);
        if ($runSettings === null || $this->mode === self::MODE_OFF) {
            return $siteMode;
        }
        if ($siteMode === self::MODE_OFF && $site !== strtolower(self::SITE_PLAN_DYNAMICS)) {
            return $siteMode; // a plan-dynamics row may switch the site on under a global on/shadow
        }
        if ($runSettings->decisionModelOff === true) {
            return self::MODE_OFF;
        }
        if ($site === strtolower(self::SITE_SKILL_PRELOAD)) {
            $rowSwitch = $runSettings->decisionModelSkillPreload ?? null;
            if ($rowSwitch === RunSettings::DECISION_MODEL_SKILL_PRELOAD_ON) {
                return self::MODE_ON;
            }
            if ($rowSwitch === RunSettings::DECISION_MODEL_SKILL_PRELOAD_OFF) {
                return self::MODE_OFF;
            }
            return $siteMode;
        }
        if ($site === strtolower(self::SITE_TURN_CLOSING) && ($runSettings->decisionModelTurnClosing ?? null) === RunSettings::DECISION_MODEL_TURN_CLOSING_OFF) {
            return self::MODE_OFF;
        }
        if ($site === strtolower(self::SITE_REASONING_EFFORT) && ($runSettings->decisionModelReasoningEffort ?? null) === RunSettings::DECISION_MODEL_REASONING_EFFORT_OFF) {
            return self::MODE_OFF; // `off` = the configured effort, no decision model; a value row is a PIN, never a site mode
        }
        if ($site === strtolower(self::SITE_STALL_RECOVERY)) {
            $rowSwitch = $runSettings->decisionModelStallRecovery ?? null;
            if ($rowSwitch === RunSettings::DECISION_MODEL_STALL_RECOVERY_ON) {
                return self::MODE_ON;
            }
            if ($rowSwitch === RunSettings::DECISION_MODEL_STALL_RECOVERY_OFF) {
                return self::MODE_OFF;
            }
        }
        if ($site === strtolower(self::SITE_REPLY_LANGUAGE)) {
            // the language row: `on` lifts `shadow` here, `off` silences the site here
            $rowSwitch = $runSettings->decisionModelReplyLanguage ?? null;
            if ($rowSwitch === RunSettings::DECISION_MODEL_REPLY_LANGUAGE_ON) {
                return self::MODE_ON;
            }
            if ($rowSwitch === RunSettings::DECISION_MODEL_REPLY_LANGUAGE_OFF) {
                return self::MODE_OFF;
            }
        }
        if ($site === strtolower(self::SITE_PLAN_DYNAMICS)) {
            // each row switch overrides its environment switch (null = inherit); the pair maps to the mode.
            // The global `off` was already returned above — a row never switches the site on under a global off.
            $askDecisionModel = $runSettings->planDynamicsAskDecisionModel ?? $this->planDynamicsAskDecisionModel;
            $modelReceivesVerdict = $runSettings->planDynamicsModelReceivesVerdict ?? $this->planDynamicsModelReceivesVerdict;
            return self::planDynamicsModeFromSwitches($askDecisionModel, $modelReceivesVerdict);
        }
        return $siteMode;
    }

    /**
     * The effort FLOOR the conversation's row pins (`low` / `medium` / `high`), or null when the row
     * inherits / is off. The site may raise above the pin, never lower it; the pin applies even under `shadow`.
     */
    public function reasoningEffortPinForConversation(AIConversation $conversation): ?string
    {
        $rowValue = isset($conversation->runSettings) ? ($conversation->runSettings->decisionModelReasoningEffort ?? null) : null;
        return self::canonicalReasoningEffort($rowValue);
    }

    /**
     * The TURN-CLOSING mode the checked stop runs with for ONE conversation: `decisionModel` only while the
     * `turnClosing` site is `on` for this conversation AND the row (`deterministicCounter` / `decisionModel`) or, when the row inherits,
     * the environment says so; every other situation is `deterministicCounter` = today's sequence. A row value never changes the
     * site mode (under `shadow` the verdict stays recorded-only, the row is inert).
     */
    public function turnClosingModeForConversation(AIConversation $conversation): string
    {
        $siteMode = $this->siteModeForConversation(self::SITE_TURN_CLOSING, $conversation);
        if ($siteMode === self::MODE_OFF) {
            return self::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;
        }
        $rowValue = isset($conversation->runSettings) ? ($conversation->runSettings->decisionModelTurnClosing ?? null) : null;
        $resolvedMode = self::canonicalTurnClosingMode($rowValue) ?? $this->turnClosingMode;
        if ($siteMode !== self::MODE_ON) {
            // under `shadow` only the counter-first mode is reachable — its decision-model call is a SAMPLE on the
            // counter's bounces (a shadow verdict never decides; the counter's bounce stands); `decisionModel` under
            // `shadow` stays today's sequence (a shadow verdict would otherwise have to be "final").
            return $resolvedMode === self::TURN_CLOSING_MODE_COUNTER_THEN_DECISION_MODEL
                ? self::TURN_CLOSING_MODE_COUNTER_THEN_DECISION_MODEL
                : self::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;
        }
        return $resolvedMode;
    }

    /**
     * The EFFECTIVE mode of one site: the global `off` wins; else the site's explicit mode; else the
     * global mode; an unlisted site is `off`. `cohort` is meaningful for the skill-preload site only — elsewhere it
     * counts as `shadow` (never an unintended apply).
     */
    public function siteMode(string $site): string
    {
        $site = strtolower(trim($site));
        if ($this->mode === self::MODE_OFF) {
            return self::MODE_OFF;
        }
        if ($site === strtolower(self::SITE_PLAN_DYNAMICS)) {
            // derived from the two switches, never from the site list
            return self::planDynamicsModeFromSwitches($this->planDynamicsAskDecisionModel, $this->planDynamicsModelReceivesVerdict);
        }
        if (!in_array($site, $this->enabledSites, true)) {
            // the skill-preload sites are ON by default — an unlisted default-on site runs
            // `on`; every other unlisted site stays off
            return in_array($site, array_map('strtolower', self::DEFAULT_ON_SITES), true) ? self::MODE_ON : self::MODE_OFF;
        }
        $siteMode = $this->siteModes[$site] ?? $this->mode;
        if ($siteMode === self::MODE_COHORT && $site !== strtolower(self::SITE_SKILL_PRELOAD)) {
            return self::MODE_SHADOW;
        }
        return $siteMode;
    }

    /** True when the decision model may be CALLED for this site (shadow, on or cohort). Off = never called. */
    public function isSiteActive(string $site): bool
    {
        return $this->siteMode($site) !== self::MODE_OFF;
    }

    /** True when a verdict may be ACTED on for this site (mode `on`); false under `shadow` / `cohort` (the arm decides there). */
    public function appliesDecisionsForSite(string $site): bool
    {
        return $this->siteMode($site) === self::MODE_ON;
    }

    /**
     * True when a verdict may be ACTED on (global mode `on`); false under `shadow`, where it is only stamped. The
     * PER-SITE readers ({@see appliesDecisionsForSite()}) are what the sites use; this stays for
     * callers that reason about the global mode only.
     */
    public function appliesDecisions(): bool
    {
        return $this->mode === self::MODE_ON;
    }

    /**
     * The gate 6 ARM of an account under the `cohort` site mode: `CRC32(accountId) % 3` → 0 control
     * (no questions of this site in the request), 1 shadow (asked, never applied), 2 on. The same expression as the
     * measurement SQL, so the PHP and SQL assignments agree by construction.
     */
    public static function resolveArmForAccountId(int|string|null $accountId): string
    {
        $bucket = crc32((string) ($accountId ?? '')) % 3;
        return match ($bucket) {
            0 => self::ARM_CONTROL,
            1 => self::ARM_SHADOW,
            default => self::ARM_ON,
        };
    }

    /**
     * The mode the skill-preload site RUNS with for one conversation: the site mode, with `cohort`
     * resolved through the account's arm — control = off for this conversation, shadow, on.
     */
    public function resolveSkillPreloadModeForAccountId(int|string|null $accountId): string
    {
        $siteMode = $this->siteMode(self::SITE_SKILL_PRELOAD);
        if ($siteMode !== self::MODE_COHORT) {
            return $siteMode;
        }
        return match (self::resolveArmForAccountId($accountId)) {
            self::ARM_CONTROL => self::MODE_OFF,
            self::ARM_SHADOW => self::MODE_SHADOW,
            default => self::MODE_ON,
        };
    }
}
