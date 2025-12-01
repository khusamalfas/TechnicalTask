<?php

require_once 'PriceOptimizer.php';

$offers = [
    ['id' => 111, 'count' => 42,  'price' => 13.0, 'pack' => 1],
    ['id' => 222, 'count' => 77,  'price' => 11.0, 'pack' => 10],
    ['id' => 333, 'count' => 103, 'price' => 10.0, 'pack' => 50],
    ['id' => 444, 'count' => 65,  'price' => 12.0, 'pack' => 5],
];

$need = 76;

$optimizer = new PriceOptimizer();
$plan = $optimizer-> optimize($offers, $need);

var_dump($plan);


