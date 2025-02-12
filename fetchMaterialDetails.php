<?php
include_once('../../../include/php/connect.php');  // Ensure this file creates a $conn variable
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $materialName = $_POST['materialName'] ?? '';
    $fromDate = $_POST['fromDate'] ?? '';
    $toDate = $_POST['toDate'] ?? '';

    if (!$materialName || !$fromDate || !$toDate) {
        echo json_encode(['error' => 'Invalid parameters.']);
        exit;
    }
    
    // 1. Get all rows from mat_stocks between fromDate and toDate.
    $query = "SELECT stk_date, mat_stock_ary 
              FROM mat_stocks 
              WHERE stk_date BETWEEN ? AND ? 
              ORDER BY stk_date ASC";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['error' => 'Database error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('ss', $fromDate, $toDate);
    $stmt->execute();
    $result = $stmt->get_result();

    // Initialize an associative array to hold monthly totals.
    $monthlyAggregates = [];
    while ($row = $result->fetch_assoc()) {
        $stkDate = $row['stk_date']; // Expected format: YYYY-MM-DD
        $month = date('Y-m', strtotime($stkDate));
        // Initialize for this month if not already set.
        if (!isset($monthlyAggregates[$month])) {
            $monthlyAggregates[$month] = [
                'totalPQ' => 0,
                'totalPR' => 0,
                'totalCO' => 0
            ];
        }
        // Decode the JSON array from the current row.
        $stockArray = json_decode($row['mat_stock_ary'], true);
        if (is_array($stockArray)) {
            foreach ($stockArray as $stockItem) {
                // Check if the material name matches.
                if (isset($stockItem['mn']) && $stockItem['mn'] == $materialName) {
                    $monthlyAggregates[$month]['totalPQ'] += isset($stockItem['pq']) ? $stockItem['pq'] : 0;
                    $monthlyAggregates[$month]['totalPR'] += isset($stockItem['pr']) ? $stockItem['pr'] : 0;
                    $monthlyAggregates[$month]['totalCO'] += isset($stockItem['co']) ? $stockItem['co'] : 0;
                }
            }
        }
    }
    $stmt->close();

    // 2. Build the monthly data array.
    $monthlyData = [];
    foreach ($monthlyAggregates as $month => $values) {
        // Net inwards = total purchase quantity minus total purchase returns.
        $inwardQty = $values['totalPQ'] - $values['totalPR'];
        // Outwards quantity is the total consumption.
        $outwardQty = $values['totalCO'];

        // Determine the first and last day of the month.
        $monthStart = $month . '-01';
        $monthEnd = date("Y-m-t", strtotime($monthStart));

        // 2.a. Get the average perUnit cost from mat_pur_item for this month.
        $purQuery = "SELECT AVG(perUnit) as avgPerUnit 
                     FROM mat_pur_item 
                     WHERE mat_pur_item_matname = ? 
                       AND mat_pur_date BETWEEN ? AND ?";
        $purStmt = $conn->prepare($purQuery);
        if (!$purStmt) {
            echo json_encode(['error' => 'Database error: ' . $conn->error]);
            exit;
        }
        $purStmt->bind_param('sss', $materialName, $monthStart, $monthEnd);
        $purStmt->execute();
        $purResult = $purStmt->get_result();
        $avgPerUnit = 0;
        if ($purRow = $purResult->fetch_assoc()) {
            $avgPerUnit = $purRow['avgPerUnit'] ? $purRow['avgPerUnit'] : 0;
        }
        $purStmt->close();
        $inwardValue = $inwardQty * $avgPerUnit;
        
        // 2.b. Get the average selling price from mat_selling_price.
        $sellingQuery = "SELECT proposed_rate_ary 
                         FROM mat_selling_price 
                         WHERE materialName = ?";
        $sellStmt = $conn->prepare($sellingQuery);
        if (!$sellStmt) {
            echo json_encode(['error' => 'Database error: ' . $conn->error]);
            exit;
        }
        $sellStmt->bind_param('s', $materialName);
        $sellStmt->execute();
        $sellResult = $sellStmt->get_result();
        $avgSellingPrice = 0;
        if ($sellRow = $sellResult->fetch_assoc()) {
            $proposedRates = json_decode($sellRow['proposed_rate_ary'], true);
            if (is_array($proposedRates) && count($proposedRates) > 0) {
                $spValues = [];
                // For each rate entry, if its date falls in the current month, add it.
                foreach ($proposedRates as $entry) {
                    if (isset($entry['dt'], $entry['sp'])) {
                        // Compare the first 7 characters (YYYY-MM) of dt with the current month.
                        if (substr($entry['dt'], 0, 7) === $month) {
                            $spValues[] = $entry['sp'];
                        }
                    }
                }
                if (count($spValues) > 0) {
                    $avgSellingPrice = array_sum($spValues) / count($spValues);
                }
            }
        }
        $sellStmt->close();
        $outwardValue = $outwardQty * $avgSellingPrice;
        
        // Append the computed data for this month.
        $monthlyData[] = [
            'month'         => $month,
            'inwardQty'     => $inwardQty,
            'inwardValue'   => round($inwardValue, 2),
            'outwardQty'    => $outwardQty,
            'outwardValue'  => round($outwardValue, 2)
        ];
    }
    
    echo json_encode(['mat_stocks_ary' => $monthlyData]);
} else {
    echo json_encode(['error' => 'Invalid request method.']);
}
?>
