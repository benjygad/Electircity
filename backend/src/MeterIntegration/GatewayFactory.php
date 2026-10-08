<?php
declare(strict_types=1);

namespace Sems\MeterIntegration;

use Sems\Core\Env;

/** Chooses the active gateway. Only 'simulated' is available today. */
final class GatewayFactory
{
    public static function make(): MeterGatewayInterface
    {
        $kind = Env::get('METER_GATEWAY', 'simulated');
        return match ($kind) {
            'luku' => new LukuGateway(),   // still returns integration_unavailable until authorized
            default => new SimulatedGateway(),
        };
    }
}
