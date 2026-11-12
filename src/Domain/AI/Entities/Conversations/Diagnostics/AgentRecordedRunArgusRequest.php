<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecordingPlayedRequest;

/**
 * ONE request a run sent to Argus, as the recorded-run comparison compares it: the endpoint and the body, and — for a
 * request a replay sent — the position of the recorded call that answered it. The harness builds the recorded ones
 * from the stored recording ({@see forEndpointAndRequestBody()}), {@see AgentRecordedRunComparison::buildPlayedArgusRequests()}
 * the replayed ones; {@see AgentRecordedRunComparison::buildComparison()} compares the two lists.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunArgusRequest extends ValueObject
{
    /** @var string The request's endpoint (method, scheme, host and path) */
    public string $endpoint = '';

    /** @var string The request body as sent */
    public string $requestBody = '';

    /** @var int|null The position of the recorded call that answered a replayed request; null when none did */
    public ?int $answeredByPosition = null;

    /**
     * @var bool Whether {@see $answeredByPosition} is known (a replayed request); a request without it is compared
     *      with the recorded call at its own position
     */
    public bool $hasAnsweredByPosition = false;

    /** @var string|null The request's kind (a replayed request only) */
    public ?string $kind = null;

    /** @var int|null The owner turn the request was sent in (a replayed request only) */
    public ?int $turnOrdinal = null;

    /**
     * A recorded request, or one whose answering call is not known.
     *
     * @param string $endpoint
     * @param string $requestBody
     * @return static
     */
    public static function forEndpointAndRequestBody(string $endpoint, string $requestBody): static
    {
        $agentRecordedRunArgusRequest = new static();
        $agentRecordedRunArgusRequest->endpoint = $endpoint;
        $agentRecordedRunArgusRequest->requestBody = $requestBody;
        return $agentRecordedRunArgusRequest;
    }

    /**
     * A replayed request and the position of the recorded call that answered it (null when none did).
     *
     * @param string $endpoint
     * @param string $requestBody
     * @param int|null $answeredByPosition
     * @return static
     */
    public static function forReplayedRequest(string $endpoint, string $requestBody, ?int $answeredByPosition): static
    {
        $agentRecordedRunArgusRequest = static::forEndpointAndRequestBody($endpoint, $requestBody);
        $agentRecordedRunArgusRequest->answeredByPosition = $answeredByPosition;
        $agentRecordedRunArgusRequest->hasAnsweredByPosition = true;
        return $agentRecordedRunArgusRequest;
    }

    /**
     * @param ArgusTransportRecordingPlayedRequest $playedRequest One request a replay sent through the transport recording
     * @return static
     */
    public static function fromPlayedRequest(ArgusTransportRecordingPlayedRequest $playedRequest): static
    {
        $agentRecordedRunArgusRequest = static::forReplayedRequest($playedRequest->endpoint, $playedRequest->requestBody, $playedRequest->answeredByPosition);
        $agentRecordedRunArgusRequest->kind = $playedRequest->kind;
        $agentRecordedRunArgusRequest->turnOrdinal = $playedRequest->turnOrdinal;
        return $agentRecordedRunArgusRequest;
    }

    /**
     * The position of the recorded call this request is compared with: the one that answered it when known, else the
     * request's own position.
     *
     * @param int $ownPosition The request's position among the replay's requests
     * @return int|null null when the request is known to have been answered by no recorded call
     */
    public function getComparedRecordedPosition(int $ownPosition): ?int
    {
        return $this->hasAnsweredByPosition ? $this->answeredByPosition : $ownPosition;
    }
}
