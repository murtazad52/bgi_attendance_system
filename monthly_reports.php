<?php
require_once __DIR__ . '/monthly_reports_lib.php';
include('session_check.php');
include('db.php');

bgi_require_roles([BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN]);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$defaultPeriod = bgi_monthly_report_period();
$selectedMonth = filter_var($_GET['month'] ?? $defaultPeriod['month'], FILTER_VALIDATE_INT);
$selectedMonth = $selectedMonth !== false && $selectedMonth >= 1 && $selectedMonth <= 12 ? $selectedMonth : (int) $defaultPeriod['month'];
$selectedYear = filter_var($_GET['year'] ?? $defaultPeriod['year'], FILTER_VALIDATE_INT);
$currentYear = (int) date('Y');
$selectedYear = $selectedYear !== false && $selectedYear >= $currentYear - 5 && $selectedYear <= $currentYear + 1 ? $selectedYear : (int) $defaultPeriod['year'];
$selectedRoleFilter = bgi_normalize_monthly_report_role_filter($_GET['recipient_role'] ?? BGI_MONTHLY_REPORT_ROLE_ALL);
$selectedScopeFilters = bgi_monthly_normalize_scope_filters($_GET);
$selectedHierarchyFilters = bgi_monthly_normalize_hierarchy_filters($_GET);
$baseScopeFilter = bgi_monthly_scope_filter_for_current_user();
$visibleScopeOptions = array_values(array_filter(
    bgi_get_scope_options($conn),
    static function (array $option): bool {
        return bgi_scope_matches_current($option['idara'] ?? '', $option['mohalla'] ?? '');
    }
));
$visibleIdaras = [];
$visibleMohallas = [];
foreach ($visibleScopeOptions as $scopeOption) {
    $idara = (string) ($scopeOption['idara'] ?? '');
    $mohalla = (string) ($scopeOption['mohalla'] ?? '');
    if ($idara !== '') {
        $visibleIdaras[$idara] = $idara;
    }
    if ($mohalla !== '') {
        $visibleMohallas[$mohalla] = $mohalla;
    }
}
if ($selectedScopeFilters['idara'] !== '' && !isset($visibleIdaras[$selectedScopeFilters['idara']])) {
    $selectedScopeFilters['idara'] = '';
}
if ($selectedScopeFilters['mohalla'] !== '' && !isset($visibleMohallas[$selectedScopeFilters['mohalla']])) {
    $selectedScopeFilters['mohalla'] = '';
}
$scopeFilter = bgi_monthly_build_effective_scope_filter($baseScopeFilter, $selectedScopeFilters);
$period = bgi_monthly_report_period($selectedYear, $selectedMonth);
$smtpConfig = bgi_load_smtp_config();
$smtpEnabled = !empty($smtpConfig['enabled']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $postMonth = filter_var($_POST['month'] ?? $defaultPeriod['month'], FILTER_VALIDATE_INT);
    $postMonth = $postMonth !== false && $postMonth >= 1 && $postMonth <= 12 ? $postMonth : (int) $defaultPeriod['month'];
    $postYear = filter_var($_POST['year'] ?? $defaultPeriod['year'], FILTER_VALIDATE_INT);
    $postYear = $postYear !== false && $postYear >= $currentYear - 5 && $postYear <= $currentYear + 1 ? $postYear : (int) $defaultPeriod['year'];
    $postRoleFilter = bgi_normalize_monthly_report_role_filter($_POST['recipient_role'] ?? BGI_MONTHLY_REPORT_ROLE_ALL);
    $postScopeFilters = bgi_monthly_normalize_scope_filters($_POST);
    $postHierarchyFilters = bgi_monthly_normalize_hierarchy_filters($_POST);
    $forceResend = !empty($_POST['force_resend']);

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        bgi_set_flash('Invalid request token. Please refresh the page and try again.', 'error');
    } else {
        $postEffectiveScopeFilter = bgi_monthly_build_effective_scope_filter($baseScopeFilter, $postScopeFilters);
        $sendSummary = bgi_send_monthly_reports($conn, $postYear, $postMonth, $postEffectiveScopeFilter, $postRoleFilter, $forceResend, $postHierarchyFilters);
        $periodLabel = $sendSummary['period']['month_label'] ?? ($postMonth . '/' . $postYear);
        $flashText = $periodLabel . ' monthly reports processed: '
            . $sendSummary['sent'] . ' sent, '
            . $sendSummary['already_sent'] . ' already sent, '
            . $sendSummary['missing_email'] . ' missing email, '
            . $sendSummary['smtp_disabled'] . ' skipped because SMTP is disabled, '
            . $sendSummary['failed'] . ' failed.';
        bgi_set_flash($flashText, $sendSummary['failed'] > 0 ? 'error' : 'success');
    }

    $conn->close();
    header('Location: monthly_reports.php?year=' . urlencode((string) $postYear)
        . '&month=' . urlencode((string) $postMonth)
        . '&recipient_role=' . urlencode($postRoleFilter)
        . '&idara=' . urlencode($postScopeFilters['idara'])
        . '&mohalla=' . urlencode($postScopeFilters['mohalla'])
        . '&captain_its_id=' . urlencode($postHierarchyFilters['captain_its_id'])
        . '&team_leader_its_id=' . urlencode($postHierarchyFilters['team_leader_its_id'])
        . '&member_its_id=' . urlencode($postHierarchyFilters['member_its_id']));
    exit;
}

