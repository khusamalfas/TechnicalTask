<?php
declare(strict_types=1);

final class PriceOptimizer
{
    /**
     * @param array<int, array{id:int, count:int, price:float, pack:int}> $offers
     * @param int $need    Exact number of units to purchase (N)
     * @return array<int, array{id:int, qty:int}>  Empty array if no solution
     */
    public function optimize(array $offers, int $need): array
    {
        if ($need <= 0) {
            return [];
        }

        if (!$this->isFeasibleByStock($offers, $need)) {
            return [];
        }

        $pseudoItems = $this->buildPseudoItems($offers, $need);
        if (empty($pseudoItems)) {
            return [];
        }

        [$dp, $choice] = $this->runKnapsack($pseudoItems, $need);

        // No way to get exactly $need units
        if (!is_finite($dp[$need])) {
            return [];
        }

        $qtyPerSupplier = $this->reconstructQuantities($pseudoItems, $choice, $need, count($offers));

        return $this->buildResult($offers, $qtyPerSupplier);
    }

    /**
     * Quick feasibility check: total stock >= need?
     *
     * @param array<int, array{id:int, count:int, price:float, pack:int}> $offers
     */
    private function isFeasibleByStock(array $offers, int $need): bool
    {
        $totalStock = 0;
        foreach ($offers as $offer) {
            $totalStock += $offer['count'];
        }
        return $totalStock >= $need;
    }

    /**
     * Build pseudo-items via binary splitting (bounded knapsack trick).
     *
     * @param array<int, array{id:int, count:int, price:float, pack:int}> $offers
     * @return array<int, array{supplierIndex:int, units:int, cost:float}>
     */
    private function buildPseudoItems(array $offers, int $need): array
    {
        $pseudoItems = [];

        foreach ($offers as $i => $offer) {
            $pack  = $offer['pack'];
            $count = $offer['count'];
            $price = $offer['price'];

            if ($pack <= 0 || $count <= 0 || $price <= 0.0) {
                continue;
            }

            $maxPacksByStock = intdiv($count, $pack);
            if ($maxPacksByStock <= 0) {
                continue;
            }

            $maxPacksByNeed = intdiv($need, $pack);
            $maxPacks = min($maxPacksByStock, $maxPacksByNeed);

            if ($maxPacks <= 0) {
                continue;
            }

            // Binary decomposition of maxPacks (1, 2, 4, 8, ...)
            $k = $maxPacks;
            $power = 1;

            while ($k > 0) {
                $chunk = min($power, $k); // how many packs in this pseudo-item
                $units = $chunk * $pack;
                $cost  = $units * $price;

                $pseudoItems[] = [
                    'supplierIndex' => $i,
                    'units'         => $units,
                    'cost'          => $cost,
                ];

                $k     -= $chunk;
                $power *= 2;
            }
        }

        return $pseudoItems;
    }

    /**
     * Run 0/1 knapsack DP over units [0..need].
     *
     * @param array<int, array{supplierIndex:int, units:int, cost:float}> $pseudoItems
     * @param int $need
     * @return array{0: array<int,float>, 1: array<int, ?array{prev:int, itemIndex:int}>}
     */
    private function runKnapsack(array $pseudoItems, int $need): array
    {
        $INF = INF;

        $dp = array_fill(0, $need + 1, $INF);
        $dp[0] = 0.0;

        /** @var array<int, ?array{prev:int, itemIndex:int}> $choice */
        $choice = array_fill(0, $need + 1, null);

        $numItems = count($pseudoItems);

        for ($j = 0; $j < $numItems; $j++) {
            $units = $pseudoItems[$j]['units'];
            $cost  = $pseudoItems[$j]['cost'];

            for ($q = $need; $q >= $units; $q--) {
                $prevQ = $q - $units;
                if (!is_finite($dp[$prevQ])) {
                    continue;
                }

                $newCost = $dp[$prevQ] + $cost;
                if ($newCost < $dp[$q]) {
                    $dp[$q] = $newCost;
                    $choice[$q] = [
                        'prev'      => $prevQ,
                        'itemIndex' => $j,
                    ];
                }
            }
        }

        return [$dp, $choice];
    }

    /**
     * Reconstruct total units per supplier from the choice array.
     *
     * @param array<int, array{supplierIndex:int, units:int, cost:float}> $pseudoItems
     * @param array<int, ?array{prev:int, itemIndex:int}> $choice
     * @return array<int,int>  qty per supplier index
     */
    private function reconstructQuantities(
        array $pseudoItems,
        array $choice,
        int $need,
        int $numOffers
    ): array {
        $qtyPerSupplier = array_fill(0, $numOffers, 0);

        $q = $need;
        while ($q > 0) {
            $step = $choice[$q] ?? null;
            if ($step === null) {
                // Should not happen if dp[need] is finite; if it does, treat as no solution.
                return array_fill(0, $numOffers, 0);
            }

            $itemIndex = $step['itemIndex'];
            $prevQ     = $step['prev'];

            $pseudo   = $pseudoItems[$itemIndex];
            $supplier = $pseudo['supplierIndex'];
            $units    = $pseudo['units'];

            $qtyPerSupplier[$supplier] += $units;
            $q = $prevQ;
        }

        return $qtyPerSupplier;
    }

    /**
     * Convert internal qty per supplier into final {id, qty} result.
     *
     * @param array<int, array{id:int, count:int, price:float, pack:int}> $offers
     * @param array<int,int> $qtyPerSupplier
     * @return array<int, array{id:int, qty:int}>
     */
    private function buildResult(array $offers, array $qtyPerSupplier): array
    {
        $result = [];

        foreach ($qtyPerSupplier as $i => $qty) {
            if ($qty <= 0) {
                continue;
            }

            // Extra safety: never exceed available stock
            if ($qty > $offers[$i]['count']) {
                // In a real project you might throw an exception here.
                $qty = $offers[$i]['count'];
            }

            $result[] = [
                'id'  => $offers[$i]['id'],
                'qty' => $qty,
            ];
        }

        return $result;
    }
}