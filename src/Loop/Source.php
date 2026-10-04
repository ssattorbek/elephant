<?php
declare(strict_types=1);

namespace Elephant\Loop;

/**
 * External source of events, such as network I/O, that the event loop polls between ticks.
 */
interface Source
{
    /**
     * Whether the source has pending work that should keep the loop alive.
     */
    public function isActive(): bool;

    /**
     * Waits for activity and processes what arrived.
     *
     * @param float|null $timeout Maximum seconds to wait, or null to wait until something happens.
     */
    public function poll(?float $timeout): void;
}
