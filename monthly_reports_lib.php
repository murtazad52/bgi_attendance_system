<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

const BGI_MONTHLY_REPORT_ROLE_ALL = 'all';

function bgi_normalize_monthly_report_role_filter(?string $role): string
{
    $normalizedRole = strtolower(trim((string) $role));

    if ($normalizedRole === BGI_POSITION_MEMBER) {
        return BGI_POSITION_MEMBER;
    }

    if ($normalizedRole === BGI_POSITION_CAPTAIN) {
        return BGI_POSITION_CAPTAIN;
    }

    if ($normalizedRole === BGI_POSITION_TEAM_LEADER) {
        return BGI_POSITION_TEAM_LEADER;
    }

    return BGI_MONTHLY_REPORT_ROLE_ALL;
}

function bgi_monthly_normalize_scope_filters(array $input): array
{
    $normalizeScope = static function ($value): string {
        $normalized = trim((string) $value);
        return $normalized === '' ? '' : bgi_normalize_scope_value($normalized, '');
    };

    return [
        'idara' => $normalizeScope($input['idara'] ?? ''),
        'mohalla' => $normalizeScope($input['mohalla'] ?? ''),
    ];
}

function bgi_monthly_build_effective_scope_filter(array $baseScopeFilter, array $selectedScopeFilters = []): array
{
    $normalizedSelection = bgi_monthly_normalize_scope_filters($selectedScopeFilters);
    $selectedIdara = $normalizedSelection['idara'];
    $selectedMohalla = $normalizedSelection['mohalla'];
    $mode = $baseScopeFilter['mode'] ?? 'all';

    if ($mode === 'pair') {
        return [
            'mode' => 'pair',
            'idara' => bgi_normalize_scope_value($baseScopeFilter['idara'] ?? '', BGI_DEFAULT_IDARA),
            'mohalla' => bgi_normalize_scope_value($baseScopeFilter['mohalla'] ?? '', BGI_DEFAULT_MOHALLA),
        ];
    }

    if ($mode === 'mohalla') {
        $effectiveMohalla = bgi_normalize_scope_value($baseScopeFilter['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);
        if ($selectedIdara !== '') {
            return [
                'mode' => 'pair',
                'idara' => $selectedIdara,
                'mohalla' => $effectiveMohalla,
            ];
        }

        return [
            'mode' => 'mohalla',
            'mohalla' => $effectiveMohalla,
        ];
    }

    if ($selectedIdara !== '' && $selectedMohalla !== '') {
        return [
            'mode' => 'pair',
            'idara' => $selectedIdara,
            'mohalla' => $selectedMohalla,
        ];
    }

    if ($selectedIdara !== '') {
        return [
            'mode' => 'idara',
            'idara' => $selectedIdara,
        ];
    }

    if ($selectedMohalla !== '') {
        return [
            'mode' => 'mohalla',
            'mohalla' => $selectedMohalla,
        ];
    }

    return ['mode' => 'all'];
}

function bgi_monthly_normalize_hierarchy_filters(array $input): array
{
    $normalizeItsId = static function ($value): string {
        $normalized = trim((string) $value);
        return preg_match('/^\d{8}$/', $normalized) ? $normalized : '';
    };

    return [
        'captain_its_id' => $normalizeItsId($input['captain_its_id'] ?? ''),
        'team_leader_its_id' => $normalizeItsId($input['team_leader_its_id'] ?? ''),
        'member_its_id' => $normalizeItsId($input['member_its_id'] ?? ''),
    ];
}

function bgi_monthly_apply_recipient_hierarchy_conditions(array $hierarchyFilters, array &$conditions, string &$types, array &$params): void
{
    $captainItsId = trim((string) ($hierarchyFilters['captain_its_id'] ?? ''));
    $teamLeaderItsId = trim((string) ($hierarchyFilters['team_leader_its_id'] ?? ''));
    $memberItsId = trim((string) ($hierarchyFilters['member_its_id'] ?? ''));

    if ($captainItsId !== '') {
        $conditions[] = "(CASE
            WHEN m.position = 'captain' THEN m.its_id
            WHEN m.position = 'team_leader' THEN m.captain_its_id
            ELSE COALESCE(m.captain_its_id, tl.captain_its_id)
        END) = ?";
        $types .= 's';
        $params[] = $captainItsId;
    }

    if ($teamLeaderItsId !== '') {
        $conditions[] = "(CASE
            WHEN m.position = 'team_leader' THEN m.its_id
            WHEN m.position = 'member' THEN m.team_leader_its_id
            ELSE NULL
        END) = ?";
        $types .= 's';
        $params[] = $teamLeaderItsId;
    }

    if ($memberItsId !== '') {
        $conditions[] = "m.its_id = ?";
        $types .= 's';
        $params[] = $memberItsId;
    }
}

