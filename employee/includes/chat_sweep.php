<?php
function run_chat_sweep($conn): void {
    // See run_token_sweep(): an untyped parameter, because a dead connection
    // arrives here as `false` and a typed one would fatal the page instead of
    // letting its db_guard line speak for the system.
    if (!($conn instanceof mysqli)) {
        return;
    }

    $flag = $conn->query(
        "SELECT option_value FROM site_options WHERE option_key = 'last_chat_sweep' LIMIT 1"
    );
    if ($flag) {
        $row = $flag->fetch_assoc();
        if ($row && (time() - (int)$row['option_value']) < 300) return;
    }
    $conn->query(
        "UPDATE chat_messages
         SET    archived_at = NOW()
         WHERE  archived_at IS NULL
           AND  sent_at < NOW() - INTERVAL 1 DAY"
    );
    $conn->query(
        "INSERT INTO site_options (option_key, option_value)
         VALUES ('last_chat_sweep', UNIX_TIMESTAMP())
         ON DUPLICATE KEY UPDATE option_value = UNIX_TIMESTAMP()"
    );
}