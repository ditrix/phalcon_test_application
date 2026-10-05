<?php

namespace App\Services;

class ImportRepository
{
    public function replaceRequests($db)
    {
        $db->execute('TRUNCATE TABLE requests');
    }

    public function getRows($db, $page, $limit = 50)
    {
        $page = max(1, (int) $page);
        $limit = max(1, (int) $limit);
        $count = $db->fetchOne('SELECT COUNT(*) AS total FROM requests', \Phalcon\Db::FETCH_ASSOC);
        $total = (int) ($count['total'] ?? 0);
        $maxPage = max(1, (int) ceil($total / $limit));
        $page = min($page, $maxPage);
        $offset = ($page - 1) * $limit;

        $rows = $db->fetchAll(
            'SELECT id, external_id, created_at, first_name, last_name, phone, email, warning FROM requests ORDER BY id LIMIT ' . $offset . ', ' . $limit,
            \Phalcon\Db::FETCH_ASSOC
        );

        return array(
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        );
    }
}
