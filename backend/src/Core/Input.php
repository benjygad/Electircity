<?php
declare(strict_types=1);

namespace Sems\Core;

/** Request input helpers. */
final class Input
{
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '[]', true);
        if (!is_array($data)) throw HttpException::badRequest('Request body must be valid JSON');
        return $data;
    }

    /** Serialize a DB row for JSON output: timestamps become ISO-8601 UTC. */
    public static function stamp(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $v)) {
                $row[$k] = str_replace(' ', 'T', substr($v, 0, 19)) . 'Z';
            }
        }
        return $row;
    }
}
