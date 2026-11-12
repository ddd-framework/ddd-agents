<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one ranking of one rerank eval case scored (the host's rerank eval scorer). A DDD ValueObject: transient, never
 * persisted — the verdict of one model call against the relevance the case gives each candidate.
 */
class SearchRerankEvalCaseScore extends ValueObject
{
    /** @var bool True when the ranking kept every REQUIRED candidate, no IRRELEVANT one, did not start with an IRRELEVANT one and named only existing candidates. */
    public bool $passed;

    /** @var bool False when the model call returned no ranking that could be read — the case fails on that alone. */
    public bool $reranked;

    /** @var int REQUIRED candidates of the case. */
    public int $requiredCandidateCount;

    /** @var int REQUIRED candidates the ranking kept. */
    public int $keptRequiredCandidateCount;

    /** @var int IRRELEVANT candidates of the case. */
    public int $irrelevantCandidateCount;

    /** @var int IRRELEVANT candidates the ranking kept. */
    public int $keptIrrelevantCandidateCount;

    /** @var bool True when the first candidate of the ranking is an IRRELEVANT one. */
    public bool $firstOfRankingIsIrrelevant;

    /** @var int[] REQUIRED candidates the ranking dropped, as candidate indices. */
    public array $missingRequiredCandidateIndices;

    /** @var int[] IRRELEVANT candidates the ranking kept, as candidate indices. */
    public array $keptIrrelevantCandidateIndices;

    /** @var int[] Labels of the ranking that name no candidate (a garbled answer such as a fused `c2013`). */
    public array $unknownLabelIndices;

    /**
     * @param int[] $missingRequiredCandidateIndices
     * @param int[] $keptIrrelevantCandidateIndices
     * @param int[] $unknownLabelIndices
     */
    public function __construct(
        bool $passed,
        bool $reranked,
        int $requiredCandidateCount,
        int $keptRequiredCandidateCount,
        int $irrelevantCandidateCount,
        int $keptIrrelevantCandidateCount,
        bool $firstOfRankingIsIrrelevant,
        array $missingRequiredCandidateIndices,
        array $keptIrrelevantCandidateIndices,
        array $unknownLabelIndices,
    ) {
        parent::__construct();
        $this->passed = $passed;
        $this->reranked = $reranked;
        $this->requiredCandidateCount = $requiredCandidateCount;
        $this->keptRequiredCandidateCount = $keptRequiredCandidateCount;
        $this->irrelevantCandidateCount = $irrelevantCandidateCount;
        $this->keptIrrelevantCandidateCount = $keptIrrelevantCandidateCount;
        $this->firstOfRankingIsIrrelevant = $firstOfRankingIsIrrelevant;
        $this->missingRequiredCandidateIndices = $missingRequiredCandidateIndices;
        $this->keptIrrelevantCandidateIndices = $keptIrrelevantCandidateIndices;
        $this->unknownLabelIndices = $unknownLabelIndices;
    }

    /**
     * The reasons the ranking failed the case, in words; empty when it passed.
     *
     * @return string[]
     */
    public function getFailureDescriptions(): array
    {
        if ($this->passed) {
            return [];
        }
        if (!$this->reranked) {
            return ['the model returned no ranking that could be read'];
        }
        $failureDescriptions = [];
        if ($this->unknownLabelIndices !== []) {
            $failureDescriptions[] = 'labels naming no candidate: c' . implode(', c', $this->unknownLabelIndices);
        }
        if ($this->missingRequiredCandidateIndices !== []) {
            $failureDescriptions[] = 'dropped required: c' . implode(', c', $this->missingRequiredCandidateIndices);
        }
        if ($this->keptIrrelevantCandidateIndices !== []) {
            $failureDescriptions[] = 'kept irrelevant: c' . implode(', c', $this->keptIrrelevantCandidateIndices);
        }
        if ($this->firstOfRankingIsIrrelevant) {
            $failureDescriptions[] = 'the first of the ranking is an irrelevant candidate';
        }
        return $failureDescriptions;
    }
}
