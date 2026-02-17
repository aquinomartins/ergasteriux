<?php
// includes/db.php
// Conexão compartilhada com o banco do app principal.

declare(strict_types=1);

require_once __DIR__ . '/../../lib/db.php';

$pdo = db();