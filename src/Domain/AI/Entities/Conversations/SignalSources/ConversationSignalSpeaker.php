<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\SignalSources;

/**
 * Who speaks through a signal source — one of the basic properties every
 * {@see ConversationSignalSource} declares; the derived questions read it ({@see ConversationSignalSourceDefaultAnswers}).
 * The owner's three (OWNER, PLATFORM, LOOP) plus the two the property table proved necessary: SUPERVISOR (its steer and
 * correction are neither the loop's nor a work order) and AGENT (the agent's own work coming back, and the markers on
 * its own text).
 */
enum ConversationSignalSpeaker: string
{
    /** The owner acted: a click, a confirm, a form submit, a channel connection, a navigation, a phone call. */
    case OWNER = 'OWNER';
    /** The agent's own work or text: its children settled, its background work finished, a chunk continues, its report markers. */
    case AGENT = 'AGENT';
    /** The supervisor: a steer or a correction. */
    case SUPERVISOR = 'SUPERVISOR';
    /** The turn loop's own notes: nudges, bounces, replays, bookkeeping. */
    case LOOP = 'LOOP';
    /** The platform or a domain service: work orders, memory, live data, channel calls, widget notices. */
    case PLATFORM = 'PLATFORM';
}
