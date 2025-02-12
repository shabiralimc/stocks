<?php
include_once('../../../include/php/connect.php');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Retrieve parameters
    $materialName = $_GET['materialName'] ?? '';
    $month = $_GET['month'] ?? '';

    if (!$materialName || !$month) {
        echo json_encode(['error' => 'Invalid parameters.']);
        exit;
    }
    
    // Query all rows in mat_stocks for the given month.
    // We select the stk_date and the JSON column mat_stock_ary.
    $query = "SELECT stk_date, mat_stock_ary 
              FROM mat_stocks
              WHERE DATE_FORMAT(stk_date, '%Y-%m') = ?
              ORDER BY stk_date ASC";
              
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['error' => 'Database error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('s', $month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Initialize an associative array to accumulate daily totals.
    $dailyData = array();
    while ($row = $result->fetch_assoc()) {
        $date = $row['stk_date']; // e.g. "2024-10-22"
        // Initialize accumulator for this date if not already set.
        if (!isset($dailyData[$date])) {
            $dailyData[$date] = array(
                'inwardQty' => 0,
                'outwardQty' => 0
            );
        }
        
        // Decode the JSON from mat_stock_ary.
            $matStockArray = json_decode($row['mat_stock_ary'], true);
            if (is_array($matStockArray)) {
                foreach ($matStockArray as $item) {
                    // Check if this record is for the given material.
                    if (isset($item['mn']) && $item['mn'] == $materialName) {
                        // Calculate the sum of pq and pr.
                        $pq = isset($item['pq']) ? $item['pq'] : 0;
                        $pr = isset($item['pr']) ? $item['pr'] : 0;
                        $dailyData[$date]['inwardQty'] += ($pq - $pr);
                        
                        // Accumulate the consumption (outward)
                        $dailyData[$date]['outwardQty'] += isset($item['co']) ? $item['co'] : 0;
                    }
                }
            }

    }
    $stmt->close();
    
    // Sort daily data by date
    ksort($dailyData);
    
    // Build the final output array.
    $finalData = array();
    foreach ($dailyData as $stk_date => $values) {
        $inwardQty = $values['inwardQty'];
        $outwardQty = $values['outwardQty'];
        
        // Get the average perUnit (for inward value) from mat_pur_item for this material on this date.
$avgPerUnit = 0;
$purQuery = "SELECT AVG(perUnit) as avgPerUnit 
             FROM mat_pur_item 
             WHERE mat_pur_item_matname = ? 
               AND mat_pur_date = ?";
$purStmt = $conn->prepare($purQuery);
if ($purStmt) {
    $purStmt->bind_param('ss', $materialName, $stk_date);
    $purStmt->execute();
    $purResult = $purStmt->get_result();
    if ($purRow = $purResult->fetch_assoc()) {
        // If no value exists, avgPerUnit will be null or 0.
        $avgPerUnit = $purRow['avgPerUnit'] ? $purRow['avgPerUnit'] : 0;
    }
    $purStmt->close();
}

// If no perUnit value was found for the exact date, then find the nearest perUnit value.
if (!$avgPerUnit) {
    $nearestQuery = "SELECT perUnit, ABS(DATEDIFF(mat_pur_date, ?)) as diff 
                     FROM mat_pur_item 
                     WHERE mat_pur_item_matname = ? 
                       AND perUnit IS NOT NULL 
                     ORDER BY diff ASC 
                     LIMIT 1";
    $nearestStmt = $conn->prepare($nearestQuery);
    if ($nearestStmt) {
        // Bind parameters: first the current stk_date for DATEDIFF, then the material name.
        $nearestStmt->bind_param('ss', $stk_date, $materialName);
        $nearestStmt->execute();
        $nearestResult = $nearestStmt->get_result();
        if ($nearestRow = $nearestResult->fetch_assoc()) {
            $avgPerUnit = $nearestRow['perUnit'];
        }
        $nearestStmt->close();
    }
}

$inwardValue = $inwardQty * $avgPerUnit;

        
        // For outward value, get the average selling price from mat_selling_price.
        $sellingQuery = "SELECT proposed_rate_ary 
        FROM mat_selling_price 
        WHERE materialName = ?";
        
        $sellStmt = $conn->prepare($sellingQuery);
        $avgSellingPrice = 0;
        if ($sellStmt) {
        $sellStmt->bind_param('s', $materialName);
        $sellStmt->execute();
        $sellResult = $sellStmt->get_result();
        if ($sellRow = $sellResult->fetch_assoc()) {
        $proposedRates = json_decode($sellRow['proposed_rate_ary'], true);
        if (is_array($proposedRates)) {
        // First, try to find exact date matches.
        $spValues = array();
        foreach ($proposedRates as $entry) {
        if (isset($entry['dt'], $entry['sp']) && $entry['dt'] === $stk_date) {
        $spValues[] = $entry['sp'];
        }
        }
        if (count($spValues) > 0) {
        $avgSellingPrice = array_sum($spValues) / count($spValues);
        } else {
        // No exact date found; use the nearest date.
        $nearestEntry = null;
        $minDiff = null;
        $currentDate = new DateTime($stk_date);
        foreach ($proposedRates as $entry) {
        if (isset($entry['dt'], $entry['sp'])) {
            $entryDate = new DateTime($entry['dt']);
            $diff = abs($entryDate->diff($currentDate)->days);
            if ($minDiff === null || $diff < $minDiff) {
                $minDiff = $diff;
                $nearestEntry = $entry;
            }
        }
        }
        if ($nearestEntry !== null) {
        $avgSellingPrice = $nearestEntry['sp'];
        }
        }
        }
        }
        $sellStmt->close();
        }
        $outwardValue = $outwardQty * $avgSellingPrice;

        
        $finalData[] = array(
            'stk_date'      => $stk_date,
            'inwardQty'     => $inwardQty,
            'inwardValue'   => round($inwardValue, 2),
            'outwardQty'    => $outwardQty,
            'outwardValue'  => round($outwardValue, 2)
        );
    }
    
    echo json_encode(['daily_details' => $finalData]);
} else {
    echo json_encode(['error' => 'Invalid request method.']);
}
?>
