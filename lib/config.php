<?php

function required_env(string $key): string {
  $value = $_ENV[$key] ?? getenv($key);
  if ($value === false || $value === null || trim((string)$value) === '') {
    throw new RuntimeException("Missing required environment variable: {$key}");
  }
  return (string)$value;
}

function env_or_default(string $key, string $default): string {
  $value = $_ENV[$key] ?? getenv($key);
  if ($value === false || $value === null || trim((string)$value) === '') {
    return $default;
  }
  return (string)$value;
}

const MERCADO_PREDITIVO_USER_ID = 999999;
const MERCADO_PREDITIVO_USER_NAME = 'Mercado Preditivo';
const MERCADO_PREDITIVO_USER_EMAIL = 'mercado.preditivo@piscina.local';
define('MERCADO_PREDITIVO_USER_PASSWORD', env_or_default('MERCADO_PREDITIVO_USER_PASSWORD', 'CHANGE_ME_ENV_PASSWORD'));