function bgi_monthly_bind_dynamic_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $bindParams = [$types];
    foreach ($params as $key => &$value) {
        $bindParams[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

function bgi_monthly_placeholders(int $count): string
{
    return implode(', ', array_fill(0, max(0, $count), '?'));
}

function bgi_monthly_report_period(?int $year = null, ?int $month = null): array
{
    $periodStart = new DateTimeImmutable('first day of last month');

    if ($year !== null && $year > 0 && $month !== null && $month >= 1 && $month <= 12) {
        $selectedStart = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
        if ($selectedStart instanceof DateTimeImmutable) {
            $periodStart = $selectedStart;
        }
    }

    $periodEnd = $periodStart->modify('last day of this month');

    return [
        'year' => (int) $periodStart->format('Y'),
        'month' => (int) $periodStart->format('n'),
        'month_label' => $periodStart->format('F Y'),
        'start_date' => $periodStart->format('Y-m-d'),
        'end_date' => $periodEnd->format('Y-m-d'),
        'range_label' => $periodStart->format('d M Y') . ' to ' . $periodEnd->format('d M Y'),
        'report_key' => $periodStart->format('Y-m'),
    ];
}

function bgi_monthly_scope_filter_for_current_user(): array
{
    if (bgi_is_super_admin()) {
        return ['mode' => 'all'];
    }

    if (bgi_is_mohalla_admin()) {
        return [
            'mode' => 'mohalla',
            'mohalla' => bgi_current_scope_mohalla(),
        ];
    }

    return [
        'mode' => 'pair',
        'idara' => bgi_current_scope_idara(),
        'mohalla' => bgi_current_scope_mohalla(),
    ];
}

function bgi_monthly_apply_scope_conditions(array $scopeFilter, string $idaraColumn, string $mohallaColumn, array &$conditions, string &$types, array &$params): void
{
    $mode = $scopeFilter['mode'] ?? 'all';
    if ($mode === 'idara') {
        $conditions[] = $idaraColumn . ' = ?';
        $types .= 's';
        $params[] = $scopeFilter['idara'] ?? BGI_DEFAULT_IDARA;
        return;
    }

    if ($mode === 'mohalla') {
        $conditions[] = $mohallaColumn . ' = ?';
        $types .= 's';
        $params[] = $scopeFilter['mohalla'] ?? BGI_DEFAULT_MOHALLA;
        return;
    }

    if ($mode === 'pair') {
        $conditions[] = $idaraColumn . ' = ?';
        $conditions[] = $mohallaColumn . ' = ?';
        $types .= 'ss';
        $params[] = $scopeFilter['idara'] ?? BGI_DEFAULT_IDARA;
        $params[] = $scopeFilter['mohalla'] ?? BGI_DEFAULT_MOHALLA;
    }
}

function bgi_ensure_monthly_report_schema(mysqli $conn): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    $conn->query(
        "CREATE TABLE IF NOT EXISTS monthly_report_dispatch_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            report_year SMALLINT NOT NULL,
            report_month TINYINT NOT NULL,
            recipient_role VARCHAR(40) NOT NULL,
            recipient_its_id VARCHAR(8) NOT NULL,
            recipient_name VARCHAR(255) NOT NULL,
            recipient_email VARCHAR(255) NOT NULL DEFAULT '',
            scope_idara VARCHAR(100) NOT NULL,
            scope_mohalla VARCHAR(100) NOT NULL,
            status VARCHAR(60) NOT NULL,
            message TEXT NULL,
            processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_monthly_report_recipient (report_year, report_month, recipient_role, recipient_its_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function bgi_monthly_dispatch_key(string $role, string $itsId): string
{
    return $role . '||' . $itsId;
}

function bgi_monthly_report_status_label(string $status): string
{
    switch ($status) {
        case 'sent':
            return 'Sent';
        case 'skipped_already_sent':
            return 'Already Sent';
        case 'skipped_missing_email':
            return 'Missing Email';
        case 'skipped_disabled':
            return 'SMTP Disabled';
        case 'failed':
            return 'Failed';
        default:
            return 'Pending';
    }
}

function bgi_monthly_report_status_class(string $status): string
{
    switch ($status) {
        case 'sent':
            return 'ontime';
        case 'skipped_already_sent':
            return 'late';
        case 'skipped_missing_email':
        case 'skipped_disabled':
            return 'out-of-kuwait';
        case 'failed':
            return 'absent';
        default:
            return 'late';
    }
}

function bgi_fetch_monthly_scope_events(mysqli $conn, int $year, int $month, array $scopeFilter): array
{
    $period = bgi_monthly_report_period($year, $month);
    $sql = "
        SELECT
            e.id,
            e.event_code,
            e.event_name,
            e.event_date,
            e.reporting_time,
            e.idara,
            e.mohalla,
            COUNT(DISTINCT a.its_id) AS recorded_attendees
        FROM events e
        LEFT JOIN attendance a ON a.event_id = e.id
        WHERE e.event_date BETWEEN ? AND ?
    ";
    $conditions = [];
    $types = 'ss';
    $params = [$period['start_date'], $period['end_date']];

    bgi_monthly_apply_scope_conditions($scopeFilter, 'e.idara', 'e.mohalla', $conditions, $types, $params);
    if ($conditions !== []) {
        $sql .= ' AND ' . implode(' AND ', $conditions);
    }

    $sql .= "
        GROUP BY e.id, e.event_code, e.event_name, e.event_date, e.reporting_time, e.idara, e.mohalla
        ORDER BY e.event_date ASC, e.reporting_time ASC, e.event_name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    bgi_monthly_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function bgi_fetch_monthly_dispatch_record(mysqli $conn, int $year, int $month, string $role, string $itsId): ?array
{
    bgi_ensure_monthly_report_schema($conn);

    $stmt = $conn->prepare(
        "SELECT report_year, report_month, recipient_role, recipient_its_id, recipient_name, recipient_email, scope_idara, scope_mohalla, status, message, processed_at
         FROM monthly_report_dispatch_log
         WHERE report_year = ? AND report_month = ? AND recipient_role = ? AND recipient_its_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iiss', $year, $month, $role, $itsId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function bgi_fetch_monthly_dispatch_map(mysqli $conn, int $year, int $month, array $scopeFilter, string $roleFilter = BGI_MONTHLY_REPORT_ROLE_ALL): array
{
    bgi_ensure_monthly_report_schema($conn);

    $sql = "
        SELECT report_year, report_month, recipient_role, recipient_its_id, recipient_name, recipient_email, scope_idara, scope_mohalla, status, message, processed_at
        FROM monthly_report_dispatch_log
        WHERE report_year = ? AND report_month = ?
    ";
    $conditions = [];
    $types = 'ii';
    $params = [$year, $month];

    if ($roleFilter !== BGI_MONTHLY_REPORT_ROLE_ALL) {
        $conditions[] = 'recipient_role = ?';
        $types .= 's';
        $params[] = $roleFilter;
    }

    bgi_monthly_apply_scope_conditions($scopeFilter, 'scope_idara', 'scope_mohalla', $conditions, $types, $params);
    if ($conditions !== []) {
        $sql .= ' AND ' . implode(' AND ', $conditions);
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    bgi_monthly_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $map = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $map[bgi_monthly_dispatch_key((string) ($row['recipient_role'] ?? ''), (string) ($row['recipient_its_id'] ?? ''))] = $row;
    }
    $stmt->close();

    return $map;
}

function bgi_fetch_monthly_covered_members(mysqli $conn, array $recipient): array
{
    $recipientRole = bgi_normalize_member_position($recipient['role'] ?? $recipient['position'] ?? BGI_POSITION_MEMBER);
    $recipientItsId = (string) ($recipient['its_id'] ?? '');
    $recipientIdara = bgi_normalize_scope_value($recipient['idara'] ?? '', BGI_DEFAULT_IDARA);
    $recipientMohalla = bgi_normalize_scope_value($recipient['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

    if ($recipientRole === BGI_POSITION_MEMBER) {
        $stmt = $conn->prepare(
            "SELECT its_id, member_name, position, email, team_leader_its_id, captain_its_id
             FROM members
             WHERE its_id = ? AND idara = ? AND mohalla = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('sss', $recipientItsId, $recipientIdara, $recipientMohalla);
    } elseif ($recipientRole === BGI_POSITION_TEAM_LEADER) {
        $stmt = $conn->prepare(
            "SELECT its_id, member_name, position, email, team_leader_its_id, captain_its_id
             FROM members
             WHERE team_leader_its_id = ? AND idara = ? AND mohalla = ?
             ORDER BY member_name ASC"
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('sss', $recipientItsId, $recipientIdara, $recipientMohalla);
    } elseif ($recipientRole === BGI_POSITION_CAPTAIN) {
        $stmt = $conn->prepare(
            "SELECT its_id, member_name, position, email, team_leader_its_id, captain_its_id
             FROM members
             WHERE captain_its_id = ? AND idara = ? AND mohalla = ?
             ORDER BY FIELD(position, 'team_leader', 'member'), member_name ASC"
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('sss', $recipientItsId, $recipientIdara, $recipientMohalla);
    } else {
        return [];
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $row['position'] = bgi_normalize_member_position($row['position'] ?? BGI_POSITION_MEMBER);
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function bgi_fetch_monthly_hierarchy_options(mysqli $conn, array $scopeFilter, array $hierarchyFilters = []): array
{
    $normalizedFilters = bgi_monthly_normalize_hierarchy_filters($hierarchyFilters);
    $captainItsId = $normalizedFilters['captain_its_id'];
    $teamLeaderItsId = $normalizedFilters['team_leader_its_id'];

    $captainsSql = "
        SELECT m.its_id, m.member_name, m.idara, m.mohalla
        FROM members m
        WHERE m.position = ?
    ";
    $captainConditions = [];
    $captainTypes = 's';
    $captainParams = [BGI_POSITION_CAPTAIN];
    bgi_monthly_apply_scope_conditions($scopeFilter, 'm.idara', 'm.mohalla', $captainConditions, $captainTypes, $captainParams);
    if ($captainConditions !== []) {
        $captainsSql .= ' AND ' . implode(' AND ', $captainConditions);
    }
    $captainsSql .= ' ORDER BY m.mohalla ASC, m.idara ASC, m.member_name ASC';

    $captains = [];
    $captainsStmt = $conn->prepare($captainsSql);
    if ($captainsStmt) {
        bgi_monthly_bind_dynamic_params($captainsStmt, $captainTypes, $captainParams);
        $captainsStmt->execute();
        $captainsResult = $captainsStmt->get_result();
        while ($captainsResult && ($row = $captainsResult->fetch_assoc())) {
            $captains[] = $row;
        }
        $captainsStmt->close();
    }

    $teamLeadersSql = "
        SELECT m.its_id, m.member_name, m.idara, m.mohalla, m.captain_its_id, cap.member_name AS captain_name
        FROM members m
        LEFT JOIN members cap ON m.captain_its_id = cap.its_id
        WHERE m.position = ?
    ";
    $teamLeaderConditions = [];
    $teamLeaderTypes = 's';
    $teamLeaderParams = [BGI_POSITION_TEAM_LEADER];
    bgi_monthly_apply_scope_conditions($scopeFilter, 'm.idara', 'm.mohalla', $teamLeaderConditions, $teamLeaderTypes, $teamLeaderParams);
    if ($captainItsId !== '') {
        $teamLeaderConditions[] = 'm.captain_its_id = ?';
        $teamLeaderTypes .= 's';
        $teamLeaderParams[] = $captainItsId;
    }
    if ($teamLeaderConditions !== []) {
        $teamLeadersSql .= ' AND ' . implode(' AND ', $teamLeaderConditions);
    }
    $teamLeadersSql .= ' ORDER BY m.mohalla ASC, m.idara ASC, m.member_name ASC';

    $teamLeaders = [];
    $teamLeadersStmt = $conn->prepare($teamLeadersSql);
    if ($teamLeadersStmt) {
        bgi_monthly_bind_dynamic_params($teamLeadersStmt, $teamLeaderTypes, $teamLeaderParams);
        $teamLeadersStmt->execute();
        $teamLeadersResult = $teamLeadersStmt->get_result();
        while ($teamLeadersResult && ($row = $teamLeadersResult->fetch_assoc())) {
            $teamLeaders[] = $row;
        }
        $teamLeadersStmt->close();
    }

    $membersSql = "
        SELECT
            m.its_id,
            m.member_name,
            m.idara,
            m.mohalla,
            m.team_leader_its_id,
            COALESCE(m.captain_its_id, tl.captain_its_id) AS captain_its_id,
            tl.member_name AS team_leader_name,
            cap.member_name AS captain_name
        FROM members m
        LEFT JOIN members tl ON m.team_leader_its_id = tl.its_id
        LEFT JOIN members cap ON COALESCE(m.captain_its_id, tl.captain_its_id) = cap.its_id
        WHERE m.position = ?
    ";
    $memberConditions = [];
    $memberTypes = 's';
    $memberParams = [BGI_POSITION_MEMBER];
    bgi_monthly_apply_scope_conditions($scopeFilter, 'm.idara', 'm.mohalla', $memberConditions, $memberTypes, $memberParams);
    if ($captainItsId !== '') {
        $memberConditions[] = 'COALESCE(m.captain_its_id, tl.captain_its_id) = ?';
        $memberTypes .= 's';
        $memberParams[] = $captainItsId;
    }
    if ($teamLeaderItsId !== '') {
        $memberConditions[] = 'm.team_leader_its_id = ?';
        $memberTypes .= 's';
        $memberParams[] = $teamLeaderItsId;
    }
    if ($memberConditions !== []) {
        $membersSql .= ' AND ' . implode(' AND ', $memberConditions);
    }
    $membersSql .= ' ORDER BY m.mohalla ASC, m.idara ASC, m.member_name ASC';

    $members = [];
    $membersStmt = $conn->prepare($membersSql);
    if ($membersStmt) {
        bgi_monthly_bind_dynamic_params($membersStmt, $memberTypes, $memberParams);
        $membersStmt->execute();
        $membersResult = $membersStmt->get_result();
        while ($membersResult && ($row = $membersResult->fetch_assoc())) {
            $members[] = $row;
        }
        $membersStmt->close();
    }

    return [
        'captains' => $captains,
        'team_leaders' => $teamLeaders,
        'members' => $members,
    ];
}

function bgi_fetch_monthly_report_recipients(mysqli $conn, int $year, int $month, array $scopeFilter, string $roleFilter = BGI_MONTHLY_REPORT_ROLE_ALL, array $hierarchyFilters = []): array
{
    $normalizedHierarchyFilters = bgi_monthly_normalize_hierarchy_filters($hierarchyFilters);

    if ($roleFilter === BGI_POSITION_CAPTAIN) {
        $positions = [BGI_POSITION_CAPTAIN];
    } elseif ($roleFilter === BGI_POSITION_TEAM_LEADER) {
        $positions = [BGI_POSITION_TEAM_LEADER];
    } elseif ($roleFilter === BGI_POSITION_MEMBER) {
        $positions = [BGI_POSITION_MEMBER];
    } else {
        $positions = [BGI_POSITION_CAPTAIN, BGI_POSITION_TEAM_LEADER, BGI_POSITION_MEMBER];
    }

    $sql = "
        SELECT
            m.its_id,
            m.member_name,
            m.position,
            m.email,
            m.idara,
            m.mohalla,
            m.team_leader_its_id,
            COALESCE(m.captain_its_id, tl.captain_its_id) AS captain_its_id,
            tl.member_name AS team_leader_name,
            cap.member_name AS captain_name
        FROM members m
        LEFT JOIN members tl ON m.team_leader_its_id = tl.its_id
        LEFT JOIN members cap ON COALESCE(m.captain_its_id, tl.captain_its_id) = cap.its_id
        WHERE m.position IN (" . bgi_monthly_placeholders(count($positions)) . ")
    ";
    $conditions = [];
    $types = str_repeat('s', count($positions));
    $params = $positions;

    bgi_monthly_apply_scope_conditions($scopeFilter, 'm.idara', 'm.mohalla', $conditions, $types, $params);
    bgi_monthly_apply_recipient_hierarchy_conditions($normalizedHierarchyFilters, $conditions, $types, $params);
    if ($conditions !== []) {
        $sql .= ' AND ' . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY m.mohalla ASC, m.idara ASC, FIELD(m.position, 'captain', 'team_leader', 'member'), m.member_name ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    bgi_monthly_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $recipients = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $row['role'] = bgi_normalize_member_position($row['position'] ?? BGI_POSITION_MEMBER);
        $coveredMembers = bgi_fetch_monthly_covered_members($conn, $row);
        $row['covered_members'] = count($coveredMembers);
        $row['email_ready'] = filter_var(trim((string) ($row['email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false;
        $recipients[] = $row;
    }
    $stmt->close();

    return $recipients;
}

function bgi_monthly_classify_attendance(?array $attendanceRow, ?string $reportingTime): string
{
    if (!$attendanceRow) {
        return 'absent';
    }

    $storedStatus = trim((string) ($attendanceRow['status'] ?? ''));
    if ($storedStatus === 'Out of Kuwait') {
        return 'out_of_kuwait';
    }
    if ($storedStatus === 'Absent') {
        return 'absent';
    }
    if ($storedStatus === 'Late') {
        return 'late';
    }

    $attendanceTime = $attendanceRow['attendance_time'] ?? null;
    if (!$attendanceTime) {
        return 'absent';
    }

    $attendanceClockTime = date('H:i:s', strtotime((string) $attendanceTime));
    if ($reportingTime !== null && $reportingTime !== '' && strtotime($attendanceClockTime) <= strtotime((string) $reportingTime)) {
        return 'ontime';
    }

    return 'late';
}

function bgi_build_monthly_report_context(mysqli $conn, array $recipient, int $year, int $month): array
{
    $period = bgi_monthly_report_period($year, $month);
    $recipientRole = bgi_normalize_member_position($recipient['role'] ?? $recipient['position'] ?? BGI_POSITION_MEMBER);
    $recipientIdara = bgi_normalize_scope_value($recipient['idara'] ?? '', BGI_DEFAULT_IDARA);
    $recipientMohalla = bgi_normalize_scope_value($recipient['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

    $events = bgi_fetch_monthly_scope_events($conn, $period['year'], $period['month'], [
        'mode' => 'pair',
        'idara' => $recipientIdara,
        'mohalla' => $recipientMohalla,
    ]);
    $coveredMembers = bgi_fetch_monthly_covered_members($conn, $recipient);

    $memberSummaries = [];
    $coveredCounts = [
        'team_leaders' => 0,
        'members' => 0,
        'total' => count($coveredMembers),
    ];

    foreach ($coveredMembers as $memberRow) {
        $memberPosition = bgi_normalize_member_position($memberRow['position'] ?? BGI_POSITION_MEMBER);
        if ($memberPosition === BGI_POSITION_TEAM_LEADER) {
            $coveredCounts['team_leaders']++;
        } else {
            $coveredCounts['members']++;
        }

        $memberSummaries[(string) $memberRow['its_id']] = [
            'its_id' => (string) $memberRow['its_id'],
            'member_name' => (string) ($memberRow['member_name'] ?? ''),
            'position' => $memberPosition,
            'on_time' => 0,
            'late' => 0,
            'out_of_kuwait' => 0,
            'absent' => 0,
            'attendance_rate' => 0,
        ];
    }

    $eventSummaries = [];
    $eventIds = [];
    foreach ($events as $eventRow) {
        $eventId = (int) ($eventRow['id'] ?? 0);
        $eventIds[] = $eventId;
        $eventSummaries[$eventId] = [
            'id' => $eventId,
            'event_code' => (string) ($eventRow['event_code'] ?? ''),
            'event_name' => (string) ($eventRow['event_name'] ?? ''),
            'event_date' => (string) ($eventRow['event_date'] ?? ''),
            'reporting_time' => (string) ($eventRow['reporting_time'] ?? ''),
            'idara' => (string) ($eventRow['idara'] ?? $recipientIdara),
            'mohalla' => (string) ($eventRow['mohalla'] ?? $recipientMohalla),
            'on_time' => 0,
            'late' => 0,
            'out_of_kuwait' => 0,
            'absent' => 0,
        ];
    }

    $attendanceLookup = [];
    if ($eventIds !== [] && $coveredMembers !== []) {
        $memberItsIds = array_keys($memberSummaries);
        $attendanceSql = "
            SELECT event_id, its_id, attendance_time, status, remark
            FROM attendance
            WHERE event_id IN (" . bgi_monthly_placeholders(count($eventIds)) . ")
              AND its_id IN (" . bgi_monthly_placeholders(count($memberItsIds)) . ")
        ";
        $attendanceStmt = $conn->prepare($attendanceSql);
        if ($attendanceStmt) {
            $attendanceTypes = str_repeat('i', count($eventIds)) . str_repeat('s', count($memberItsIds));
            $attendanceParams = array_merge($eventIds, $memberItsIds);
            bgi_monthly_bind_dynamic_params($attendanceStmt, $attendanceTypes, $attendanceParams);
            $attendanceStmt->execute();
            $attendanceResult = $attendanceStmt->get_result();
            while ($attendanceResult && ($attendanceRow = $attendanceResult->fetch_assoc())) {
                $attendanceLookup[(int) ($attendanceRow['event_id'] ?? 0)][(string) ($attendanceRow['its_id'] ?? '')] = $attendanceRow;
            }
            $attendanceStmt->close();
        }
    }

    $overall = [
        'opportunities' => count($events) * count($coveredMembers),
        'on_time' => 0,
        'late' => 0,
        'out_of_kuwait' => 0,
        'absent' => 0,
        'attendance_rate' => 0,
    ];

    foreach ($events as $eventRow) {
        $eventId = (int) ($eventRow['id'] ?? 0);
        $reportingTime = (string) ($eventRow['reporting_time'] ?? '');

        foreach ($memberSummaries as $memberItsId => $memberSummary) {
            $attendanceRow = $attendanceLookup[$eventId][$memberItsId] ?? null;
            $status = bgi_monthly_classify_attendance($attendanceRow, $reportingTime);

            if ($status === 'ontime') {
                $eventSummaries[$eventId]['on_time']++;
                $memberSummaries[$memberItsId]['on_time']++;
                $overall['on_time']++;
            } elseif ($status === 'late') {
                $eventSummaries[$eventId]['late']++;
                $memberSummaries[$memberItsId]['late']++;
                $overall['late']++;
            } elseif ($status === 'out_of_kuwait') {
                $eventSummaries[$eventId]['out_of_kuwait']++;
                $memberSummaries[$memberItsId]['out_of_kuwait']++;
                $overall['out_of_kuwait']++;
            } else {
                $eventSummaries[$eventId]['absent']++;
                $memberSummaries[$memberItsId]['absent']++;
                $overall['absent']++;
            }
        }
    }

    $totalEvents = count($events);
    foreach ($memberSummaries as $memberItsId => $memberSummary) {
        $attendedCount = (int) $memberSummary['on_time'] + (int) $memberSummary['late'];
        $memberSummaries[$memberItsId]['attendance_rate'] = $totalEvents > 0
            ? round(($attendedCount / $totalEvents) * 100, 2)
            : 0;
    }

    if ($overall['opportunities'] > 0) {
        $overall['attendance_rate'] = round((($overall['on_time'] + $overall['late']) / $overall['opportunities']) * 100, 2);
    }

    return [
        'period' => $period,
        'recipient' => [
            'its_id' => (string) ($recipient['its_id'] ?? ''),
            'member_name' => (string) ($recipient['member_name'] ?? ''),
            'role' => $recipientRole,
            'role_label' => bgi_member_position_label($recipientRole),
            'email' => (string) ($recipient['email'] ?? ''),
            'idara' => $recipientIdara,
            'mohalla' => $recipientMohalla,
        ],
        'covered_counts' => $coveredCounts,
        'events' => array_values($eventSummaries),
        'members' => array_values($memberSummaries),
        'overall' => $overall,
    ];
}

function bgi_build_monthly_report_lines(array $context): array
{
    $recipient = $context['recipient'];
    $period = $context['period'];
    $coveredCounts = $context['covered_counts'];
    $overall = $context['overall'];

    $bodyLines = [
        $recipient['role_label'] . ' Monthly Attendance Report',
        '',
        'Period: ' . $period['range_label'],
        'Recipient: ' . ($recipient['member_name'] ?? 'Recipient') . ' (' . $recipient['role_label'] . ')',
        'Scope: ' . ($recipient['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($recipient['mohalla'] ?? BGI_DEFAULT_MOHALLA),
    ];

    if (($recipient['role'] ?? '') === BGI_POSITION_CAPTAIN) {
        $bodyLines[] = 'Team Leaders Covered: ' . (int) ($coveredCounts['team_leaders'] ?? 0);
        $bodyLines[] = 'Members Covered: ' . (int) ($coveredCounts['members'] ?? 0);
        $bodyLines[] = 'Total People Covered: ' . (int) ($coveredCounts['total'] ?? 0);
    } else {
        $bodyLines[] = 'Team Members Covered: ' . (int) ($coveredCounts['total'] ?? 0);
    }

    $bodyLines[] = 'Events Held: ' . count($context['events']);
    $bodyLines[] = 'Attendance Opportunities: ' . (int) ($overall['opportunities'] ?? 0);
    $bodyLines[] = 'Attendance Rate: ' . (float) ($overall['attendance_rate'] ?? 0) . '%';
    $bodyLines[] = '';
    $bodyLines[] = 'Overall Summary';
    $bodyLines[] = 'On Time: ' . (int) ($overall['on_time'] ?? 0);
    $bodyLines[] = 'Late: ' . (int) ($overall['late'] ?? 0);
    $bodyLines[] = 'Out of Kuwait: ' . (int) ($overall['out_of_kuwait'] ?? 0);
    $bodyLines[] = 'Absent: ' . (int) ($overall['absent'] ?? 0);
    $bodyLines[] = '';

    if ($context['events'] === []) {
        $bodyLines[] = 'No events were recorded for this scope during ' . $period['month_label'] . '.';
        $bodyLines[] = '';
    } else {
        $bodyLines[] = 'Event Breakdown';
        foreach ($context['events'] as $event) {
            $eventCode = trim((string) ($event['event_code'] ?? ''));
            $bodyLines[] = sprintf(
                '- %s | %s%s | On Time %d | Late %d | Out of Kuwait %d | Absent %d',
                (string) ($event['event_date'] ?? ''),
                $eventCode !== '' ? $eventCode . ' - ' : '',
                (string) ($event['event_name'] ?? 'Event'),
                (int) ($event['on_time'] ?? 0),
                (int) ($event['late'] ?? 0),
                (int) ($event['out_of_kuwait'] ?? 0),
                (int) ($event['absent'] ?? 0)
            );
        }
        $bodyLines[] = '';
    }

    if ($context['members'] === []) {
        $bodyLines[] = 'No linked members were found for this recipient during the selected month.';
        $bodyLines[] = '';
    } else {
        $bodyLines[] = 'Member Breakdown';
        foreach ($context['members'] as $memberSummary) {
            $bodyLines[] = sprintf(
                '- %s [%s] | On Time %d | Late %d | Out of Kuwait %d | Absent %d | Rate %.2f%%',
                (string) ($memberSummary['member_name'] ?? ''),
                bgi_member_position_label($memberSummary['position'] ?? BGI_POSITION_MEMBER),
                (int) ($memberSummary['on_time'] ?? 0),
                (int) ($memberSummary['late'] ?? 0),
                (int) ($memberSummary['out_of_kuwait'] ?? 0),
                (int) ($memberSummary['absent'] ?? 0),
                (float) ($memberSummary['attendance_rate'] ?? 0)
            );
        }
        $bodyLines[] = '';
    }

    $bodyLines[] = 'This message was generated automatically by the attendance system on ' . date('Y-m-d H:i:s') . '.';

    return $bodyLines;
}

function bgi_format_monthly_report_email(array $context): array
{
    $recipient = $context['recipient'];
    $period = $context['period'];
    $subject = 'Monthly Attendance Report - ' . $period['month_label'] . ' - ' . $recipient['role_label'] . ' - ' . ($recipient['member_name'] ?? 'Recipient');
    $bodyLines = [
        'Please find attached the monthly attendance report PDF for ' . $period['month_label'] . '.',
        '',
        'This message was generated automatically by the attendance system.',
    ];

    return [
        'subject' => $subject,
        'body' => implode("\r\n", $bodyLines),
    ];
}

function bgi_monthly_pdf_escape(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
}

function bgi_monthly_pdf_color(array $rgb, bool $stroke = false): string
{
    $red = max(0, min(255, (int) ($rgb[0] ?? 0))) / 255;
    $green = max(0, min(255, (int) ($rgb[1] ?? 0))) / 255;
    $blue = max(0, min(255, (int) ($rgb[2] ?? 0))) / 255;

    return sprintf('%.3F %.3F %.3F %s', $red, $green, $blue, $stroke ? 'RG' : 'rg');
}

function bgi_monthly_pdf_add_rect(array &$commands, float $x, float $y, float $width, float $height, ?array $fillRgb = null, ?array $strokeRgb = null, float $lineWidth = 1): void
{
    $commands[] = 'q';
    if ($fillRgb !== null) {
        $commands[] = bgi_monthly_pdf_color($fillRgb);
    }
    if ($strokeRgb !== null) {
        $commands[] = bgi_monthly_pdf_color($strokeRgb, true);
        $commands[] = sprintf('%.2F w', $lineWidth);
    }

    $paintOperator = 'S';
    if ($fillRgb !== null && $strokeRgb !== null) {
        $paintOperator = 'B';
    } elseif ($fillRgb !== null) {
        $paintOperator = 'f';
    }

    $commands[] = sprintf('%.2F %.2F %.2F %.2F re %s', $x, $y, $width, $height, $paintOperator);
    $commands[] = 'Q';
}

function bgi_monthly_pdf_text_length(string $text): int
{
    return function_exists('mb_strlen') ? (int) mb_strlen($text) : strlen($text);
}

function bgi_monthly_pdf_estimate_text_width(string $text, int $fontSize = 10, bool $bold = false): float
{
    $characterFactor = $bold ? 0.56 : 0.52;
    return bgi_monthly_pdf_text_length($text) * $fontSize * $characterFactor;
}

function bgi_monthly_pdf_add_text(array &$commands, float $x, float $y, string $text, int $fontSize = 10, array $rgb = [23, 49, 38], bool $bold = false, string $align = 'left', ?float $width = null): void
{
    $fontName = $bold ? 'F2' : 'F1';
    $textX = $x;

    if ($width !== null && $align !== 'left') {
        $estimatedWidth = bgi_monthly_pdf_estimate_text_width($text, $fontSize, $bold);
        if ($align === 'center') {
            $textX = $x + max(0, ($width - $estimatedWidth) / 2);
        } elseif ($align === 'right') {
            $textX = $x + max(0, $width - $estimatedWidth);
        }
    }

    $commands[] = 'BT';
    $commands[] = '/' . $fontName . ' ' . $fontSize . ' Tf';
    $commands[] = bgi_monthly_pdf_color($rgb);
    $commands[] = sprintf('1 0 0 1 %.2F %.2F Tm', $textX, $y);
    $commands[] = '(' . bgi_monthly_pdf_escape($text) . ') Tj';
    $commands[] = 'ET';
}

function bgi_monthly_wrap_pdf_line(string $line, float $width, int $fontSize = 10): array
{
    $normalized = trim(str_replace(["\r\n", "\r"], "\n", $line));
    if ($normalized === '') {
        return [''];
    }

    $maxCharacters = max(10, (int) floor($width / max(1, $fontSize * 0.52)));
    $wrapped = wordwrap($normalized, $maxCharacters, "\n", true);
    return explode("\n", $wrapped);
}

function bgi_monthly_pdf_build_document(array $pageStreams): string
{
    $pageWidth = 612;
    $pageHeight = 792;
    $objects = [];
    $pageObjectNumbers = [];
    $nextObjectNumber = 5;

    foreach ($pageStreams as $stream) {
        $contentObjectNumber = $nextObjectNumber++;
        $pageObjectNumber = $nextObjectNumber++;
        $objects[$contentObjectNumber] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        $objects[$pageObjectNumber] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pageWidth $pageHeight] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $contentObjectNumber 0 R >>";
        $pageObjectNumbers[] = $pageObjectNumber;
    }

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Count ' . count($pageObjectNumbers) . ' /Kids [' . implode(' ', array_map(static function ($pageNumber) {
        return $pageNumber . ' 0 R';
    }, $pageObjectNumbers)) . '] >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $objectNumber => $objectContent) {
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= $objectNumber . " 0 obj\n" . $objectContent . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $maxObjectNumber = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObjectNumber + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($objectNumber = 1; $objectNumber <= $maxObjectNumber; $objectNumber++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectNumber] ?? 0);
    }

    $pdf .= "trailer\n<< /Size " . ($maxObjectNumber + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}

function bgi_generate_constructive_monthly_pdf(array $context): string
{
    $pageWidth = 612;
    $pageHeight = 792;
    $marginX = 36;
    $usableWidth = $pageWidth - ($marginX * 2);
    $bottomMargin = 42;
    $recipient = $context['recipient'];
    $period = $context['period'];
    $overall = $context['overall'];
    $coveredCounts = $context['covered_counts'];
    $events = $context['events'];
    $members = $context['members'];

    usort($members, static function ($left, $right) {
        $leftRate = (float) ($left['attendance_rate'] ?? 0);
        $rightRate = (float) ($right['attendance_rate'] ?? 0);
        if ($leftRate === $rightRate) {
            return strcmp((string) ($left['member_name'] ?? ''), (string) ($right['member_name'] ?? ''));
        }
        return $rightRate <=> $leftRate;
    });

    $reportTitle = 'Monthly Attendance Report';
    $reportSubtitle = $period['month_label'] . ' | ' . ($recipient['role_label'] ?? 'Recipient') . ' | ' . ($recipient['member_name'] ?? 'Recipient');
    $generatedAtLabel = 'Generated ' . date('Y-m-d H:i');

    $pageNumber = 0;
    $currentPage = null;
    $pageStreams = [];

    $startPage = function () use (&$pageNumber, $pageHeight, $marginX, $usableWidth, $reportTitle, $reportSubtitle, $generatedAtLabel) {
        $pageNumber++;
        $commands = [];

        bgi_monthly_pdf_add_rect($commands, $marginX, $pageHeight - 108, $usableWidth, 72, [17, 58, 48], null);
        bgi_monthly_pdf_add_text($commands, $marginX + 16, $pageHeight - 58, bgi_app_name(), 10, [247, 240, 207], true);
        bgi_monthly_pdf_add_text($commands, $marginX + 16, $pageHeight - 80, $reportTitle, 20, [255, 255, 255], true);
        bgi_monthly_pdf_add_text($commands, $marginX + 16, $pageHeight - 97, $reportSubtitle, 10, [226, 246, 236], false);
        bgi_monthly_pdf_add_text($commands, $marginX + $usableWidth - 90, $pageHeight - 58, 'Page ' . $pageNumber, 10, [255, 255, 255], true, 'right', 74);

        bgi_monthly_pdf_add_rect($commands, $marginX, 24, $usableWidth, 1, null, [217, 229, 222], 0.8);
        bgi_monthly_pdf_add_text($commands, $marginX, 12, $generatedAtLabel, 8, [96, 113, 104]);

        return [
            'commands' => $commands,
            'y' => $pageHeight - 132,
        ];
    };

    $finalizePage = function (array $page) use (&$pageStreams) {
        $pageStreams[] = implode("\n", $page['commands']);
    };

    $ensureSpace = function (float $requiredHeight) use (&$currentPage, $bottomMargin, $startPage, $finalizePage) {
        if ($currentPage === null) {
            $currentPage = $startPage();
            return;
        }

        if (($currentPage['y'] - $requiredHeight) < $bottomMargin) {
            $finalizePage($currentPage);
            $currentPage = $startPage();
        }
    };

    $drawSectionHeader = function (string $title, string $description = '') use (&$currentPage, $ensureSpace, $marginX, $usableWidth) {
        $neededHeight = $description !== '' ? 34 : 22;
        $ensureSpace($neededHeight);
        bgi_monthly_pdf_add_rect($currentPage['commands'], $marginX, $currentPage['y'] - 4, 4, 18, [23, 107, 83], null);
        bgi_monthly_pdf_add_text($currentPage['commands'], $marginX + 12, $currentPage['y'], $title, 13, [18, 54, 45], true);
        $currentPage['y'] -= 18;

        if ($description !== '') {
            foreach (bgi_monthly_wrap_pdf_line($description, $usableWidth - 12, 9) as $line) {
                bgi_monthly_pdf_add_text($currentPage['commands'], $marginX + 12, $currentPage['y'], $line, 9, [96, 113, 104]);
                $currentPage['y'] -= 11;
            }
        }

        $currentPage['y'] -= 6;
    };

    $drawInfoBox = function () use (&$currentPage, $ensureSpace, $marginX, $usableWidth, $recipient, $period) {
        $boxHeight = 74;
        $ensureSpace($boxHeight + 10);
        $top = $currentPage['y'];
        $bottom = $top - $boxHeight;

        bgi_monthly_pdf_add_rect($currentPage['commands'], $marginX, $bottom, $usableWidth, $boxHeight, [247, 250, 248], [217, 229, 222], 0.9);

        $items = [
            ['Recipient', (string) ($recipient['member_name'] ?? 'Recipient')],
            ['Role', (string) ($recipient['role_label'] ?? 'Recipient')],
            ['Scope', (string) (($recipient['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($recipient['mohalla'] ?? BGI_DEFAULT_MOHALLA))],
            ['Report Window', (string) ($period['range_label'] ?? '')],
        ];

        $columnWidth = ($usableWidth - 24) / 2;
        $positions = [
            [$marginX + 16, $top - 18],
            [$marginX + 16 + $columnWidth, $top - 18],
            [$marginX + 16, $top - 48],
            [$marginX + 16 + $columnWidth, $top - 48],
        ];

        foreach ($items as $index => $item) {
            [$itemX, $itemY] = $positions[$index];
            bgi_monthly_pdf_add_text($currentPage['commands'], $itemX, $itemY, $item[0], 8, [96, 113, 104], true);
            bgi_monthly_pdf_add_text($currentPage['commands'], $itemX, $itemY - 12, $item[1], 11, [18, 54, 45]);
        }

        $currentPage['y'] = $bottom - 14;
    };

    $drawSummaryCards = function () use (&$currentPage, $ensureSpace, $marginX, $usableWidth, $overall, $coveredCounts, $events) {
        $cards = [
            ['Events Held', (string) count($events), [235, 244, 255], [29, 78, 216]],
            ['Covered People', (string) ($coveredCounts['total'] ?? 0), [236, 253, 245], [22, 163, 74]],
            ['Attendance Rate', number_format((float) ($overall['attendance_rate'] ?? 0), 2) . '%', [236, 254, 255], [8, 145, 178]],
            ['Opportunities', (string) ($overall['opportunities'] ?? 0), [248, 250, 252], [71, 85, 105]],
            ['On Time', (string) ($overall['on_time'] ?? 0), [220, 252, 231], [22, 101, 52]],
            ['Late', (string) ($overall['late'] ?? 0), [255, 243, 223], [180, 83, 9]],
            ['Out of Kuwait', (string) ($overall['out_of_kuwait'] ?? 0), [225, 248, 245], [15, 118, 110]],
            ['Absent', (string) ($overall['absent'] ?? 0), [253, 233, 235], [132, 32, 41]],
        ];

        $cardHeight = 56;
        $gap = 10;
        $cardWidth = ($usableWidth - ($gap * 3)) / 4;
        $ensureSpace(($cardHeight * 2) + $gap + 18);
        $top = $currentPage['y'];

        foreach ($cards as $index => $card) {
            $row = intdiv($index, 4);
            $column = $index % 4;
            $cardX = $marginX + ($column * ($cardWidth + $gap));
            $cardTop = $top - ($row * ($cardHeight + $gap));
            $cardBottom = $cardTop - $cardHeight;

            bgi_monthly_pdf_add_rect($currentPage['commands'], $cardX, $cardBottom, $cardWidth, $cardHeight, $card[2], [217, 229, 222], 0.8);
            bgi_monthly_pdf_add_text($currentPage['commands'], $cardX + 10, $cardTop - 16, $card[0], 8, [96, 113, 104], true);
            bgi_monthly_pdf_add_text($currentPage['commands'], $cardX + 10, $cardTop - 38, $card[1], 16, $card[3], true);
        }

        $currentPage['y'] = $top - ($cardHeight * 2) - $gap - 14;
    };

    $renderTable = function (string $title, string $description, array $columns, array $rows) use (&$currentPage, $ensureSpace, $startPage, $finalizePage, $marginX, $bottomMargin, $drawSectionHeader) {
        $drawSectionHeader($title, $description);

        if ($rows === []) {
            $ensureSpace(38);
            bgi_monthly_pdf_add_rect($currentPage['commands'], $marginX, $currentPage['y'] - 26, 540, 28, [248, 250, 252], [217, 229, 222], 0.8);
            bgi_monthly_pdf_add_text($currentPage['commands'], $marginX + 12, $currentPage['y'] - 16, 'No data was available for this section in the selected month.', 9, [96, 113, 104]);
            $currentPage['y'] -= 40;
            return;
        }

        $drawHeader = function () use (&$currentPage, $ensureSpace, $columns, $marginX) {
            $ensureSpace(26);
            $headerTop = $currentPage['y'];
            $headerHeight = 24;
            $x = $marginX;
            foreach ($columns as $column) {
                $width = (float) ($column['width'] ?? 80);
                bgi_monthly_pdf_add_rect($currentPage['commands'], $x, $headerTop - $headerHeight, $width, $headerHeight, [23, 107, 83], [18, 54, 45], 0.8);
                bgi_monthly_pdf_add_text($currentPage['commands'], $x + 4, $headerTop - 15, (string) ($column['label'] ?? ''), 8, [255, 255, 255], true, $column['align'] ?? 'left', $width - 8);
                $x += $width;
            }
            $currentPage['y'] -= $headerHeight;
        };

        $drawHeader();
        $alternate = false;

        foreach ($rows as $row) {
            $cellLines = [];
            $maxLines = 1;
            foreach ($columns as $index => $column) {
                $cellText = (string) ($row[$index] ?? '');
                $wrappedLines = bgi_monthly_wrap_pdf_line($cellText, max(24, ((float) ($column['width'] ?? 80)) - 10), 8);
                $cellLines[$index] = $wrappedLines;
                $maxLines = max($maxLines, count($wrappedLines));
            }

            $rowHeight = max(22, ($maxLines * 10) + 8);
            if (($currentPage['y'] - $rowHeight) < $bottomMargin) {
                $finalizePage($currentPage);
                $currentPage = $startPage();
                $drawSectionHeader($title . ' (continued)');
                $drawHeader();
            }

            $rowTop = $currentPage['y'];
            $rowBottom = $rowTop - $rowHeight;
            $x = $marginX;
            foreach ($columns as $index => $column) {
                $width = (float) ($column['width'] ?? 80);
                $align = $column['align'] ?? 'left';
                bgi_monthly_pdf_add_rect(
                    $currentPage['commands'],
                    $x,
                    $rowBottom,
                    $width,
                    $rowHeight,
                    $alternate ? [249, 252, 250] : [255, 255, 255],
                    [224, 232, 226],
                    0.7
                );

                $textY = $rowTop - 14;
                foreach ($cellLines[$index] as $lineIndex => $line) {
                    bgi_monthly_pdf_add_text(
                        $currentPage['commands'],
                        $x + 4,
                        $textY - ($lineIndex * 9),
                        $line,
                        8,
                        [23, 49, 38],
                        false,
                        $align,
                        $width - 8
                    );
                }

                $x += $width;
            }

            $currentPage['y'] -= $rowHeight;
            $alternate = !$alternate;
        }

        $currentPage['y'] -= 14;
    };

    $currentPage = $startPage();
    $drawInfoBox();
    $drawSummaryCards();

    $eventRows = [];
    foreach ($events as $event) {
        $eventRows[] = [
            (string) ($event['event_date'] ?? ''),
            (string) ($event['event_code'] ?? ''),
            (string) ($event['event_name'] ?? ''),
            (string) ($event['on_time'] ?? 0),
            (string) ($event['late'] ?? 0),
            (string) ($event['out_of_kuwait'] ?? 0),
            (string) ($event['absent'] ?? 0),
        ];
    }

    $memberRows = [];
    foreach ($members as $memberSummary) {
        $memberRows[] = [
            (string) ($memberSummary['member_name'] ?? '') . ' (' . (string) ($memberSummary['its_id'] ?? '') . ')',
            bgi_member_position_label($memberSummary['position'] ?? BGI_POSITION_MEMBER),
            (string) ($memberSummary['on_time'] ?? 0),
            (string) ($memberSummary['late'] ?? 0),
            (string) ($memberSummary['out_of_kuwait'] ?? 0),
            (string) ($memberSummary['absent'] ?? 0),
            number_format((float) ($memberSummary['attendance_rate'] ?? 0), 2) . '%',
        ];
    }

    $renderTable(
        'Event Breakdown',
        'A clean month-level view of every event included in this report window.',
        [
            ['label' => 'Date', 'width' => 62, 'align' => 'left'],
            ['label' => 'Code', 'width' => 74, 'align' => 'left'],
            ['label' => 'Event Name', 'width' => 182, 'align' => 'left'],
            ['label' => 'On Time', 'width' => 52, 'align' => 'center'],
            ['label' => 'Late', 'width' => 42, 'align' => 'center'],
            ['label' => 'Out of Kuwait', 'width' => 70, 'align' => 'center'],
            ['label' => 'Absent', 'width' => 58, 'align' => 'center'],
        ],
        $eventRows
    );

    $renderTable(
        'Member Breakdown',
        'Constructive monthly summary for each linked Team Leader or Member, sorted by attendance rate.',
        [
            ['label' => 'Member', 'width' => 210, 'align' => 'left'],
            ['label' => 'Position', 'width' => 72, 'align' => 'left'],
            ['label' => 'On Time', 'width' => 48, 'align' => 'center'],
            ['label' => 'Late', 'width' => 40, 'align' => 'center'],
            ['label' => 'Out of Kuwait', 'width' => 64, 'align' => 'center'],
            ['label' => 'Absent', 'width' => 50, 'align' => 'center'],
            ['label' => 'Rate', 'width' => 56, 'align' => 'center'],
        ],
        $memberRows
    );

    if ($currentPage !== null) {
        $finalizePage($currentPage);
    }

    return bgi_monthly_pdf_build_document($pageStreams);
}

function bgi_build_monthly_report_pdf(array $context): array
{
    $recipient = $context['recipient'];
    $period = $context['period'];
    $filenameBase = strtolower(trim((string) ($recipient['member_name'] ?? 'recipient')));
    $filenameBase = preg_replace('/[^a-z0-9]+/', '_', $filenameBase);
    $filenameBase = trim((string) $filenameBase, '_');
    if ($filenameBase === '') {
        $filenameBase = 'recipient';
    }

    return [
        'filename' => 'monthly_report_' . $period['report_key'] . '_' . $filenameBase . '.pdf',
        'mime_type' => 'application/pdf',
        'content' => bgi_generate_constructive_monthly_pdf($context),
    ];
}

function bgi_record_monthly_report_dispatch(mysqli $conn, int $year, int $month, array $recipient, string $status, string $message = ''): bool
{
    bgi_ensure_monthly_report_schema($conn);

    $recipientRole = bgi_normalize_member_position($recipient['role'] ?? $recipient['position'] ?? BGI_POSITION_MEMBER);
    $recipientItsId = (string) ($recipient['its_id'] ?? '');
    $recipientName = (string) ($recipient['member_name'] ?? '');
    $recipientEmail = (string) ($recipient['email'] ?? '');
    $scopeIdara = bgi_normalize_scope_value($recipient['idara'] ?? '', BGI_DEFAULT_IDARA);
    $scopeMohalla = bgi_normalize_scope_value($recipient['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

    $stmt = $conn->prepare(
        "INSERT INTO monthly_report_dispatch_log (
            report_year, report_month, recipient_role, recipient_its_id, recipient_name, recipient_email, scope_idara, scope_mohalla, status, message, processed_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            recipient_name = VALUES(recipient_name),
            recipient_email = VALUES(recipient_email),
            scope_idara = VALUES(scope_idara),
            scope_mohalla = VALUES(scope_mohalla),
            status = VALUES(status),
            message = VALUES(message),
            processed_at = VALUES(processed_at)"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'iissssssss',
        $year,
        $month,
        $recipientRole,
        $recipientItsId,
        $recipientName,
        $recipientEmail,
        $scopeIdara,
        $scopeMohalla,
        $status,
        $message
    );
    $saved = $stmt->execute();
    $stmt->close();

    return $saved;
}

function bgi_send_monthly_report_for_recipient(mysqli $conn, array $recipient, int $year, int $month, bool $force = false): array
{
    $recipientRole = bgi_normalize_member_position($recipient['role'] ?? $recipient['position'] ?? BGI_POSITION_MEMBER);
    $recipientItsId = (string) ($recipient['its_id'] ?? '');
    $recipientEmail = trim((string) ($recipient['email'] ?? ''));
    $existingDispatch = bgi_fetch_monthly_dispatch_record($conn, $year, $month, $recipientRole, $recipientItsId);

    if (!$force && $existingDispatch && ($existingDispatch['status'] ?? '') === 'sent') {
        return [
            'recipient' => $recipient,
            'status' => 'skipped_already_sent',
            'message' => 'Already sent on ' . (string) ($existingDispatch['processed_at'] ?? ''),
        ];
    }

    if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $message = 'Recipient email is missing or invalid.';
        bgi_record_monthly_report_dispatch($conn, $year, $month, $recipient, 'skipped_missing_email', $message);
        return [
            'recipient' => $recipient,
            'status' => 'skipped_missing_email',
            'message' => $message,
        ];
    }

    $smtpConfig = bgi_load_smtp_config();
    if (empty($smtpConfig['enabled'])) {
        $message = 'SMTP is disabled.';
        bgi_record_monthly_report_dispatch($conn, $year, $month, $recipient, 'skipped_disabled', $message);
        return [
            'recipient' => $recipient,
            'status' => 'skipped_disabled',
            'message' => $message,
        ];
    }

    $context = bgi_build_monthly_report_context($conn, $recipient, $year, $month);
    $emailPayload = bgi_format_monthly_report_email($context);
    $pdfAttachment = bgi_build_monthly_report_pdf($context);
    $sent = bgi_send_smtp_message([[
        'email' => $recipientEmail,
        'name' => (string) ($recipient['member_name'] ?? ''),
    ]], $emailPayload['subject'], $emailPayload['body'], $smtpError, [$pdfAttachment]);

    if ($sent) {
        $message = 'Monthly report sent successfully.';
        bgi_record_monthly_report_dispatch($conn, $year, $month, $recipient, 'sent', $message);
        return [
            'recipient' => $recipient,
            'status' => 'sent',
            'message' => $message,
            'context' => $context,
        ];
    }

    $message = 'SMTP error: ' . ($smtpError ?: 'Unknown SMTP error.');
    bgi_record_monthly_report_dispatch($conn, $year, $month, $recipient, 'failed', $message);
    return [
        'recipient' => $recipient,
        'status' => 'failed',
        'message' => $message,
        'context' => $context,
    ];
}

function bgi_send_monthly_reports(mysqli $conn, int $year, int $month, array $scopeFilter, string $roleFilter = BGI_MONTHLY_REPORT_ROLE_ALL, bool $force = false, array $hierarchyFilters = []): array
{
    $period = bgi_monthly_report_period($year, $month);
    $recipients = bgi_fetch_monthly_report_recipients($conn, $period['year'], $period['month'], $scopeFilter, $roleFilter, $hierarchyFilters);
    $results = [];
    $summary = [
        'period' => $period,
        'total' => count($recipients),
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'already_sent' => 0,
        'missing_email' => 0,
        'smtp_disabled' => 0,
        'results' => [],
    ];

    foreach ($recipients as $recipient) {
        $result = bgi_send_monthly_report_for_recipient($conn, $recipient, $period['year'], $period['month'], $force);
        $results[] = $result;

        if (($result['status'] ?? '') === 'sent') {
            $summary['sent']++;
        } elseif (($result['status'] ?? '') === 'failed') {
            $summary['failed']++;
        } else {
            $summary['skipped']++;
            if (($result['status'] ?? '') === 'skipped_already_sent') {
                $summary['already_sent']++;
            } elseif (($result['status'] ?? '') === 'skipped_missing_email') {
                $summary['missing_email']++;
            } elseif (($result['status'] ?? '') === 'skipped_disabled') {
                $summary['smtp_disabled']++;
            }
        }
    }

    $summary['results'] = $results;

    return $summary;
}
