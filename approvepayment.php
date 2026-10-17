<?php
require __DIR__.'/bootstrap.php';
require_admin();
header('Cache-Control: no-store');
if (isset($_GET['action'])) {
 header('Content-Type: application/json; charset=utf-8');
 if (in_array($_GET['action'], ['approve','reject'], true)) {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success'=>false,'message'=>'POST required'],405);
  csrf();
 }
}
/**
 * Determine the correct voucher table based on package type
 * @param string $package - Package name/type
 * @return string - Returns the appropriate database table name
 */
function getVoucherTable($package)
{
    $package = strtolower($package);
    if (strpos($package, 'week') !== false) return 'weekly_vouchers';
    if (strpos($package, 'month') !== false) return 'monthly_vouchers';
    return 'daily_vouchers';
}

/**
 * Format phone number to Uganda standard (+256)
 * @param string $phone - Raw phone number input
 * @return string - Formatted phone number with +256 prefix
 */
function formatPhoneNumber($phone)
{
    // Convert different phone number formats to +256 standard
    if (substr($phone, 0, 1) === '0') {
        return '+256' . substr($phone, 1);
    } elseif (substr($phone, 0, 3) === '256') {
        return '+' . $phone;
    } elseif (substr($phone, 0, 4) === '+256') {
        return $phone;
    }
    return '+256' . $phone;
}

/**
 * Convert package names to short profile codes
 * @param string $package - Full package name
 * @return string - Short profile code (1D, 1W, 1M)
 */
function getPackageProfile($package)
{
    $package = strtolower($package);
    if (strpos($package, 'week') !== false || strpos($package, '7') !== false) return '1W';
    if (strpos($package, 'month') !== false || strpos($package, '30') !== false) return '1M';
    return '1D'; // Default to daily
}

