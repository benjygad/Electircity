<?php
declare(strict_types=1);

namespace Sems\Controllers;

use Sems\Core\{Auth, Audit, Db, HttpException, Input, Response, Validate};
use Sems\MeterIntegration\GatewayFactory;
use Sems\Services\NotificationService;
use function Sems\Core\paginate;

/**
 * Prepaid token transaction RECORDS.
 * In this prototype, purchases are simulated records only — no token is ever
 * transmitted to a physical LUKU meter, and the UI says so.
 */
final class TransactionController
{
    public static function index(array $args): void
    {
        $meter = Auth::accessibleMeter((int)$args['id']);
        [$limit, $offset, $page] = paginate();

        $where = ['tt.meter_id = ?']; $params = [$meter['id']];
        if (in_array($_GET['status'] ?? '', ['recorded', 'pending', 'failed'], true)) { $where[] = 'tt.status = ?'; $params[] = $_GET['status']; }
        if (!empty($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) { $where[] = 'DATE(tt.created_at) >= ?'; $params[] = $_GET['from']; }
        if (!empty($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])) { $where[] = 'DATE(tt.created_at) <= ?'; $params[] = $_GET['to']; }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val("SELECT COUNT(*) FROM token_transactions tt WHERE $whereSql", $params);
        $rows = Db::all(
            "SELECT tt.* FROM token_transactions tt WHERE $whereSql ORDER BY tt.created_at DESC LIMIT $limit OFFSET $offset",
            $params
        );
        Response::ok(array_map([Input::class, 'stamp'], $rows), 200, ['total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public static function create(array $args): void
    {
        $u = Auth::user();
        $meter = Auth::accessibleMeter((int)$args['id']); // ownership enforced
        $b = Input::body();
        Validate::required($b, ['amount']);
        $errors = [];
        if (!is_numeric($b['amount']) || (float)$b['amount'] <= 0) $errors['amount'] = 'Amount must be a positive number';
        if ((float)($b['amount'] ?? 0) > 10_000_000) $errors['amount'] = 'Amount is unrealistically large';
        $tokenRef = null;
        if (!empty($b['token'])) {
            if (!Validate::tokenInput((string)$b['token'])) $errors['token'] = 'A LUKU token is 20 digits (16–24 digits accepted)';
            else $tokenRef = Validate::maskToken((string)$b['token']); // masked — full token never stored
        }
        if ($errors) throw HttpException::badRequest('Validation failed', $errors);

        $gateway = GatewayFactory::make();
        $submission = $gateway->submitToken((int)$meter['id'], $meter['meter_number'], $tokenRef ?? '');

        $id = Db::insert('token_transactions', [
            'meter_id' => $meter['id'],
            'amount' => round((float)$b['amount'], 2),
            'token_reference' => $tokenRef,
            'transaction_type' => 'purchase',
            'status' => 'recorded',
            'source' => 'simulated', // honest label: prototype only
            'created_at' => Db::now(),
        ]);
        Audit::log((int)$u['id'], 'transaction_record', 'token_transaction', $id, 'meter=' . $meter['id'] . ' (simulated)');
        NotificationService::notifyUser((int)$u['id'], 'Token purchase recorded (simulated)',
            sprintf('A simulated purchase of TZS %s was recorded for meter %s. %s', number_format((float)$b['amount']), $meter['meter_number'], $submission['detail']));

        Response::ok([
            'transaction' => Input::stamp(Db::one('SELECT * FROM token_transactions WHERE id = ?', [$id])),
            'gateway' => ['name' => $gateway->name(), 'live' => $gateway->isLive()],
            'notice' => $submission['detail'],
        ], 201);
    }
}
