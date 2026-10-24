<?php
declare(strict_types=1);

// Authentication-aware routing is added in Phase 2.
require __DIR__ . '/config/db.php';

http_response_code(503);
echo 'KapetBahay setup is ready. Complete Phase 2 to enable sign-in.';