$events = bgi_fetch_monthly_scope_events($conn, $period['year'], $period['month'], $scopeFilter);
$allHierarchyOptions = bgi_fetch_monthly_hierarchy_options($conn, $baseScopeFilter, []);
$hierarchyOptions = bgi_fetch_monthly_hierarchy_options($conn, $scopeFilter, $selectedHierarchyFilters);
$recipients = bgi_fetch_monthly_report_recipients($conn, $period['year'], $period['month'], $scopeFilter, $selectedRoleFilter, $selectedHierarchyFilters);
$dispatchMap = bgi_fetch_monthly_dispatch_map($conn, $period['year'], $period['month'], $scopeFilter, $selectedRoleFilter);

$readyEmailCount = 0;
$missingEmailCount = 0;
$alreadySentCount = 0;
$captainCount = 0;
$teamLeaderCount = 0;
$memberCount = 0;

foreach ($recipients as &$recipient) {
    $dispatchKey = bgi_monthly_dispatch_key((string) ($recipient['role'] ?? ''), (string) ($recipient['its_id'] ?? ''));
    $dispatch = $dispatchMap[$dispatchKey] ?? null;
    $recipient['dispatch'] = $dispatch;
    $recipient['delivery_status'] = $dispatch['status'] ?? ($recipient['email_ready'] ? 'pending' : 'skipped_missing_email');
    $recipient['delivery_label'] = bgi_monthly_report_status_label($recipient['delivery_status']);
    $recipient['delivery_class'] = bgi_monthly_report_status_class($recipient['delivery_status']);

    if (($recipient['role'] ?? '') === BGI_POSITION_CAPTAIN) {
        $captainCount++;
    } elseif (($recipient['role'] ?? '') === BGI_POSITION_TEAM_LEADER) {
        $teamLeaderCount++;
    } elseif (($recipient['role'] ?? '') === BGI_POSITION_MEMBER) {
        $memberCount++;
    }

    if (!empty($recipient['email_ready'])) {
        $readyEmailCount++;
    } else {
        $missingEmailCount++;
    }

    if (($dispatch['status'] ?? '') === 'sent') {
        $alreadySentCount++;
    }
}
unset($recipient);

$conn->close();
$scopeLabel = bgi_is_super_admin() ? 'All Idara / All Mohalla' : bgi_current_scope_label();
$roleFilterLabel = $selectedRoleFilter === BGI_POSITION_CAPTAIN
    ? 'Captains only'
    : ($selectedRoleFilter === BGI_POSITION_TEAM_LEADER
        ? 'Team Leaders only'
        : ($selectedRoleFilter === BGI_POSITION_MEMBER ? 'Members only' : 'Captains, Team Leaders, and Members'));
