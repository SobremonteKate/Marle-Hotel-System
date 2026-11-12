<?php
// Keep the Groq key in the server environment (or the GROQ_API_KEY constant
// defined in config.php for hosts like InfinityFree without settable env vars),
// never hardcoded in this file.
$apiKey = getenv('GROQ_API_KEY');
$apiKey = is_string($apiKey) && $apiKey !== '' ? trim($apiKey) : (defined('GROQ_API_KEY') ? (string)GROQ_API_KEY : '');
$apiKey = trim($apiKey);




