<?php

declare(strict_types=1);

function lmsr_cost(float $b, float $qYes, float $qNo): float {
  if ($b <= 0) {
    return 0.0;
  }
  $a = $qYes / $b;
  $c = $qNo / $b;
  $m = max($a, $c);
  $sum = exp($a - $m) + exp($c - $m);
  return $b * ($m + log($sum));
}

function lmsr_price_yes(float $b, float $qYes, float $qNo): float {
  if ($b <= 0) {
    return 0.5;
  }
  $a = $qYes / $b;
  $c = $qNo / $b;
  $m = max($a, $c);
  $expYes = exp($a - $m);
  $expNo = exp($c - $m);
  $den = $expYes + $expNo;
  if ($den <= 0) {
    return 0.5;
  }
  return $expYes / $den;
}

function lmsr_quote(float $b, float $qYes, float $qNo, string $outcome, string $side, float $shares): array {
  $shares = max(0.0, $shares);
  $priceYesBefore = lmsr_price_yes($b, $qYes, $qNo);
  $qYesNew = $qYes;
  $qNoNew = $qNo;

  if ($outcome === 'yes') {
    $qYesNew = $side === 'buy' ? $qYes + $shares : $qYes - $shares;
  } else {
    $qNoNew = $side === 'buy' ? $qNo + $shares : $qNo - $shares;
  }

  $costBefore = lmsr_cost($b, $qYes, $qNo);
  $costAfter = lmsr_cost($b, $qYesNew, $qNoNew);

  $cashDelta = 0.0;
  if ($side === 'buy') {
    $cashDelta = -($costAfter - $costBefore);
  } else {
    $cashDelta = $costBefore - $costAfter;
  }

  $priceYesAfter = lmsr_price_yes($b, $qYesNew, $qNoNew);

  return [
    'cash_delta_brl' => round($cashDelta, 8),
    'price_yes_before' => round($priceYesBefore, 10),
    'price_yes_after' => round($priceYesAfter, 10),
    'q_yes_new' => $qYesNew,
    'q_no_new' => $qNoNew,
  ];
}
