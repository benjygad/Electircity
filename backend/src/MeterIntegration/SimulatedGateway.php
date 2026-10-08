<?php
declare(strict_types=1);

namespace Sems\MeterIntegration;

/**
 * Development-only gateway. Produces clearly-labelled simulated data and
 * explicitly refuses to pretend a token reached a physical LUKU meter.
 */
final class SimulatedGateway implements MeterGatewayInterface
{
    public function name(): string { return 'simulated'; }

    public function isLive(): bool { return false; }

    public function fetchLatestReading(int $meterId, string $meterNumber): ?array
    {
        // In the prototype the simulator CLI (scripts/simulate.php) writes
        // readings to the database; nothing is fetched from a utility.
        return null;
    }

    public function submitToken(int $meterId, string $meterNumber, string $maskedTokenRef): array
    {
        return [
            'status' => 'simulated_recorded',
            'detail' => 'Token recorded in SEMS only. It was NOT sent to a physical LUKU meter — no authorized utility integration exists.',
        ];
    }
}
