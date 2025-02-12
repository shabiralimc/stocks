<?php
header('Content-Type: application/json');
include_once('../../../include/php/connect.php');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromDate = $_POST['fromDate'];
    $toDate   = $_POST['toDate'];
    $godown   = $_POST['godown'];

    if (!empty($fromDate) && !empty($toDate) && !empty($godown)) {

        // 1. Get all material names that have any transaction in the period (fromDate to toDate)
        //    in the selected godown.
        $query = "SELECT mat_stock_ary FROM mat_stocks WHERE stk_date BETWEEN ? AND ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $fromDate, $toDate);
        $stmt->execute();
        $result = $stmt->get_result();

        $materialNames = [];
        while ($row = $result->fetch_assoc()) {
            $matStockArray = json_decode($row['mat_stock_ary'], true);
            if (is_array($matStockArray)) {
                foreach ($matStockArray as $item) {
                    if (isset($item['gd'], $item['mn']) && $item['gd'] === $godown) {
                        $materialNames[] = $item['mn'];
                    }
                }
            }
        }
        $materialNames = array_unique($materialNames);

        // 2. Get material master details including the openingstock_ary JSON field.
        //    We extract the opening stock ("ops") and from‑os date ("fos") for the selected godown.
        $materialsQuery = "SELECT materialName, openingstock_ary, materialCategory, materialUnit FROM material_master_creates";
        $materialsResult = $conn->query($materialsQuery);
        $materials = [];
        while ($row = $materialsResult->fetch_assoc()) {
            $openingstockAry = json_decode($row['openingstock_ary'], true);
            $openingStock = 0;  // default opening stock if not found
            $from_os = '';      // default from‑os date
            if (is_array($openingstockAry)) {
                foreach ($openingstockAry as $entry) {
                    if (isset($entry['gd']) && $entry['gd'] === $godown) {
                        $openingStock = $entry['ops'];
                        $from_os = $entry['fos'];
                        break;
                    }
                }
            }
            $materials[$row['materialName']] = [
                'openingStock'     => $openingStock,
                'from_os'          => $from_os,
                'materialCategory' => $row['materialCategory'],
                'materialUnit'     => $row['materialUnit'],
            ];
        }

        // Prepare arrays to hold transaction totals for each material.
        $closingData = [];  // for transactions from fos up to fromDate
        $afterData   = [];  // for transactions from next day after fromDate to toDate

        foreach ($materialNames as $matName) {
            $closingData[$matName] = [
                'totalPurchase'    => 0,
                'totalConsumption' => 0,
                'totalReturn'      => 0
            ];
            $afterData[$matName] = [
                'totalPurchase'    => 0,
                'totalConsumption' => 0,
                'totalReturn'      => 0
            ];
        }

        // 3. Get cumulative transactions (pq, co, pr) from mat_stocks _up to_ fromDate.
        //    Only include rows on or after the material’s fos (if defined).
        $stockQuery = "SELECT stk_date, mat_stock_ary FROM mat_stocks WHERE stk_date <= ?";
        $stockStmt = $conn->prepare($stockQuery);
        $stockStmt->bind_param("s", $fromDate);
        $stockStmt->execute();
        $stockResult = $stockStmt->get_result();

        while ($stockRow = $stockResult->fetch_assoc()) {
            $stkDate = $stockRow['stk_date'];
            $matStockArray = json_decode($stockRow['mat_stock_ary'], true);
            if (is_array($matStockArray)) {
                foreach ($matStockArray as $stock) {
                    if (isset($stock['mn'], $stock['gd']) && $stock['gd'] === $godown) {
                        $materialName = $stock['mn'];
                        // Only process if this material is among those we found
                        if (!in_array($materialName, $materialNames)) {
                            continue;
                        }
                        // Get the fos value for this material from material master
                        $from_os = isset($materials[$materialName]['from_os']) ? $materials[$materialName]['from_os'] : '';
                        // Only include transactions if the record date is on or after fos.
                        if ($from_os && $stkDate < $from_os) {
                            continue;
                        }
                        $closingData[$materialName]['totalPurchase']    += $stock['pq'] ?? 0;
                        $closingData[$materialName]['totalConsumption'] += $stock['co'] ?? 0;
                        $closingData[$materialName]['totalReturn']      += $stock['pr'] ?? 0;
                    }
                }
            }
        }

        // 4. Calculate the closing stock (as of fromDate) for each material:
        //    closingStock = openingStock + (totalPurchase - totalConsumption - totalReturn) in [fos, fromDate].
        $finalData = [];
        foreach ($materialNames as $materialName) {
            // Skip if there is no material master record
            if (!isset($materials[$materialName])) {
                continue;
            }
            $openingStock = $materials[$materialName]['openingStock'];
            $totalPurchaseTillFrom    = $closingData[$materialName]['totalPurchase'];
            $totalConsumptionTillFrom = $closingData[$materialName]['totalConsumption'];
            $totalReturnTillFrom      = $closingData[$materialName]['totalReturn'];

            $closingStock = $openingStock + $totalPurchaseTillFrom - $totalConsumptionTillFrom - $totalReturnTillFrom;

            // 5. Now get transactions for the period _after_ fromDate.
            //    We start from the next day after fromDate.
            $nextDate = date('Y-m-d', strtotime($fromDate . ' +1 day'));
            $stockQuery2 = "SELECT mat_stock_ary FROM mat_stocks WHERE stk_date BETWEEN ? AND ?";
            $stockStmt2 = $conn->prepare($stockQuery2);
            $stockStmt2->bind_param("ss", $nextDate, $toDate);
            $stockStmt2->execute();
            $stockResult2 = $stockStmt2->get_result();

            while ($stockRow = $stockResult2->fetch_assoc()) {
                $matStockArray = json_decode($stockRow['mat_stock_ary'], true);
                if (is_array($matStockArray)) {
                    foreach ($matStockArray as $stock) {
                        if (isset($stock['mn'], $stock['gd']) && $stock['gd'] === $godown) {
                            if ($stock['mn'] === $materialName) {
                                $afterData[$materialName]['totalPurchase']    += $stock['pq'] ?? 0;
                                $afterData[$materialName]['totalConsumption'] += $stock['co'] ?? 0;
                                $afterData[$materialName]['totalReturn']      += $stock['pr'] ?? 0;
                            }
                        }
                    }
                }
            }
            $totalPurchaseAfterFrom    = $afterData[$materialName]['totalPurchase'];
            $totalConsumptionAfterFrom = $afterData[$materialName]['totalConsumption'];
            $totalReturnAfterFrom      = $afterData[$materialName]['totalReturn'];

            // currentStock = closingStock + (net transactions from nextDate to toDate)
            $currentStock = $closingStock + $totalPurchaseAfterFrom - $totalConsumptionAfterFrom - $totalReturnAfterFrom;

            // 6. (Optional) Calculate average per‑unit rate from purchase items between nextDate and toDate.
            $perUnitValues = [];
            $purQuery = "SELECT perUnit FROM mat_pur_item WHERE mat_pur_date BETWEEN ? AND ? AND mat_pur_item_matname = ?";
            $purStmt = $conn->prepare($purQuery);
            $purStmt->bind_param("sss", $fromDate, $toDate, $materialName);
            $purStmt->execute();
            $resultPerUnit = $purStmt->get_result();
            while ($row = $resultPerUnit->fetch_assoc()) {
                $perUnitValues[] = $row['perUnit'];
            }
            $averagePerUnit = 0;
            if (count($perUnitValues) > 0) {
                $averagePerUnit = array_sum($perUnitValues) / count($perUnitValues);
            }

            // Prepare the final data for this material
            $finalData[] = [
                'mn'               => $materialName,
                'gd'               => $godown,
                'openingStock'     => $openingStock,
                'closingStock'     => $closingStock,
                'currentStock'     => $currentStock,
                'materialCategory' => $materials[$materialName]['materialCategory'] ?: 'Unknown',
                'materialUnit'     => $materials[$materialName]['materialUnit'] ?: 'Unknown',
                'rate'             => $averagePerUnit
            ];
        }

        echo json_encode(['materials' => $finalData]);
    } else {
        echo json_encode(['error' => 'Invalid input parameters.']);
    }
} else {
    echo json_encode(['error' => 'Invalid request method.']);
}
?>
