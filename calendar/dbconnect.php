<?php

require_once __DIR__ . '/../config.php';

// Supplies the pin helper when config.php predates the clock fix — without this,
// the marle_db_pin_time_zone() call below would be a fatal on such a host, not
// merely a wrong time. See includes/db_clock.php.
require_once __DIR__ . '/../includes/db_clock.php';

$db_server = MARLE_DB_HOST;
$db_user = MARLE_DB_USER;
$db_password = MARLE_DB_PASS;
$db_name = MARLE_DB_NAME;
$conn = "";

$conn = mysqli_connect($db_server, $db_user, $db_password, $db_name);

// This file opens its own connection instead of going through
// marle_db_connect() (config.php), so it has to pin the hotel's clock too —
// otherwise calendar/ pages write and read MySQL's NOW() on the host's
// time zone while every other screen uses Asia/Manila.
if ($conn) {
    marle_db_pin_time_zone($conn);
}

if($conn){
    echo " ";
}else{
    echo "Could not connect.";
}

?>