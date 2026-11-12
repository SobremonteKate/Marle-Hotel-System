<?php
    include "../dbconnect.php";
    include "../admin/auth.php";
    include "../csrf.php";
    require_csrf_token();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }

    $id = intval($_POST['id'] ?? 0);
    $conn->query("DELETE FROM calendartbl WHERE id = $id");
?>