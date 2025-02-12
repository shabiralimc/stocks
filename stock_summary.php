<?php
include_once('../../../include/php/connect.php');
ini_set('session.gc_maxlifetime', 43200);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user']) || $_SESSION['role'] !== '4') {
    echo "<script>alert('You are not authorised to view the URL - Please login using your username and password before accessing URL...'); window.location = '$app_url';</script>";
    exit();
}

// Calculate the remaining time
$sessionStart = $_SESSION['session_start'];
$sessionLifetime = $_SESSION['session_lifetime'];
$currentTime = time();
$remainingTime = ($sessionStart + $sessionLifetime) - $currentTime;


// Fetch godowns from master_godowns table
$query = "SELECT DISTINCT godownName FROM master_godowns";
$result = $conn->query($query);

// Initialize an array to hold the godown names
$godowns = [];

// Fetch all godown names
while ($row = $result->fetch_assoc()) {
    $godowns[] = $row['godownName'];
}
?>

<!-- Include Header File -->
<?php include_once ('../../../include/php/header.php') ?>

<!-- Include Sidebar File -->
<?php include_once ('../../../include/php/sidebar-fab.php') ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h3 class="m-0">STOCK SUMMARY</h3>
          </div>
        </div>
        <!-- /.row -->
      </div>
      <!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->
    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">

      <div class="card card-info card-outline">
    <div class="card-header">
        <div class="col-md-8 offset-md-2">
            <form id="dateForm">
                <div class="d-flex justify-content-between mb-2">
                    <label for="fromDate" class="form-label">From <span style="color:red;">*</span>:</label>
                    <label for="toDate" class="form-label">To <span style="color:red;">*</span> :</label>
                    <label for="godown" class="form-label">Godown <span style="color:red;">*</span> :</label>
                </div>
                <div class="d-flex justify-content-between">
                    <input type="date" id="fromDate" class="form-control form-control-lg me-2" required>
                    <input type="date" id="toDate" class="form-control form-control-lg me-2" required>
                    <select id="godown" class="form-control form-control-lg" required>
                        <option value="" selected disabled>Select Godown</option>
                        <?php foreach ($godowns as $godown) {
                            echo "<option value='" . $godown . "'>" . $godown . "</option>";
                        } ?>
                    </select>
                </div>
                <div class="text-end mt-3">
                    <button type="button" id="search-button" class="btn btn-lg btn-default">
                        <i class="fa fa-search"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div class="card-body" id="table-container" style="display: none;">
        <table id="report-summary" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Material Name</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Unit</th>
                    <th>Rate</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody id="stock-summary-body">
            </tbody>
        </table>
    </div>
</div>

</section>
</div>

<!-- Include SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Function to clear the table
function clearTable() {
    const stockSummaryBody = document.getElementById('stock-summary-body');
    stockSummaryBody.innerHTML = '';  // Clear the table content
    document.getElementById('table-container').style.display = 'none';  // Hide the table
}

// Add event listeners for fromDate and toDate to clear the table on change
document.getElementById('fromDate').addEventListener('input', clearTable);
document.getElementById('toDate').addEventListener('input', clearTable);

