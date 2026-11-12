<?php 
include('auth.php');
require_once __DIR__ . '/../includes/log_action.php';

// Owner-only: exporting the whole database (password hashes + audit trail) is
// not a manager capability. Managers lost the shortcut 2026-09-24; this guard
// keeps a direct request to the URL from working either.
require_capability('backup_database');

require_once __DIR__ . '/../config.php';

// The connection goes through the one shared helper, like every other screen.
// This file used to call mysqli_connect() itself, which made it the single staff
// endpoint an outage could not reach gracefully: a dead MySQL handed back `false`
// and the next line — mysqli_query(false, "SHOW TABLES") — was an uncaught
// TypeError, i.e. a 500 in the middle of a download that had already promised a
// file. It also hid from the outage drill, whose simulation patches the helper in
// config.php and therefore never touched this line (found 2026-10-16).
require_once __DIR__ . '/../includes/db_guard.php';

$database_name = MARLE_DB_NAME;

$conn = marle_db_connect();

// "Nothing was saved" is the honest answer for a backup: with no database there
// is nothing to export, and a staff member told nothing will assume the file they
// asked for was produced.
marle_db_guard($conn, 'page', 'staff');

log_event($conn, 'database_backup_downloaded', 'system', null, 'success', 'SQL dump export');

$tables = array();
$sql = "SHOW TABLES";
$result = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_row($result)) {
$tables[] = $row[0];
}

$sqlScript = "";
foreach ($tables as $table) { 
// Prepare SQLscript for creating table structure
$query = "SHOW CREATE TABLE $table";
$result = mysqli_query($conn, $query);
$row = mysqli_fetch_row($result);

$sqlScript .= "\n\n" . $row[1] . ";\n\n";

$query = "SELECT * FROM $table";
$result = mysqli_query($conn, $query);

$columnCount = mysqli_num_fields($result); 
// Prepare SQLscript for dumping data for each table
for ($i = 0; $i < $columnCount; $i ++) {
while ($row = mysqli_fetch_row($result)) {
$sqlScript .= "INSERT INTO $table VALUES(";
for ($j = 0; $j < $columnCount; $j ++) {
$row[$j] = $row[$j];

if (isset($row[$j])) {
$sqlScript .= '"' . $row[$j] . '"';
} else {
$sqlScript .= '""';
}
if ($j < ($columnCount - 1)) {
$sqlScript .= ',';
}
}
$sqlScript .= ");\n";
}
}
$sqlScript .= "\n"; 
}

if(!empty($sqlScript))
{
// Keep the temporary backup outside the document root.
$backup_file_name = tempnam(sys_get_temp_dir(), $database_name . '_backup_');
$fileHandler = fopen($backup_file_name, 'wb');
$number_of_lines = fwrite($fileHandler, $sqlScript);
fclose($fileHandler);

// Download the SQL backup file to the browser
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename=' . basename($backup_file_name));
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($backup_file_name));
ob_clean();
flush();
readfile($backup_file_name);
unlink($backup_file_name);
}
?>