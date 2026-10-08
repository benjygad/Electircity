<?php
declare(strict_types=1);

namespace Sems\Core;

/** Append-only audit trail for administrative and security-relevant actions. */
final class Audit
{
    public static function log(?int $actorId, string $action, string $entityType, ?int $entityId = null, ?string $details = null): void
    {
        try {
            Db::insert('audit_logs', [
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details !== null ? mb_substr($details, 0, 500) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[SEMS][audit] ' . $e->getMessage());
        }
    }
}
