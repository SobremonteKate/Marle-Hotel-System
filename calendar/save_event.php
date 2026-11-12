<?php
    include "../dbconnect.php";
    include "../admin/auth.php";
    include "../csrf.php";
    require_csrf_token();

    $id = $_POST['id'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $date_from = $_POST['date_from'];
    $date_to = $_POST['date_to'];
    $time = $_POST['time'];
    $allowed_colors = ['#009416', '#933310', '#ffb60a'];
    $color = in_array($_POST['color'], $allowed_colors) ? $_POST['color'] : '#ffb60a';

    if (empty($date_from)) {
        die("Date From is required");
    }

    if (empty($date_to)) {
        $date_to = $date_from;
    }

    if (empty($time)) {
        $time = "00:00:00";
    } else {
        $time .= ":00";
    }

    $start = $date_from . " " . $time;
    $end   = $date_to . " " . $time;

    if ($id == "") {

        $stmt = $conn->prepare("INSERT INTO calendartbl 
            (title, description, start, end, color) 
            VALUES (?, ?, ?, ?, ?)");

        $stmt->bind_param("sssss", $title, $description, $start, $end, $color);
        $stmt->execute();

    } else {

        $stmt = $conn->prepare("UPDATE calendartbl 
            SET title=?, description=?, start=?, end=?, color=? 
            WHERE id=?");

        $stmt->bind_param("sssssi", $title, $description, $start, $end, $color, $id);
        $stmt->execute();
    }

    echo "success";
?>