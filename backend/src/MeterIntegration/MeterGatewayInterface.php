<?php
declare(strict_types=1);

namespace Sems\MeterIntegration;

/**
 * Meter gateway abstraction.
 *
 * The prototype only has a SimulatedGateway. A future AUTHORIZED TANESCO/LUKU
 * interface (or a compatible hardware gateway) implements this same interface
 * and is returned by GatewayFactory — the rest of the system does not change.
 *
 * Implementations MUST:
 *  - return readings with source = 'hardware' only when data truly comes
 *    from an authorized integration;
 *  - never claim to load tokens into a physical meter unless the integration
 *    is verified and authorized.
 */
interface MeterGatewayInterface
{
    /** Stable identifier of the gateway implementation, e.g. 'simulated'. */
    public function name(): string;

    /** Whether this gateway can talk to real meters right now. */
    public function isLive(): bool;

    /**
     * Fetch the latest cumulative reading for a meter, or null when unavailable.
     * @return array{cumulative_kwh: float, voltage: ?float, current_amps: ?float, reading_time: string}|null
     */
    public function fetchLatestReading(int $meterId, string $meterNumber): ?array;

    /**
     * Submit a prepaid token for loading. MUST return status 'simulated_recorded'
     * unless an authorized live integration exists.
     * @return array{status: string, detail: string}
     */
    public function submitToken(int $meterId, string $meterNumber, string $maskedTokenRef): array;
}
