<?php
require_once 'db.php';

try {
    // Fetch all vocabulary records from the database
    $stmt = $pdo->query("SELECT * FROM meine_wortschatz ORDER BY Wort ASC");
    $words = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set header to output clean JSON
    header('Content-Type: application/json; charset=utf-8');
    
    // Optional: If you want the browser/tool to download it directly as a file, uncomment the line below:
    // header('Content-Disposition: attachment; filename="meine_wortschatz_export.json"');

    echo json_encode([
        'status' => 'success',
        'total_records' => count($words),
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $words
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'status' => 'error',
        'message' => 'Database query failed: ' . $e->getMessage()
    ]);
}