// ==== AJAX HANDLERS ====
if (isset($_GET['action'])) {
    $pdo = db();

    // Get all voucher requests with pagination and filtering
    if ($_GET['action'] === 'get_requests') {
        $stmt = $pdo->prepare("
        SELECT request_id, phone, package, price, created_at, status, notes, voucher_code, approved_at,
        (SELECT COUNT(*) FROM voucher_requests vr2 WHERE vr2.phone = voucher_requests.phone) as phone_count
        FROM voucher_requests 
        ORDER BY FIELD(status, 'pending', 'approved', 'rejected') ASC, created_at DESC LIMIT 500
    ");
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format data for frontend display
        foreach ($requests as &$request) {
            $request['phone_formatted'] = formatPhoneNumber($request['phone']);
            $request['created_at_formatted'] = date('M j, Y g:i A', strtotime($request['created_at']));
            $request['approved_at_formatted'] = $request['approved_at'] ?
                date('M j, Y g:i A', strtotime($request['approved_at'])) : null;
            $request['package_profile'] = getPackageProfile($request['package']);
        }

        echo json_encode($requests);
        exit;
    }

    // Get voucher inventory status (Available, Used, Total only)
    if ($_GET['action'] === 'get_inventory') {
        $tables = ['daily_vouchers', 'weekly_vouchers', 'monthly_vouchers'];
        $inventory = [];

        foreach ($tables as $table) {
            // Count vouchers by status (excluding expired from display)
            $stmt = $pdo->prepare("SELECT status, COUNT(*) AS count FROM $table GROUP BY status");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Initialize inventory counters
            $inventory[$table] = ['Available' => 0, 'Used' => 0];
            foreach ($results as $row) {
                if ($row['status'] === 'Available' || $row['status'] === 'Used') {
                    $inventory[$table][$row['status']] = (int)$row['count'];
                }
            }

            // Calculate total count (Available + Used only)
            $inventory[$table]['Total'] = $inventory[$table]['Available'] + $inventory[$table]['Used'];
        }

        echo json_encode($inventory);
        exit;
    }

    // Get dashboard statistics
    if ($_GET['action'] === 'get_stats') {
        $stats = [];

        try {
            // Count of pending requests
            $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM voucher_requests WHERE status = 'pending'");
            $stmt->execute();
            $stats['pending_requests'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Total requests for today - using DATE() to compare dates properly
            $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM voucher_requests WHERE DATE(created_at) = CURDATE()");
            $stmt->execute();
            $stats['requests_today'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Revenue generated today - using DATE() to compare dates properly
            $stmt = $pdo->prepare("SELECT SUM(price) AS revenue FROM voucher_requests WHERE status = 'approved' AND DATE(approved_at) = CURDATE()");
            $stmt->execute();
            $stats['revenue_today'] = (float)($stmt->fetch(PDO::FETCH_ASSOC)['revenue'] ?? 0);

            // Monthly total revenue - using proper date functions
            $stmt = $pdo->prepare("SELECT SUM(price) AS revenue FROM voucher_requests WHERE status = 'approved' AND MONTH(approved_at) = MONTH(CURDATE()) AND YEAR(approved_at) = YEAR(CURDATE())");
            $stmt->execute();
            $stats['revenue_monthly'] = (float)($stmt->fetch(PDO::FETCH_ASSOC)['revenue'] ?? 0);
        } catch (Exception $e) {
            error_log("Stats query error: " . $e->getMessage());
            // Return safe defaults on error
            $stats = [
                'pending_requests' => 0,
                'requests_today' => 0,
                'revenue_today' => 0,
                'revenue_monthly' => 0
            ];
        }

        echo json_encode($stats);
        exit;
    }

    // Approve a payment request and assign voucher
    if ($_GET['action'] === 'approve' && !empty($_POST['id'])) {
        $request_id = $_POST['id'];

        try {
            $pdo->beginTransaction();

            // Get and lock the pending request
            $stmt = $pdo->prepare("SELECT * FROM voucher_requests WHERE request_id = ? AND status = 'pending' FOR UPDATE");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Request not found']);
                exit;
            }

            $voucher_table = getVoucherTable($request['package']);

            // Get and lock an available voucher
            $stmt = $pdo->prepare("SELECT * FROM $voucher_table WHERE status = 'Available' ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
            $stmt->execute();
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$voucher) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => "No available vouchers for package '{$request['package']}'. Try again later"]);
                exit;
            }

            // Format phone number for SMS delivery
            $formatted_phone = formatPhoneNumber($request['phone']);

            // Mark voucher as used and assign to customer
            $stmt = $pdo->prepare("UPDATE $voucher_table SET status = 'Used', user_phone = ?, used_at = NOW() WHERE id = ?");
            $stmt->execute([$formatted_phone, $voucher['id']]);

            // Update request status to approved
            $stmt = $pdo->prepare("UPDATE voucher_requests SET status = 'approved', voucher_code = ?, approved_at = NOW() WHERE request_id = ?");
            $stmt->execute([$voucher['voucher_code'], $request_id]);

            // Log the approval action for audit trail
            $stmt = $pdo->prepare("
                INSERT INTO system_logs 
                (log_type, reference_id, user_identifier, action_description, ip_address) 
                VALUES ('voucher_approval', ?, 'admin', ?, ?)
            ");
            $action_description = "Approved payment request. Assigned voucher {$voucher['voucher_code']} to {$formatted_phone}";
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt->execute([$request_id, $action_description, $ip_address]);

            $pdo->commit();

            // Send voucher code to customer via SMS
            $sms_message = "Voucher code: {$voucher['voucher_code']}. Valid for {$request['package']}";
            $sms_sent = sendSMS($formatted_phone, $sms_message);

            // Log SMS delivery attempt
            $stmt = $pdo->prepare("
                INSERT INTO system_logs 
                (log_type, reference_id, user_identifier, action_description) 
                VALUES ('system_action', ?, ?, ?)
            ");
            $sms_log = $sms_sent ?
                "Voucher SMS sent successfully to {$formatted_phone}" :
                "Failed to send voucher SMS to {$formatted_phone}";
            $stmt->execute([$request_id, 'system', $sms_log]);

            echo json_encode([
                'success' => true,
                'message' => 'Request approved successfully.',
                'voucher_code' => $voucher['voucher_code'],
                'sms_sent' => $sms_sent
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Approval error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Unable to approve request. Check server logs.']);
            exit;
        }
    }

    // Reject a payment request with reason
    if ($_GET['action'] === 'reject' && !empty($_POST['id']) && !empty($_POST['reason'])) {
        $request_id = $_POST['id'];
        $reason = trim($_POST['reason']);
        if (strlen($reason)>500) json_response(['success'=>false,'message'=>'Reason must be at most 500 characters.']);

        try {
            $pdo->beginTransaction();
            // Verify request exists and is still pending
            $stmt = $pdo->prepare("SELECT * FROM voucher_requests WHERE request_id = ? AND status = 'pending' FOR UPDATE");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Request not found']);
                exit;
            }

            // Update request status to rejected with reason
            $stmt = $pdo->prepare("UPDATE voucher_requests SET status = 'rejected', notes = ?, approved_at = NOW() WHERE request_id = ?");
            $stmt->execute([$reason, $request_id]);

            // Log the rejection action
            $stmt = $pdo->prepare("
                INSERT INTO system_logs 
                (log_type, reference_id, user_identifier, action_description, ip_address) 
                VALUES ('voucher_approval', ?, 'admin', ?, ?)
            ");
            $action_description = "Rejected payment request for {$request['phone']}. Reason: $reason";
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt->execute([$request_id, $action_description, $ip_address]);

            $pdo->commit();
            // Send rejection notification to customer
            $formatted_phone = formatPhoneNumber($request['phone']);
            $sms_message = "Request rejected. Reason: $reason.";
            $sms_sent = sendSMS($formatted_phone, $sms_message);

            echo json_encode([
                'success' => true,
                'message' => 'Request rejected and user notified.',
                'sms_sent' => $sms_sent
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Rejection error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Unable to reject request. Check server logs.']);
            exit;
        }
    }

    // Handle invalid action requests
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TemboNet Admin Dashboard</title>

    <!-- External CSS Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Custom CSS Files -->
    <link href="../css/mainstyles.css" rel="stylesheet">
    <link href="../css/responsive.css" rel="stylesheet">
    <link href="../css/approvepayment.css" rel="stylesheet">
</head>

<body>
<nav class="container py-3 d-flex gap-3 align-items-center"><a href="inventory.php">Import vouchers</a><a href="login.html">Customer portal</a><form action="logout.php" method="post" class="ms-auto"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><button class="btn btn-outline-secondary">Sign out</button></form></nav>
    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand">
                <img src="../images/FastNetUGbg.png" alt="FastNetUG Logo" class="logo-text">
            </span>
            <!-- Live clock display -->
            <span class="navbar-text">
                <i class="fas fa-clock me-1"></i>
                <span id="current-time"></span>
            </span>
        </div>
    </nav>

    <!-- Auto-refresh indicator -->
    <div id="refresh-indicator">

    </div>

    <div class="container-fluid my-4">
        <!-- Dashboard Statistics Cards Row -->
        <div class="row mb-4">
            <!-- Pending Requests Counter -->
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stats-icon pending">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-1" id="pending-count">0 Pending</h5>
                            <p class="card-text text-muted mb-0">Requests</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Today's Requests Counter (resets daily at midnight) -->
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stats-icon today">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-1" id="today-count">0 Requests</h5>
                            <p class="card-text text-muted mb-0">Today</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Today's Revenue Counter (resets daily at midnight) -->
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stats-icon revenue">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-1" id="revenue-today">UGX 0</h5>
                            <p class="card-text text-muted mb-0">Revenue Today</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Revenue Counter (resets at end of month) -->
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stats-icon monthly">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-1" id="revenue-monthly">UGX 0</h5>
                            <p class="card-text text-muted mb-0">Revenue This Month</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Payment Requests Management Section -->
            <div class="col-lg-8">
                <div class="card main-card">
                    <div class="card-header-custom d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-list-alt me-2"></i>Payment Requests</span>
                        <!-- Single refresh button that refreshes all data -->
                        <button class="refreshbtn" onclick="refreshAllData()">
                            <i class="fas fa-sync-alt me-1"></i>Refresh
                        </button>
                    </div>
                    <div class="card-body p-3">
                        <div class="table-responsive table-container">
                            <table class="table table-hover mb-0" id="requests-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Phone Number</th>
                                        <th>Profile</th>
                                        <!-- <th>Time</th> -->
                                        <th>Status</th>
                                        <th>Code</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="fas fa-spinner fa-spin me-2"></i>Loading requests...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Voucher Inventory Section (3 categories: Available, Used, Total) -->
            <div class="col-lg-4">
                <div class="card main-card">
                    <div class="card-header-custom">
                        <span><i class="fas fa-boxes me-2"></i>Voucher Inventory</span>
                    </div>
                    <div class="card-body">
                        <div id="inventory-container">
                            <div class="text-center py-3">
                                <i class="fas fa-spinner fa-spin me-2"></i>Loading inventory...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Rejecting Payment Requests -->
    <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form id="reject-form" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectModalLabel">
                        <i class="fas fa-times-circle me-2"></i>Reject Payment Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="reject-request-id" name="id">

                    <div class="mb-3">
                        <label for="reject-reason" class="form-label">
                            <i class="fas fa-comment-alt me-1"></i>Reason for rejection
                        </label>
                        <textarea class="form-control" id="reject-reason" name="reason" rows="3"
                            placeholder="Please provide a clear reason for rejecting this request..." required></textarea>
                        <div class="form-text">This reason will be sent to the customer via SMS.</div>
                    </div>

                    <div class="alert alert-danger d-none" id="reject-error"></div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-1"></i>Reject Request
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Success/Error Toast Notifications -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="actionToast" class="toast" role="alert">
            <div class="toast-header">
                <i class="fas fa-check-circle text-success me-2"></i>
                <strong class="me-auto">FastNetUG</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body"></div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        $.ajaxSetup({headers: {'X-CSRF-Token': <?=json_encode($_SESSION['csrf'])?>}});
        const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        // Initialize Bootstrap components
        const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
        const actionToast = new bootstrap.Toast(document.getElementById('actionToast'));

        /**
         * Display toast notification to user
         * @param {string} message - Message to display
         * @param {boolean} isSuccess - Whether this is a success or error message
         */
        function showToast(message, isSuccess = true) {
            const toast = document.getElementById('actionToast');
            const icon = toast.querySelector('.toast-header i');
            const body = toast.querySelector('.toast-body');

            // Set appropriate icon based on message type
            if (isSuccess) {
                icon.className = 'fas fa-check-circle text-success me-2';
            } else {
                icon.className = 'fas fa-exclamation-circle text-danger me-2';
            }

            body.textContent = message;
            actionToast.show();
        }

        /**
         * Show auto-refresh indicator briefly
         */
        function showRefreshIndicator() {
            const indicator = document.getElementById('refresh-indicator');
            indicator.classList.add('show');
            setTimeout(() => indicator.classList.remove('show'), 1500);
        }

        /**
         * Update the live clock display in navigation
         */
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleString('en-US', {
                timeZone: 'Africa/Kampala',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('current-time').textContent = timeString;
        }

        /**
         * Load and display dashboard statistics
         */
        function loadStats() {
            $.getJSON('?action=get_stats')
                .done(function(data) {
                    $('#pending-count').text(data.pending_requests + ' Pending');
                    $('#today-count').text(data.requests_today + ' Requests');
                    $('#revenue-today').text('UGX ' + Number(data.revenue_today).toLocaleString());
                    $('#revenue-monthly').text('UGX ' + Number(data.revenue_monthly).toLocaleString());
                })
                .fail(function() {
                    console.error('Failed to load statistics');
                });
        }

        /**
         * Load and display payment requests in table format
         */
        function loadRequests() {
            $.getJSON('?action=get_requests')
                .done(function(data) {
                    const tbody = $('#requests-table tbody');
                    tbody.empty();

                    // Display message if no requests found
                    if (data.length === 0) {
                        tbody.append(`
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="fas fa-inbox me-2"></i>No payment requests found
                                </td>
                            </tr>
                        `);
                        return;
                    }

                    // Populate table with request data
                    data.forEach(function(request) {
                        Object.keys(request).forEach(key => { if (typeof request[key] === 'string') request[key] = escapeHtml(request[key]); });
                        let statusBadge = '';
                        let actionsHtml = '';

                        // Generate status badge and action buttons based on request status
                        switch (request.status) {
                            case 'pending':
                                statusBadge = '<span class="status-badge status-pending">Pending</span>';
                                actionsHtml = `
                                    <button class="btn btn-success btn-sm me-1 approve-btn" data-id="${request.request_id}" title="Approve Request">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-danger btn-sm reject-btn" data-id="${request.request_id}" title="Reject Request">
                                        <i class="fas fa-times"></i>
                                    </button>
                                `;
                                break;
                            case 'approved':
                                statusBadge = '<span class="status-badge status-approved">Approved</span>';
                                actionsHtml = '<small class="text-muted">Completed</small>';
                                break;
                            case 'rejected':
                                statusBadge = '<span class="status-badge status-rejected">Rejected</span>';
                                actionsHtml = '<small class="text-muted">Rejected</small>';
                                break;
                            default:
                                statusBadge = `<span class="status-badge">${request.status}</span>`;
                                actionsHtml = '<small class="text-muted">-</small>';
                        }

                        // Display voucher code if available
                        const voucherCode = request.voucher_code ?
                            `<code class="bg-light px-2 py-1 rounded">${request.voucher_code}</code>` :
                            '<small class="text-muted">-</small>';

                        // Add row to table
                        const regularClientClass = request.phone_count >= 3 ? 'regular-client' : '';
                        tbody.append(`
                            <tr class="${regularClientClass}">
                                <td><strong>${request.request_id}</strong></td>
                                <td>${request.phone_formatted}</td>
                                <td><span class="badge bg-secondary">${request.package_profile}</span></td>
                                <td>${statusBadge}</td>
                                <td>${voucherCode}</td>
                                <td class="text-center">${actionsHtml}</td>
                            </tr>
                        `);
                    });
                })
                .fail(function() {
                    const tbody = $('#requests-table tbody');
                    tbody.html(`
                        <tr>
                            <td colspan="6" class="text-center py-4 text-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>Failed to load requests
                            </td>
                        </tr>
                    `);
                });
        }

        /**
         * Load and display voucher inventory (Available, Used, Total only)
         */
        function loadInventory() {
            $.getJSON('?action=get_inventory')
                .done(function(data) {
                    const container = $('#inventory-container');
                    container.empty();

                    // Map table names to user-friendly titles
                    const tableNames = {
                        'daily_vouchers': 'Daily Vouchers (24 Hours)',
                        'weekly_vouchers': 'Weekly Vouchers (1 Week)',
                        'monthly_vouchers': 'Monthly Vouchers (1 Month)'
                    };

                    // Generate inventory display for each voucher type
                    Object.entries(data).forEach(function([table, counts]) {
                        const title = tableNames[table] || table;
                        const totalVouchers = counts.Total || 0;
                        const availablePercent = totalVouchers > 0 ? Math.round((counts.Available / totalVouchers) * 100) : 0;

                        // Set alert color based on availability percentage
                        let alertClass = '';
                        if (availablePercent < 20) alertClass = 'border-danger';
                        else if (availablePercent < 50) alertClass = 'border-warning';
                        else alertClass = 'border-success';

                        // Create inventory item (3 widgets: Available, Used, Total)
                        container.append(`
                            <div class="inventory-item ${alertClass}">
                                <div class="inventory-title">${title}</div>
                                <div class="inventory-stats">
                                    <div class="inventory-stat">
                                        <div class="inventory-stat-value text-success">${counts.Available || 0}</div>
                                        <div class="inventory-stat-label">Available</div>
                                    </div>
                                    <div class="inventory-stat">
                                        <div class="inventory-stat-value text-primary">${counts.Used || 0}</div>
                                        <div class="inventory-stat-label">Used</div>
                                    </div>
                                    <div class="inventory-stat">
                                        <div class="inventory-stat-value text-secondary">${totalVouchers}</div>
                                        <div class="inventory-stat-label">Total</div>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-success" style="width: ${availablePercent}%"></div>
                                    </div>
                                    <small class="text-muted">${availablePercent}% available</small>
                                </div>
                            </div>
                        `);
                    });
                })
                .fail(function() {
                    $('#inventory-container').html(`
                        <div class="text-center py-3 text-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>Failed to load inventory
                        </div>
                    `);
                });
        }

        /**
         * Refresh all dashboard data (requests, inventory, and stats)
         */
        function refreshAllData() {
            //showRefreshIndicator();
            loadRequests();
            loadInventory();
            loadStats();
        }

        /**
         * Handle approval of a payment request
         * @param {string} requestId - The request ID to approve
         */
        function handleApprove(requestId) {
            if (!confirm(`Are you sure you want to approve request ${requestId}? This will send a voucher code to the customer.`)) {
                return;
            }

            const button = $(`.approve-btn[data-id="${requestId}"]`);
            const originalHtml = button.html();
            button.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);

            $.post('?action=approve', {
                    id: requestId
                })
                .done(function(response) {
                    try {
                        // Parse response if it's a string
                        const data = typeof response === 'string' ? JSON.parse(response) : response;

                        if (data.success) {
                            showToast(data.sms_sent ? 'Approved; voucher SMS sent.' : 'Approved; SMS not sent. Deliver the displayed voucher manually.');
                            refreshAllData(); // Refresh all data after approval
                        } else { showToast(data.message || 'Approval failed', false); button.html(originalHtml).prop('disabled', false);
                        }
                    } catch (e) {
                        showToast('Invalid response format', false);
                        button.html(originalHtml).prop('disabled', false);
                    }
                })
                .fail(function() {
                    showToast('Network error. Please try again.', false);
                    button.html(originalHtml).prop('disabled', false);
                });
        }

        /**
         * Handle rejection of a payment request
         * @param {string} requestId - The request ID to reject
         */
        function handleReject(requestId) {
            $('#reject-request-id').val(requestId);
            $('#reject-reason').val('');
            $('#reject-error').addClass('d-none').text('');
            rejectModal.show();
        }

        // Initialize page when DOM is ready
        $(document).ready(function() {
            // Load initial data
            refreshAllData();
            updateCurrentTime();

            // Update live clock every second
            setInterval(updateCurrentTime, 1000);

            // Auto-refresh all data every 5 seconds
            setInterval(function() {
                refreshAllData();
            }, 5000);

            // Event handlers for approve/reject buttons
            $(document).on('click', '.approve-btn', function() {
                const requestId = $(this).data('id');
                handleApprove(requestId);
            });

            $(document).on('click', '.reject-btn', function() {
                const requestId = $(this).data('id');
                handleReject(requestId);
            });

            // Handle reject form submission
            $('#reject-form').on('submit', function(e) {
                e.preventDefault();

                const requestId = $('#reject-request-id').val();
                const reason = $('#reject-reason').val().trim();

                if (!reason) {
                    $('#reject-error').removeClass('d-none').text('Please enter a rejection reason.');
                    return;
                }

                const submitBtn = $(this).find('button[type="submit"]');
                const originalHtml = submitBtn.html();
                submitBtn.html('<i class="fas fa-spinner fa-spin me-1"></i>Rejecting...').prop('disabled', true);

                $.post('?action=reject', {
                        id: requestId,
                        reason: reason
                    })
                    .done(function(response) {
                        try {
                            // Parse response if it's a string
                            const data = typeof response === 'string' ? JSON.parse(response) : response;

                            if (data.success) {
                                rejectModal.hide();
                                showToast(`Request rejected`);
                                refreshAllData(); // Refresh all data after rejection
                            } else { $('#reject-error').removeClass('d-none').text(data.message || 'Rejection failed.');
                            }
                        } catch (e) {
                            $('#reject-error').removeClass('d-none').text('Invalid response format.');
                        }
                        submitBtn.html(originalHtml).prop('disabled', false);
                    }).fail(function() { $('#reject-error').removeClass('d-none').text('Request failed. Please retry.'); submitBtn.html(originalHtml).prop('disabled', false); });
            });

            // Clear modal form when hidden
            $('#rejectModal').on('hidden.bs.modal', function() {
                $('#reject-reason').val('');
                $('#reject-error').addClass('d-none').text('');
            });
        });

        // Keyboard shortcuts
        $(document).keydown(function(e) {
            // Ctrl/Cmd + R to refresh all data
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 82) {
                e.preventDefault();
                refreshAllData();
            }
        });
    </script>
</body>

</html>