document.getElementById('search-button').addEventListener('click', function () {
    const fromDate = document.getElementById('fromDate').value;
    const toDate = document.getElementById('toDate').value;
    const godown = document.getElementById('godown').value;

    // Check if all fields are filled
    if (fromDate && toDate && godown) {
        document.getElementById('table-container').style.display = 'block';
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Information',
            text: 'All fields are required.'
        });
        document.getElementById('table-container').style.display = 'none';
        return;
    }

    // Fetch main stock report data (your existing code)
    fetch('fetchStocks.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `fromDate=${fromDate}&toDate=${toDate}&godown=${encodeURIComponent(godown)}`
    })
    .then(response => response.json())
    .then(data => {
        const stockSummaryBody = document.getElementById('stock-summary-body');
        stockSummaryBody.innerHTML = '';  // Clear previous results

        if (data.materials) {
            data.materials.forEach(item => {
                if (item.gd === godown) {
                    const row = document.createElement('tr');

                    // Material Name cell (clickable)
                    const materialNameCell = document.createElement('td');
                    const materialLink = document.createElement('a');
                    materialLink.textContent = item.mn; 
                    materialLink.href = '#';
                    materialLink.style.textDecoration = 'none';
                    materialLink.style.color = 'black';
                    materialLink.addEventListener('click', function (event) {
                        event.preventDefault();
                        openMaterialDetailsTab(item.mn, fromDate, toDate);
                    });
                    materialNameCell.appendChild(materialLink);
                    row.appendChild(materialNameCell);

                    // Other cells (category, quantity, unit, rate, value)
                    const categoryCell = document.createElement('td');
                    categoryCell.textContent = item.materialCategory || 'Unknown';
                    row.appendChild(categoryCell);
 
                    const quantityCell = document.createElement('td');
                    quantityCell.textContent = item.currentStock || 0;
                    row.appendChild(quantityCell);

                    const unitCell = document.createElement('td');
                    unitCell.textContent = item.materialUnit || 'Unknown';
                    row.appendChild(unitCell);

                    const rateCell = document.createElement('td');
                    rateCell.textContent = item.rate || 'Unknown';
                    row.appendChild(rateCell);

                    const valueCell = document.createElement('td');
                    const value = item.currentStock * item.rate;
                    valueCell.textContent = value.toFixed(2);
                    row.appendChild(valueCell);

                    stockSummaryBody.appendChild(row);
                }
            });
        } else {
            Swal.fire('Error', data.error, 'error');
        }
    })
    .catch(error => {
        Swal.fire('Error', 'An unexpected error occurred.', 'error');
        console.error(error);
    });
});

// Helper function to generate an array of months between two dates (format "YYYY-MM")
function getMonthsBetweenDates(start, end) {
    let startDate = new Date(start);
    let endDate = new Date(end);
    let months = [];
    while (startDate <= endDate) {
        let month = startDate.getFullYear() + '-' + ('0' + (startDate.getMonth() + 1)).slice(-2);
        months.push(month);
        startDate.setMonth(startDate.getMonth() + 1);
    }
    return months;
}

