<?php

declare(strict_types=1);

function lmsr_cost(int $qSim, int $qNao, float $b): float {
  if ($b <= 0) {
    return 0.0;
  }

  $a = $qSim / $b;
  $d = $qNao / $b;
  $m = max($a, $d);
  $sum = exp($a - $m) + exp($d - $m);
  return $b * ($m + log($sum));
}

function lmsr_price_sim(int $qSim, int $qNao, float $b): float {
  if ($b <= 0) {
    return 0.5;
  }

  $a = $qSim / $b;
  $d = $qNao / $b;
  $m = max($a, $d);
  $expSim = exp($a - $m);
  $expNao = exp($d - $m);
  $den = $expSim + $expNao;
  if ($den <= 0) {
    return 0.5;
  }
  return $expSim / $den;
}

function lmsr_delta_cost(int $qSim, int $qNao, float $b, string $side, int $shares): float {
  $shares = max(0, $shares);
  $qSimNew = $qSim;
  $qNaoNew = $qNao;

  if ($side === 'SIM') {
    $qSimNew += $shares;
  } else {
    $qNaoNew += $shares;
  }

  $costBefore = lmsr_cost($qSim, $qNao, $b);
  $costAfter = lmsr_cost($qSimNew, $qNaoNew, $b);

  return $costAfter - $costBefore;
}
