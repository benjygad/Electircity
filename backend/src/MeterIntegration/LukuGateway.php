<?php
declare(strict_types=1);

namespace Sems\MeterIntegration;

/**
 * Placeholder for a future AUTHORIZED TANESCO/LUKU integration.
 * Intentionally inert: enabling it requires verified credentials, an
 * authorized interface specification, and explicit configuration.
 */
final class LukuGateway implements MeterGatewayInterface
{
    public function name(): string { return 'luku_unconfigured'; }

    public function isLive(): bool { return false; }

    public function fetchLatestReading(int $meterId, string $meterNumber): ?array { return null; }

    public function submitToken(int $meterId, string $meterNumber, string $maskedTokenRef): array
    {
        return [
            'status' => 'integration_unavailable',
            'detail' => 'No authorized LUKU integration is configured. Token was not transmitted anywhere.',
        ];
    }
}
