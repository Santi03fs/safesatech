<?php
/**
 * Endpoint público GET que devuelve las fotos/proyectos guardados en MySQL.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';

try {
    $pdo = get_pdo();
    if (!$pdo instanceof PDO) {
        echo json_encode(['ok' => true, 'items' => []]);
        exit;
    }

    $stmt = $pdo->query('
        SELECT id, title, description, tags, image_path, created_at
        FROM portfolio_items
        ORDER BY display_order ASC, id DESC
    ');
    $items = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['ok' => true, 'items' => []]);
}
