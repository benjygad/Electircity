<?php
declare(strict_types=1);

namespace Sems\Core;

/** Pagination helper: returns [limit, offset, page] from query params. */
function paginate(): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    return [$limit, ($page - 1) * $limit, $page];
}
