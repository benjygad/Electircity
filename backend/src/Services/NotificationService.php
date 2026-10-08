<?php
declare(strict_types=1);

namespace Sems\Services;

use Sems\Core\Db;

/** In-app notifications. Only created when the underlying event actually occurred. */
final class NotificationService
{
    public static function notifyUser(int $userId, string $title, string $message): void
    {
        Db::insert('notifications', [
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 150),
            'message' => mb_substr($message, 0, 1000),
            'created_at' => Db::now(),
        ]);
    }

    /** Notify every consumer linked to a meter (e.g. anomaly, low credit). */
    public static function notifyMeterOwners(int $meterId, string $title, string $message): void
    {
        $owners = Db::all('SELECT user_id FROM user_meters WHERE meter_id = ?', [$meterId]);
        foreach ($owners as $o) self::notifyUser((int)$o['user_id'], $title, $message);
    }

    /** Low-credit check invoked after consumption changes. */
    public static function maybeLowCredit(int $meterId): void
    {
        $credit = ConsumptionService::creditEstimate($meterId);
        $threshold = \Sems\Core\Settings::num('low_credit_threshold_tzs', 2000);
        if ($credit['estimated_credit_tzs'] <= $threshold) {
            self::notifyMeterOwners($meterId, 'Low credit warning', sprintf(
                'Your estimated prepaid credit is TZS %s (below the %s threshold). Consider recording a new token purchase.',
                number_format($credit['estimated_credit_tzs']), number_format($threshold)
            ));
        }
    }
}
