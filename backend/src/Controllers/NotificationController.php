<?php
declare(strict_types=1);

namespace Sems\Controllers;

use Sems\Core\{Auth, Db, HttpException, Input, Response};
use function Sems\Core\paginate;

final class NotificationController
{
    public static function index(array $args): void
    {
        $u = Auth::user();
        [$limit, $offset, $page] = paginate();
        $total = (int) Db::val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$u['id']]);
        $unread = (int) Db::val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$u['id']]);
        $rows = Db::all(
            "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit OFFSET $offset",
            [$u['id']]
        );
        Response::ok(array_map([Input::class, 'stamp'], $rows), 200, ['total' => $total, 'unread' => $unread, 'page' => $page, 'limit' => $limit]);
    }

    public static function markRead(array $args): void
    {
        $u = Auth::user();
        $id = (int)$args['id'];
        $n = Db::one('SELECT * FROM notifications WHERE id = ?', [$id]);
        if (!$n) throw HttpException::notFound('Notification not found');
        if ((int)$n['user_id'] !== (int)$u['id']) throw HttpException::forbidden('Not your notification');
        if ($n['read_at'] === null) Db::update('notifications', ['read_at' => Db::now()], 'id = ?', [$id]);
        Response::ok(Input::stamp(Db::one('SELECT * FROM notifications WHERE id = ?', [$id])));
    }

    public static function markAllRead(array $args): void
    {
        $u = Auth::user();
        Db::run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Db::now(), $u['id']]);
        Response::ok(['message' => 'All notifications marked as read']);
    }
}
