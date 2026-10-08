<?php
declare(strict_types=1);

namespace Sems;

use Sems\Controllers\{AdminController, AlertController, AuthController, CustomerController,
    FaultController, MeterController, NotificationController, ReportController, TransactionController};
use Sems\Core\{Auth, HttpException, Response};
use Sems\MeterIntegration\GatewayFactory;

/**
 * Route table for /api/v1.
 * 'auth' => false: public | true: any authenticated user | role string(s): enforced server-side.
 * Authorization is ALWAYS enforced here/in controllers — never by client input.
 */
final class Router
{
    /** @return array{0:string,1:callable,2:mixed}[] */
    public static function routes(): array
    {
        return [
            // --- health / meta
            ['GET', '/health', fn() => Response::ok(['status' => 'ok', 'time_utc' => gmdate('c'), 'env' => \Sems\Core\Env::get('APP_ENV', 'production')]), false],
            ['GET', '/system/gateway', function () {
                $g = GatewayFactory::make();
                Response::ok([
                    'gateway' => $g->name(),
                    'live' => $g->isLive(),
                    'notice' => 'No authorized TANESCO/LUKU integration exists in this academic prototype. Meter data shown is simulated.',
                ]);
            }, true],

            // --- authentication
            ['POST', '/auth/register', [AuthController::class, 'register'], false],
            ['POST', '/auth/login', [AuthController::class, 'login'], false],
            ['POST', '/auth/forgot-password', [AuthController::class, 'forgotPassword'], false],
            ['POST', '/auth/reset-password', [AuthController::class, 'resetPassword'], false],
            ['POST', '/auth/logout', [AuthController::class, 'logout'], true],
            ['GET', '/auth/me', [AuthController::class, 'me'], true],
            ['PATCH', '/auth/me', [AuthController::class, 'updateMe'], true],

            // --- customers (admin)
            ['GET', '/customers', [CustomerController::class, 'index'], Auth::ROLE_ADMIN],
            ['POST', '/customers', [CustomerController::class, 'create'], Auth::ROLE_ADMIN],
            ['GET', '/customers/{id}', [CustomerController::class, 'show'], Auth::ROLE_ADMIN],
            ['PATCH', '/customers/{id}', [CustomerController::class, 'update'], Auth::ROLE_ADMIN],

            // --- meters
            ['GET', '/meters', [MeterController::class, 'index'], true],            // scoped in controller
            ['POST', '/meters', [MeterController::class, 'create'], Auth::ROLE_ADMIN],
            ['GET', '/meters/{id}', [MeterController::class, 'show'], true],
            ['POST', '/meters/{id}/assign', [MeterController::class, 'assign'], Auth::ROLE_ADMIN],
            ['GET', '/meters/{id}/readings', [MeterController::class, 'readings'], true],
            ['POST', '/meters/{id}/readings', [MeterController::class, 'addReading'], Auth::ROLE_ADMIN],
            ['GET', '/meters/{id}/consumption', [MeterController::class, 'consumption'], true],
            ['GET', '/meters/{id}/transactions', [TransactionController::class, 'index'], true],
            ['POST', '/meters/{id}/transactions', [TransactionController::class, 'create'], true],

            // --- anomaly alerts (admin)
            ['GET', '/alerts', [AlertController::class, 'index'], Auth::ROLE_ADMIN],
            ['GET', '/alerts/{id}', [AlertController::class, 'show'], Auth::ROLE_ADMIN],
            ['PATCH', '/alerts/{id}', [AlertController::class, 'update'], Auth::ROLE_ADMIN],

            // --- fault reports & support
            ['GET', '/fault-reports', [FaultController::class, 'index'], true],
            ['POST', '/fault-reports', [FaultController::class, 'create'], true],
            ['GET', '/fault-reports/{id}', [FaultController::class, 'show'], true],
            ['PATCH', '/fault-reports/{id}', [FaultController::class, 'update'], Auth::ROLE_ADMIN],
            ['GET', '/fault-reports/{id}/messages', [FaultController::class, 'messages'], true],
            ['POST', '/fault-reports/{id}/messages', [FaultController::class, 'addMessage'], true],

            // --- notifications
            ['GET', '/notifications', [NotificationController::class, 'index'], true],
            ['PATCH', '/notifications/{id}', [NotificationController::class, 'markRead'], true],
            ['POST', '/notifications/read-all', [NotificationController::class, 'markAllRead'], true],

            // --- reports (admin)
            ['GET', '/reports/consumption', [ReportController::class, 'consumption'], Auth::ROLE_ADMIN],
            ['GET', '/reports/transactions', [ReportController::class, 'transactions'], Auth::ROLE_ADMIN],
            ['GET', '/reports/faults', [ReportController::class, 'faults'], Auth::ROLE_ADMIN],

            // --- admin misc
            ['GET', '/admin/summary', [AdminController::class, 'summary'], Auth::ROLE_ADMIN],
            ['GET', '/admin/audit-logs', [AdminController::class, 'auditLogs'], Auth::ROLE_ADMIN],
            ['GET', '/admin/settings', [AdminController::class, 'settings'], Auth::ROLE_ADMIN],
            ['PATCH', '/admin/settings', [AdminController::class, 'updateSettings'], Auth::ROLE_ADMIN],
        ];
    }

    public static function dispatch(string $method, string $path): void
    {
        foreach (self::routes() as [$m, $pattern, $handler, $auth]) {
            if ($m !== $method) continue;
            $regex = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>\d+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $matches)) continue;

            $args = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($auth !== false) {
                $user = Auth::user(); // 401 if missing/invalid/expired
                if (is_string($auth) && $user['role'] !== $auth) {
                    throw HttpException::forbidden(); // 403: role enforced server-side
                }
            }
            $handler($args);
            return;
        }
        throw HttpException::notFound('Unknown API endpoint');
    }
}
