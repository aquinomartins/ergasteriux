<?php

declare(strict_types=1);

function market_read_json(): array {
  $payload = json_decode(file_get_contents('php://input'), true);
  return is_array($payload) ? $payload : [];
}

function market_read_id(): int {
  if (!empty($_GET['id'])) {
    return (int)$_GET['id'];
  }

  $path = $_SERVER['PATH_INFO'] ?? '';
  if ($path) {
    $parts = array_values(array_filter(explode('/', $path)));
    if (!empty($parts[0]) && is_numeric($parts[0])) {
      return (int)$parts[0];
    }
  }

  return 0;
}