$selectedCaptainItsId = $selectedHierarchyFilters['captain_its_id'];
$selectedTeamLeaderItsId = $selectedHierarchyFilters['team_leader_its_id'];
$selectedMemberItsId = $selectedHierarchyFilters['member_its_id'];
$selectedIdara = $selectedScopeFilters['idara'];
$selectedMohalla = $selectedScopeFilters['mohalla'];
$idaraOptions = [];
$mohallaOptions = [];
foreach ($visibleScopeOptions as $scopeOption) {
    $optionIdara = (string) ($scopeOption['idara'] ?? '');
    $optionMohalla = (string) ($scopeOption['mohalla'] ?? '');

    if ($selectedMohalla === '' || strcasecmp($optionMohalla, $selectedMohalla) === 0) {
        $idaraOptions[$optionIdara] = $optionIdara;
    }
    if ($selectedIdara === '' || strcasecmp($optionIdara, $selectedIdara) === 0) {
        $mohallaOptions[$optionMohalla] = $optionMohalla;
    }
}
$idaraOptions = array_values($idaraOptions);
$mohallaOptions = array_values($mohallaOptions);
$findHierarchyLabel = static function (array $options, string $itsId): string {
    if ($itsId === '') {
        return '';
    }

    foreach ($options as $option) {
        if ((string) ($option['its_id'] ?? '') === $itsId) {
            return (string) ($option['member_name'] ?? $itsId);
        }
    }

    return $itsId;
};
$selectedCaptainLabel = $findHierarchyLabel($hierarchyOptions['captains'] ?? [], $selectedCaptainItsId);
$selectedTeamLeaderLabel = $findHierarchyLabel($hierarchyOptions['team_leaders'] ?? [], $selectedTeamLeaderItsId);
$selectedMemberLabel = $findHierarchyLabel($hierarchyOptions['members'] ?? [], $selectedMemberItsId);
$hierarchyFilterDataset = [
    'scope_pairs' => array_values($visibleScopeOptions),
    'captains' => array_values($allHierarchyOptions['captains'] ?? []),
    'team_leaders' => array_values($allHierarchyOptions['team_leaders'] ?? []),
    'members' => array_values($allHierarchyOptions['members'] ?? []),
];
$hierarchyFilterDatasetJson = json_encode($hierarchyFilterDataset, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$taskCommand = 'C:\xampp\php\php.exe C:\xampp\htdocs\bgi_attendance_system\send_monthly_reports.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Reports - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
    <style>
        .monthly-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 16px 18px;
            align-items: end;
        }

        .monthly-filter-grid .filter-field,
        .monthly-send-bar .send-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }

        .monthly-filter-grid .filter-field label,
        .monthly-send-bar .send-field label {
            margin: 0;
            font-size: 0.92rem;
            font-weight: 800;
            color: #214338;
        }

        .monthly-filter-grid .filter-field select {
            width: 100%;
            max-width: none;
            min-width: 0;
        }

        .monthly-filter-grid .filter-actions {
            display: flex;
            align-items: end;
        }

        .monthly-filter-grid .filter-actions .btn {
            width: 100%;
            justify-content: center;
        }

        .monthly-send-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 14px 18px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid rgba(23, 107, 83, 0.12);
        }

        .monthly-send-bar .checkbox-inline {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: #23463a;
        }

        .monthly-send-bar .checkbox-inline input {
            margin: 0;
        }

        .monthly-send-bar .btn {
            min-width: 220px;
            justify-content: center;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 116px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.88rem;
            font-weight: 800;
            background: #edf7f1;
        }

        .status-pill.ontime {
            background: #dcfce7;
            color: #166534;
        }

        .status-pill.late {
            background: #fff3df;
            color: #b45309;
        }

        .status-pill.absent {
            background: #fde9eb;
            color: #842029;
        }

        .status-pill.out-of-kuwait {
            background: #e1f8f5;
            color: #0f766e;
        }

        .code-block {
            margin-top: 14px;
            padding: 14px 16px;
            border-radius: 18px;
            background: #12362d;
            color: #f7f0cf;
            font-family: Consolas, "Courier New", monospace;
            font-size: 0.94rem;
            overflow-x: auto;
        }

        @media (max-width: 760px) {
            .monthly-filter-grid {
                grid-template-columns: 1fr;
            }

            .monthly-send-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .monthly-send-bar .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body class="app-page page-table">
    <div class="page-shell">
        <div class="hero-actions">
            <a href="dashboard.php" class="btn secondary">Back to Dashboard</a>
        </div>

        <section class="report-hero">
            <span class="eyebrow">Monthly Reports</span>
            <h2><?= htmlspecialchars($period['month_label']) ?> Delivery Center</h2>
            <p class="page-intro">Preview the selected month&apos;s event window, review which Captains, Team Leaders, and Members are send-ready, download an individual PDF report, and manually trigger the monthly email summary with PDF attachment when needed.</p>

            <div class="report-meta">
                <span class="meta-pill"><?= htmlspecialchars($scopeLabel) ?></span>
                <span class="meta-pill"><?= htmlspecialchars($roleFilterLabel) ?></span>
                <?php if ($selectedIdara !== ''): ?>
                    <span class="meta-pill">Idara: <?= htmlspecialchars($selectedIdara) ?></span>
                <?php endif; ?>
                <?php if ($selectedMohalla !== ''): ?>
                    <span class="meta-pill">Mohalla: <?= htmlspecialchars($selectedMohalla) ?></span>
                <?php endif; ?>
                <?php if ($selectedCaptainItsId !== ''): ?>
                    <span class="meta-pill">Captain: <?= htmlspecialchars($selectedCaptainLabel) ?></span>
                <?php endif; ?>
                <?php if ($selectedTeamLeaderItsId !== ''): ?>
                    <span class="meta-pill">Team Leader: <?= htmlspecialchars($selectedTeamLeaderLabel) ?></span>
                <?php endif; ?>
                <?php if ($selectedMemberItsId !== ''): ?>
                    <span class="meta-pill">Member: <?= htmlspecialchars($selectedMemberLabel) ?></span>
                <?php endif; ?>
                <span class="meta-pill"><?= count($events) ?> event(s) in period</span>
                <span class="meta-pill"><?= count($recipients) ?> recipient(s) in view</span>
                <span class="meta-pill"><?= $smtpEnabled ? 'SMTP Enabled' : 'SMTP Disabled' ?></span>
            </div>
        </section>

        <?php if ($flashMessage !== ''): ?>
            <div class="flash-message <?= $flashType === 'error' ? 'error' : 'success' ?>">
                <?= htmlspecialchars($flashMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!$smtpEnabled): ?>
            <div class="flash-message error">
                SMTP is currently disabled. You can still preview the monthly recipients below, but email delivery will stay skipped until <?= bgi_can_manage_admins() ? '<a href="smtp_settings.php">SMTP Settings</a>' : 'the super admin enables SMTP settings' ?>.
            </div>
        <?php endif; ?>

        <section class="filter-card">
            <div class="panel-heading">
                <div>
                    <span class="eyebrow">Filters & Send</span>
                    <h3>Choose The Reporting Month</h3>
                    <p>Monthly reports always summarize the selected calendar month. First narrow the scope by Idara and Mohalla, then use the hierarchy filters to focus on one Captain, Team Leader, or Member before sending.</p>
                </div>
            </div>

            <form method="GET" class="monthly-filter-grid">
                <div class="filter-field">
                    <label for="month">Month</label>
                    <select id="month" name="month">
                        <?php for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++): ?>
                            <option value="<?= $monthNumber ?>" <?= $selectedMonth === $monthNumber ? 'selected' : '' ?>>
                                <?= htmlspecialchars(date('F', mktime(0, 0, 0, $monthNumber, 1))) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="year">Year</label>
                    <select id="year" name="year">
                        <?php for ($yearOption = $currentYear; $yearOption >= $currentYear - 5; $yearOption--): ?>
                            <option value="<?= $yearOption ?>" <?= $selectedYear === $yearOption ? 'selected' : '' ?>>
                                <?= $yearOption ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="idara">Idara</label>
                    <select id="idara" name="idara">
                        <option value="">All Idaras</option>
                        <?php foreach ($idaraOptions as $idaraOption): ?>
                            <option value="<?= htmlspecialchars($idaraOption) ?>" <?= $selectedIdara === $idaraOption ? 'selected' : '' ?>>
                                <?= htmlspecialchars($idaraOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="mohalla">Mohalla</label>
                    <select id="mohalla" name="mohalla">
                        <option value="">All Mohallas</option>
                        <?php foreach ($mohallaOptions as $mohallaOption): ?>
                            <option value="<?= htmlspecialchars($mohallaOption) ?>" <?= $selectedMohalla === $mohallaOption ? 'selected' : '' ?>>
                                <?= htmlspecialchars($mohallaOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="recipient_role">Recipient Role</label>
                    <select id="recipient_role" name="recipient_role">
                        <option value="<?= htmlspecialchars(BGI_MONTHLY_REPORT_ROLE_ALL) ?>" <?= $selectedRoleFilter === BGI_MONTHLY_REPORT_ROLE_ALL ? 'selected' : '' ?>>All</option>
                        <option value="<?= htmlspecialchars(BGI_POSITION_CAPTAIN) ?>" <?= $selectedRoleFilter === BGI_POSITION_CAPTAIN ? 'selected' : '' ?>>Captains</option>
                        <option value="<?= htmlspecialchars(BGI_POSITION_TEAM_LEADER) ?>" <?= $selectedRoleFilter === BGI_POSITION_TEAM_LEADER ? 'selected' : '' ?>>Team Leaders</option>
                        <option value="<?= htmlspecialchars(BGI_POSITION_MEMBER) ?>" <?= $selectedRoleFilter === BGI_POSITION_MEMBER ? 'selected' : '' ?>>Members</option>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="captain_its_id">Captain</label>
                    <select id="captain_its_id" name="captain_its_id">
                        <option value="">All Captains</option>
                        <?php foreach (($hierarchyOptions['captains'] ?? []) as $captainOption): ?>
                            <option value="<?= htmlspecialchars((string) ($captainOption['its_id'] ?? '')) ?>" <?= $selectedCaptainItsId === (string) ($captainOption['its_id'] ?? '') ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) ($captainOption['member_name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="team_leader_its_id">Team Leader</label>
                    <select id="team_leader_its_id" name="team_leader_its_id">
                        <option value="">All Team Leaders</option>
                        <?php foreach (($hierarchyOptions['team_leaders'] ?? []) as $teamLeaderOption): ?>
                            <option value="<?= htmlspecialchars((string) ($teamLeaderOption['its_id'] ?? '')) ?>" <?= $selectedTeamLeaderItsId === (string) ($teamLeaderOption['its_id'] ?? '') ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) ($teamLeaderOption['member_name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="member_its_id">Member</label>
                    <select id="member_its_id" name="member_its_id">
                        <option value="">All Members</option>
                        <?php foreach (($hierarchyOptions['members'] ?? []) as $memberOption): ?>
                            <option value="<?= htmlspecialchars((string) ($memberOption['its_id'] ?? '')) ?>" <?= $selectedMemberItsId === (string) ($memberOption['its_id'] ?? '') ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) ($memberOption['member_name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn">Load Monthly Report</button>
                </div>
            </form>

            <p class="small-note">Idara and Mohalla narrow the visible Captain, Team Leader, and Member filters immediately so you only choose from the matching hierarchy.</p>

            <form method="POST" class="monthly-send-bar">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="month" value="<?= (int) $selectedMonth ?>">
                <input type="hidden" name="year" value="<?= (int) $selectedYear ?>">
                <input type="hidden" name="recipient_role" value="<?= htmlspecialchars($selectedRoleFilter) ?>">
                <input type="hidden" name="idara" value="<?= htmlspecialchars($selectedIdara) ?>">
                <input type="hidden" name="mohalla" value="<?= htmlspecialchars($selectedMohalla) ?>">
                <input type="hidden" name="captain_its_id" value="<?= htmlspecialchars($selectedCaptainItsId) ?>">
                <input type="hidden" name="team_leader_its_id" value="<?= htmlspecialchars($selectedTeamLeaderItsId) ?>">
                <input type="hidden" name="member_its_id" value="<?= htmlspecialchars($selectedMemberItsId) ?>">
                <label class="checkbox-inline">
                    <input type="checkbox" name="force_resend" value="1">
                    Force resend even if this month was already sent
                </label>
                <button type="submit" class="btn">Send Monthly Reports Now</button>
            </form>
        </section>

        <div class="summary">
            <div class="summary-card summary-total"><span class="summary-label">Events In Month</span><span class="summary-value"><?= count($events) ?></span></div>
            <div class="summary-card summary-present"><span class="summary-label">Recipients</span><span class="summary-value"><?= count($recipients) ?></span></div>
            <div class="summary-card summary-ontime"><span class="summary-label">Ready Emails</span><span class="summary-value"><?= $readyEmailCount ?></span></div>
            <div class="summary-card summary-late"><span class="summary-label">Already Sent</span><span class="summary-value"><?= $alreadySentCount ?></span></div>
            <div class="summary-card summary-out"><span class="summary-label">Captains</span><span class="summary-value"><?= $captainCount ?></span></div>
            <div class="summary-card summary-time"><span class="summary-label">Team Leaders</span><span class="summary-value"><?= $teamLeaderCount ?></span></div>
            <div class="summary-card summary-present"><span class="summary-label">Members</span><span class="summary-value"><?= $memberCount ?></span></div>
            <div class="summary-card summary-absent"><span class="summary-label">Missing Emails</span><span class="summary-value"><?= $missingEmailCount ?></span></div>
        </div>

        <section class="section-card">
            <div class="panel-heading">
                <div>
                    <span class="eyebrow">Recipient Queue</span>
                    <h3>Monthly Delivery Status By Recipient</h3>
                    <p>Use this table to confirm which monthly report emails are ready, missing an email address, or already delivered for the selected month, and download each PDF before sending if you want to verify the content.</p>
                </div>
            </div>

            <?php if ($recipients !== []): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Idara</th>
                                <th>Mohalla</th>
                                <th>Captain</th>
                                <th>Team Leader</th>
                                <th>Covered Members</th>
                                <th>Status</th>
                                <th>Processed At</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recipients as $recipient): ?>
                                <tr>
                                    <td><?= htmlspecialchars(bgi_member_position_label($recipient['role'] ?? BGI_POSITION_MEMBER)) ?></td>
                                    <td><?= htmlspecialchars($recipient['member_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars((string) ($recipient['email'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($recipient['idara'] ?? BGI_DEFAULT_IDARA) ?></td>
                                    <td><?= htmlspecialchars($recipient['mohalla'] ?? BGI_DEFAULT_MOHALLA) ?></td>
                                    <td><?= htmlspecialchars((string) (($recipient['role'] ?? '') === BGI_POSITION_CAPTAIN ? ($recipient['member_name'] ?? '') : ($recipient['captain_name'] ?? ''))) ?></td>
                                    <td><?= htmlspecialchars((string) (($recipient['role'] ?? '') === BGI_POSITION_TEAM_LEADER ? ($recipient['member_name'] ?? '') : ($recipient['team_leader_name'] ?? ''))) ?></td>
                                    <td><?= (int) ($recipient['covered_members'] ?? 0) ?></td>
                                    <td><span class="status-pill <?= htmlspecialchars($recipient['delivery_class'] ?? 'late') ?>"><?= htmlspecialchars($recipient['delivery_label'] ?? 'Pending') ?></span></td>
                                    <td><?= htmlspecialchars((string) (($recipient['dispatch']['processed_at'] ?? 'Not processed yet'))) ?></td>
                                    <td><?= htmlspecialchars((string) (($recipient['dispatch']['message'] ?? ($recipient['email_ready'] ? 'Ready to send.' : 'Recipient email is missing or invalid.')))) ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="monthly_report_pdf.php?year=<?= (int) $selectedYear ?>&month=<?= (int) $selectedMonth ?>&role=<?= rawurlencode((string) ($recipient['role'] ?? BGI_POSITION_MEMBER)) ?>&its_id=<?= rawurlencode((string) ($recipient['its_id'] ?? '')) ?>" class="link-button">Download PDF</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No monthly-report recipients were found for the selected month and scope or hierarchy filters.</div>
            <?php endif; ?>
        </section>

        <section class="section-card">
            <div class="panel-heading">
                <div>
                    <span class="eyebrow">Event Window</span>
                    <h3>Events Included In This Monthly Report</h3>
                    <p>These are the events from <?= htmlspecialchars($period['range_label']) ?> that will feed the monthly email summary for the visible scope.</p>
                </div>
            </div>

            <?php if ($events !== []): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Event Code</th>
                                <th>Event Name</th>
                                <th>Idara</th>
                                <th>Mohalla</th>
                                <th>Reporting Time</th>
                                <th>Recorded Attendees</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <tr>
                                    <td><?= htmlspecialchars($event['event_date'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($event['event_code'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($event['event_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($event['idara'] ?? BGI_DEFAULT_IDARA) ?></td>
                                    <td><?= htmlspecialchars($event['mohalla'] ?? BGI_DEFAULT_MOHALLA) ?></td>
                                    <td><?= htmlspecialchars($event['reporting_time'] ?? '') ?></td>
                                    <td><?= (int) ($event['recorded_attendees'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No events were found for the selected month and scope.</div>
            <?php endif; ?>
        </section>

        <section class="section-card">
            <div class="panel-heading">
                <div>
                    <span class="eyebrow">Automation</span>
                    <h3>Windows Task Scheduler Command</h3>
                    <p>Schedule this command to run on the 1st of every month. It automatically sends the previous month&apos;s summary with PDF attachment to Captains, Team Leaders, and Members.</p>
                </div>
            </div>
            <div class="code-block"><?= htmlspecialchars($taskCommand) ?></div>
        </section>
    </div>
    <script>
        (function () {
            const data = <?= $hierarchyFilterDatasetJson ?: '{}' ?>;
            const idaraSelect = document.getElementById('idara');
            const mohallaSelect = document.getElementById('mohalla');
            const captainSelect = document.getElementById('captain_its_id');
            const teamLeaderSelect = document.getElementById('team_leader_its_id');
            const memberSelect = document.getElementById('member_its_id');

            if (!idaraSelect || !mohallaSelect || !captainSelect || !teamLeaderSelect || !memberSelect || !data) {
                return;
            }

            const uniqueValues = function (rows, key) {
                const seen = new Set();
                const values = [];

                rows.forEach(function (row) {
                    const value = (row && row[key] ? String(row[key]) : '').trim();
                    if (value !== '' && !seen.has(value)) {
                        seen.add(value);
                        values.push(value);
                    }
                });

                return values.sort(function (left, right) {
                    return left.localeCompare(right);
                });
            };

            const refillSelect = function (select, placeholder, options, currentValue, labelBuilder) {
                select.innerHTML = '';

                const placeholderOption = document.createElement('option');
                placeholderOption.value = '';
                placeholderOption.textContent = placeholder;
                select.appendChild(placeholderOption);

                let matched = false;
                options.forEach(function (option) {
                    const value = String(option.value || '');
                    const optionElement = document.createElement('option');
                    optionElement.value = value;
                    optionElement.textContent = labelBuilder(option);
                    if (currentValue !== '' && currentValue === value) {
                        optionElement.selected = true;
                        matched = true;
                    }
                    select.appendChild(optionElement);
                });

                if (!matched) {
                    select.value = '';
                }
            };

            const applyScopeLists = function () {
                const selectedIdara = idaraSelect.value;
                const selectedMohalla = mohallaSelect.value;
                const scopePairs = Array.isArray(data.scope_pairs) ? data.scope_pairs : [];

                const idaraRows = scopePairs.filter(function (row) {
                    return selectedMohalla === '' || String(row.mohalla || '') === selectedMohalla;
                });
                const mohallaRows = scopePairs.filter(function (row) {
                    return selectedIdara === '' || String(row.idara || '') === selectedIdara;
                });

                const idaraOptions = uniqueValues(idaraRows, 'idara').map(function (value) {
                    return { value: value, label: value };
                });
                const mohallaOptions = uniqueValues(mohallaRows, 'mohalla').map(function (value) {
                    return { value: value, label: value };
                });

                refillSelect(idaraSelect, 'All Idaras', idaraOptions, selectedIdara, function (option) {
                    return option.label;
                });
                refillSelect(mohallaSelect, 'All Mohallas', mohallaOptions, selectedMohalla, function (option) {
                    return option.label;
                });
            };

            const matchesScope = function (row) {
                const selectedIdara = idaraSelect.value;
                const selectedMohalla = mohallaSelect.value;

                if (selectedIdara !== '' && String(row.idara || '') !== selectedIdara) {
                    return false;
                }
                if (selectedMohalla !== '' && String(row.mohalla || '') !== selectedMohalla) {
                    return false;
                }

                return true;
            };

            const applyHierarchyLists = function () {
                const selectedCaptain = captainSelect.value;
                const selectedTeamLeader = teamLeaderSelect.value;

                const captains = (Array.isArray(data.captains) ? data.captains : []).filter(matchesScope);
                refillSelect(captainSelect, 'All Captains', captains.map(function (row) {
                    return {
                        value: String(row.its_id || ''),
                        label: String(row.member_name || ''),
                    };
                }), selectedCaptain, function (option) {
                    return option.label;
                });

                const effectiveCaptain = captainSelect.value;
                const teamLeaders = (Array.isArray(data.team_leaders) ? data.team_leaders : []).filter(matchesScope).filter(function (row) {
                    return effectiveCaptain === '' || String(row.captain_its_id || '') === effectiveCaptain;
                });
                refillSelect(teamLeaderSelect, 'All Team Leaders', teamLeaders.map(function (row) {
                    return {
                        value: String(row.its_id || ''),
                        label: String(row.member_name || ''),
                    };
                }), selectedTeamLeader, function (option) {
                    return option.label;
                });

                const effectiveTeamLeader = teamLeaderSelect.value;
                const members = (Array.isArray(data.members) ? data.members : []).filter(matchesScope).filter(function (row) {
                    if (effectiveCaptain !== '' && String(row.captain_its_id || '') !== effectiveCaptain) {
                        return false;
                    }
                    if (effectiveTeamLeader !== '' && String(row.team_leader_its_id || '') !== effectiveTeamLeader) {
                        return false;
                    }

                    return true;
                });
                refillSelect(memberSelect, 'All Members', members.map(function (row) {
                    return {
                        value: String(row.its_id || ''),
                        label: String(row.member_name || ''),
                    };
                }), memberSelect.value, function (option) {
                    return option.label;
                });
            };

            const refreshHierarchyFilters = function () {
                applyScopeLists();
                applyHierarchyLists();
            };

            idaraSelect.addEventListener('change', refreshHierarchyFilters);
            mohallaSelect.addEventListener('change', refreshHierarchyFilters);
            captainSelect.addEventListener('change', applyHierarchyLists);
            teamLeaderSelect.addEventListener('change', applyHierarchyLists);

            refreshHierarchyFilters();
        }());
    </script>
</body>
</html>
