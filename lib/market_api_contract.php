<?php

declare(strict_types=1);

function market_side_to_canonical(string $value): ?string {
  $normalized = strtolower(trim($value));
  if (in_array($normalized, ['sim', 'yes'], true)) {
    return 'yes';
  }
  if (in_array($normalized, ['nao', 'não', 'no'], true)) {
    return 'no';
  }
  return null;
}

function market_side_to_legacy(string $value): ?string {
  $canonical = market_side_to_canonical($value);
  if ($canonical === 'yes') {
    return 'SIM';
  }
  if ($canonical === 'no') {
    return 'NAO';
  }
  return null;
}

function market_emit_deprecation(string $legacyEndpoint, string $replacementEndpoint, string $sunset = '2026-12-31'): void {
  header('Deprecation: true');
  header(sprintf('Sunset: %sT23:59:59Z', $sunset));
  header(sprintf('Link: <%s>; rel="successor-version"', $replacementEndpoint));
  header(sprintf('Warning: 299 - "%s está em depreciação; migre para %s até %s"', $legacyEndpoint, $replacementEndpoint, $sunset));
}

function market_deprecation_payload(string $legacyEndpoint, string $replacementEndpoint, string $sunset = '2026-12-31'): array {
  return [
    'deprecated' => true,
    'legacy_endpoint' => $legacyEndpoint,
    'replacement_endpoint' => $replacementEndpoint,
    'sunset_date' => $sunset,
  ];
}

function market_contract_aliases(array $payload): array {
  $result = $payload;

  if (array_key_exists('p_sim', $payload)) {
    $result['price_yes'] = $payload['p_sim'];
  }
  if (array_key_exists('p_nao', $payload)) {
    $result['price_no'] = $payload['p_nao'];
  }

  if (array_key_exists('q_sim', $payload)) {
    $result['q_yes'] = $payload['q_sim'];
  }
  if (array_key_exists('q_nao', $payload)) {
    $result['q_no'] = $payload['q_nao'];
  }

  return $result;
}