// Function to open material details in a new tab with monthly and daily details
function openMaterialDetailsTab(materialName, fromDate, toDate) {
    // Get months between the dates (for the monthly table)
    const months = getMonthsBetweenDates(fromDate, toDate);
    
    // Fetch monthly data from your existing endpoint
    fetch('fetchMaterialDetails.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `materialName=${encodeURIComponent(materialName)}&fromDate=${fromDate}&toDate=${toDate}`
    })
    .then(response => response.json())
    .then(data => {
        const monthlyData = data.mat_stocks_ary || [];
        const newTab = window.open('', '_blank');
        
        if (newTab) {
            let tabContent = `
                <html>
                <head>
                    <title>Material Details - ${materialName}</title>
                    <style>
                        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        a { text-decoration: none; color: blue; cursor: pointer; }
                    </style>
                </head>
                <body>
                    <h1>Material Details for ${materialName}</h1>
                    <h2>Monthly Summary</h2>
                    <table id="monthlyTable">
                        <thead>
                            <tr>
                                <th>Months</th>
                                <th>Inward Quantity</th>
                                <th>Inward Value</th>
                                <th>Outward Quantity</th>
                                <th>Outward Value</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            // Grand totals for monthly summary
            let grandInwardQty = 0, grandInwardValue = 0, grandOutwardQty = 0, grandOutwardValue = 0;
            
            months.forEach(month => {
                // Look up the monthly data (if available)
                const monthData = monthlyData.find(item => item.month === month);
                let inwardQty = 0, inwardValue = 0, outwardQty = 0, outwardValue = 0;
                if (monthData) {
                    inwardQty = monthData.inwardQty;
                    inwardValue = monthData.inwardValue;
                    outwardQty = monthData.outwardQty;
                    outwardValue = monthData.outwardValue;
                }
                grandInwardQty += Number(inwardQty);
                grandInwardValue += Number(inwardValue);
                grandOutwardQty += Number(outwardQty);
                grandOutwardValue += Number(outwardValue);
                
                tabContent += `
                    <tr>
                        <td><a onclick="loadDailyDetails('${materialName}', '${month}');"style="color: black">${month}</a></td>
                        <td>${inwardQty}</td>
                        <td>${Number(inwardValue).toFixed(2)}</td>
                        <td>${outwardQty}</td>
                        <td>${Number(outwardValue).toFixed(2)}</td>
                    </tr>
                `;
            });
            
            // Append the grand total row
            tabContent += `
                    <tr>
                        <td><strong>Grand Total</strong></td>
                        <td><strong>${grandInwardQty}</strong></td>
                        <td><strong>${Number(grandInwardValue).toFixed(2)}</strong></td>
                        <td><strong>${grandOutwardQty}</strong></td>
                        <td><strong>${Number(grandOutwardValue).toFixed(2)}</strong></td>
                    </tr>
                    </tbody>
                    </table>
                    
                    <!-- Placeholder for daily details -->
                    <div id="dailyDetails"></div>
                    
                    <script>
                        // This function is defined in the new tab to load daily breakdown details
                        function loadDailyDetails(materialName, month) {
                            const url = 'fetchMaterialDailyDetails.php?materialName=' + encodeURIComponent(materialName) + '&month=' + encodeURIComponent(month);
                            fetch(url)
                                .then(response => response.json())
                                .then(data => {
                                    const dailyData = data.daily_details || [];
                                    let dailyTable = '<h2>Daily Details for ' + month + '</h2>';
                                    dailyTable += '<table><thead><tr><th>Date</th><th>Inward Quantity</th><th>Inward Value</th><th>Outward Quantity</th><th>Outward Value</th></tr></thead><tbody>';
                                    
                                    // Accumulators for daily totals
                                    let totalInwardQty = 0, totalInwardValue = 0, totalOutwardQty = 0, totalOutwardValue = 0;
                                    
                                    dailyData.forEach(row => {
                                        totalInwardQty += Number(row.inwardQty);
                                        totalInwardValue += Number(row.inwardValue);
                                        totalOutwardQty += Number(row.outwardQty);
                                        totalOutwardValue += Number(row.outwardValue);
                                        
                                        dailyTable += '<tr>';
                                        dailyTable += '<td>' + row.stk_date + '</td>';
                                        dailyTable += '<td>' + row.inwardQty + '</td>';
                                        dailyTable += '<td>' + Number(row.inwardValue).toFixed(2) + '</td>';
                                        dailyTable += '<td>' + row.outwardQty + '</td>';
                                        dailyTable += '<td>' + Number(row.outwardValue).toFixed(2) + '</td>';
                                        dailyTable += '</tr>';
                                    });
                                    
                                    dailyTable += '<tr>';
                                    dailyTable += '<td><strong>Daily Grand Total</strong></td>';
                                    dailyTable += '<td><strong>' + totalInwardQty + '</strong></td>';
                                    dailyTable += '<td><strong>' + Number(totalInwardValue).toFixed(2) + '</strong></td>';
                                    dailyTable += '<td><strong>' + totalOutwardQty + '</strong></td>';
                                    dailyTable += '<td><strong>' + Number(totalOutwardValue).toFixed(2) + '</strong></td>';
                                    dailyTable += '</tr>';
                                    
                                    dailyTable += '</tbody></table>';
                                    
                                    document.getElementById('dailyDetails').innerHTML = dailyTable;
                                })
                                .catch(error => {
                                    alert('Error loading daily details');
                                    console.error(error);
                                });
                        }
                    <\/script>
                </body>
                </html>
            `;
            
            newTab.document.write(tabContent);
            newTab.document.close();
        } else {
            Swal.fire('Error', 'Unable to open a new tab. Please check your browser settings.', 'error');
        }
    })
    .catch(error => {
        Swal.fire('Error', 'An error occurred fetching material details.', 'error');
        console.error(error);
    });
}
</script>

<script>
$(document).ready(function() {
    $('#report-summary').DataTable();
});
</script>

<!-- Include Footer File -->
<?php include_once ('../../../include/php/footer.php') ?>
