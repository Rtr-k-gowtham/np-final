<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Webhook Inbox</title>
    <!-- Google Fonts - Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            --bs-body-bg: #0b0f19;
            --bs-body-color: #f3f4f6;
            --card-bg: #111827;
            --card-border: #1f2937;
            --hover-bg: #1f2937;
            --accent-green: #10b981;
            --purple-color: #8b5cf6;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bs-body-bg);
            color: var(--bs-body-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(16, 185, 129, 0.05) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(139, 92, 246, 0.05) 0px, transparent 50%);
            background-attachment: fixed;
            min-height: 100vh;
        }

        .bg-purple-subtle {
            background-color: rgba(139, 92, 246, 0.15) !important;
        }
        .text-purple {
            color: #a78bfa !important;
        }
        .border-purple-subtle {
            border-color: rgba(139, 92, 246, 0.3) !important;
        }

        .navbar {
            background-color: rgba(17, 24, 39, 0.8) !important;
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--card-border);
        }

        .logo-text {
            font-weight: 700;
            letter-spacing: -0.5px;
            background: linear-gradient(to right, var(--accent-green), var(--purple-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .card-custom {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.3);
        }

        .form-control-custom {
            background-color: #1f2937;
            border: 1px solid #374151;
            color: #f3f4f6;
            border-radius: 10px;
        }

        .form-control-custom:focus {
            background-color: #1f2937;
            border-color: var(--accent-green);
            color: #f3f4f6;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
        }

        .btn-custom-search {
            background-color: var(--accent-green);
            color: #0b0f19;
            font-weight: 500;
            border-radius: 10px;
            border: none;
            transition: all 0.2s ease-in-out;
        }

        .btn-custom-search:hover {
            background-color: #059669;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-custom-clear {
            background-color: #374151;
            color: #f3f4f6;
            border-radius: 10px;
            border: 1px solid #4b5563;
        }

        .btn-custom-clear:hover {
            background-color: #4b5563;
            color: #ffffff;
        }

        .table-custom {
            color: #f3f4f6;
            vertical-align: middle;
        }

        .table-custom thead th {
            background-color: rgba(17, 24, 39, 0.5);
            color: #9ca3af;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--card-border);
            padding: 16px;
        }

        .table-custom tbody td {
            padding: 16px;
            border-bottom: 1px solid var(--card-border);
            background-color: transparent;
        }

        .table-custom tbody tr {
            transition: background-color 0.15s ease-in-out;
        }

        .table-custom tbody tr:hover {
            background-color: var(--hover-bg);
        }

        .badge {
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.75rem;
        }

        /* Customize switch styling */
        .form-switch .form-check-input {
            width: 2.5em;
            height: 1.25em;
            background-color: #374151;
            border-color: #4b5563;
            cursor: pointer;
        }

        .form-switch .form-check-input:checked {
            background-color: var(--accent-green);
            border-color: var(--accent-green);
        }

        .empty-state {
            text-align: center;
            padding: 48px 16px;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 3rem;
            color: #4b5563;
            margin-bottom: 16px;
        }

        /* Countdown display */
        .countdown-indicator {
            font-size: 0.85rem;
            font-weight: 500;
            color: #9ca3af;
        }
    </style>
</head>
<body>

    <!-- Navigation Header -->
    <nav class="navbar navbar-dark bg-dark sticky-top">
        <div class="container">
            <span class="navbar-brand d-flex align-items-center">
                <i class="bi bi-whatsapp text-success me-2 fs-3"></i>
                <span class="logo-text fs-4">WhatsApp Webhook Portal</span>
            </span>
            <div class="d-flex align-items-center">
                <span class="badge bg-success-subtle text-success border border-success-subtle d-flex align-items-center gap-1">
                    <span class="spinner-grow spinner-grow-sm text-success" role="status" style="width: 8px; height: 8px;"></span>
                    Live Webhook Active
                </span>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="container my-4">

        <!-- Search & Control Panel -->
        <div class="card card-custom p-4 mb-4">
            <div class="row g-3 align-items-center">
                <!-- Search Form -->
                <div class="col-md-7">
                    <form method="GET" action="" class="row g-2">
                        <!-- Keep refresh state in search form -->
                        <input type="hidden" name="refresh" value="<?= esc($autoRefresh) ?>">
                        
                        <div class="col-sm-8 position-relative">
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary border-end-0 text-secondary">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" name="search" class="form-control form-control-custom border-start-0" placeholder="Filter by phone number (e.g. 9199...)" value="<?= esc($search) ?>">
                            </div>
                        </div>
                        <div class="col-sm-4 d-flex gap-2">
                            <button type="submit" class="btn btn-custom-search px-3 flex-grow-1">Search</button>
                            <?php if ($search !== ''): ?>
                                <a href="?refresh=<?= esc($autoRefresh) ?>" class="btn btn-custom-clear d-flex align-items-center justify-content-center px-3"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Spacer -->
                <div class="col-md-1"></div>
                
                <!-- Auto Refresh Controls -->
                <div class="col-md-4 d-flex justify-content-md-end align-items-center gap-3 border-start border-secondary ps-md-4 py-1">
                    <div class="form-check form-switch d-flex align-items-center gap-2 mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="refreshToggle" <?= $autoRefresh === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label text-secondary" for="refreshToggle">Auto-Refresh</label>
                    </div>
                    <?php if ($autoRefresh === '1'): ?>
                        <div class="countdown-indicator d-flex align-items-center gap-1 bg-dark px-3 py-2 rounded-3 border border-secondary">
                            <i class="bi bi-arrow-clockwise text-success spinner-animation"></i>
                            Reloading in <span id="countdown">5</span>s
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Messages Dashboard Table -->
        <div class="card card-custom overflow-hidden">
            <div class="card-header bg-dark bg-opacity-50 py-3 px-4 d-flex justify-content-between align-items-center border-bottom border-secondary">
                <h5 class="mb-0 text-white font-weight-600">Messages Inbox</h5>
                <span class="text-secondary small fw-medium">Total Received: <span class="text-white"><?= count($messages) ?></span></span>
            </div>
            
            <div class="table-responsive">
                <?php if (empty($messages)): ?>
                    <div class="empty-state">
                        <i class="bi bi-chat-left-dots"></i>
                        <h5>No messages found</h5>
                        <p class="text-secondary mb-0">
                            <?= $search !== '' ? 'No messages matches your phone number query.' : 'Send a message to your WhatsApp Cloud API number to get started.'; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <table class="table table-custom table-dark mb-0">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Sender Name / Number</th>
                                <th style="width: 12%;">Type</th>
                                <th style="width: 50%;">Message Content</th>
                                <th style="width: 18%;">Time Received</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($messages as $msg): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-white">
                                            <?= esc($msg['sender_name'] ?? 'Guest') ?>
                                        </div>
                                        <div class="small text-secondary mt-1">
                                            <i class="bi bi-telephone text-success-emphasis me-1"></i>+<?= esc($msg['sender_phone']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $type = strtolower($msg['message_type']);
                                        $badgeClass = 'bg-secondary-subtle text-secondary border border-secondary-subtle';
                                        if ($type === 'text') $badgeClass = 'bg-success-subtle text-success border border-success-subtle';
                                        elseif ($type === 'image') $badgeClass = 'bg-primary-subtle text-primary border border-primary-subtle';
                                        elseif ($type === 'video') $badgeClass = 'bg-warning-subtle text-warning border border-warning-subtle';
                                        elseif ($type === 'document') $badgeClass = 'bg-info-subtle text-info border border-info-subtle';
                                        elseif ($type === 'location') $badgeClass = 'bg-danger-subtle text-danger border border-danger-subtle';
                                        elseif (in_array($type, ['button', 'interactive'])) $badgeClass = 'bg-purple-subtle text-purple border border-purple-subtle';
                                        ?>
                                        <span class="badge <?= $badgeClass ?>">
                                            <?= esc(ucfirst($msg['message_type'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-wrap lh-base" style="word-break: break-word; white-space: pre-line; max-height: 150px; overflow-y: auto;">
                                            <?= esc($msg['message_content']) ?>
                                        </div>
                                    </td>
                                    <td class="text-secondary small">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        <?php
                                        if ($msg['created_at']) {
                                            $date = new DateTime($msg['created_at']);
                                            echo $date->format('d M Y, h:i A');
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
    </div>

    <!-- Bootstrap JS Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    
    <!-- Auto Refresh Javascript Control -->
    <script>
        const refreshToggle = document.getElementById('refreshToggle');
        const countdownEl = document.getElementById('countdown');
        let refreshEnabled = <?= $autoRefresh === '1' ? 'true' : 'false' ?>;
        let countdownVal = 5;
        let intervalId = null;

        // Sync switch changes
        refreshToggle.addEventListener('change', function() {
            const isChecked = this.checked;
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('refresh', isChecked ? '1' : '0');
            window.location.href = currentUrl.toString();
        });

        // Initialize Countdown Timer if enabled
        if (refreshEnabled && countdownEl) {
            intervalId = setInterval(() => {
                countdownVal--;
                countdownEl.textContent = countdownVal;
                
                if (countdownVal <= 0) {
                    clearInterval(intervalId);
                    window.location.reload();
                }
            }, 1000);
        }
    </script>
</body>
</html>
