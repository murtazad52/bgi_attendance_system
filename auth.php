<?php
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const BGI_ROLE_SUPER_ADMIN = 'super_admin';
const BGI_ROLE_IDARA_ATTENDANCE_ADMIN = 'idara_attendance_admin';
const BGI_ROLE_IDARA_ADMIN = 'idara_admin';
const BGI_ROLE_ADMIN = 'idara_admin';
const BGI_ROLE_MOHALLA_ADMIN = 'mohalla_admin';
const BGI_ROLE_MEMBER = 'member';
const BGI_POSITION_MEMBER = 'member';
const BGI_POSITION_TEAM_LEADER = 'team_leader';
const BGI_POSITION_CAPTAIN = 'captain';
const BGI_DEFAULT_IDARA = 'BGI';
const BGI_DEFAULT_MOHALLA = 'Badri';
const BGI_SUPER_ADMIN_IDARA = 'All Idara';
const BGI_SUPER_ADMIN_MOHALLA = 'All Mohalla';

function bgi_app_name(): string
{
    if (bgi_is_logged_in() && !bgi_is_super_admin()) {
        return bgi_current_scope_idara() . '-' . bgi_current_scope_mohalla() . ' Attendance System';
    }

    return 'Idara-Mohalla Attendance System';
}

function bgi_is_valid_admin_role(string $role): bool
{
    return in_array($role, [
        BGI_ROLE_SUPER_ADMIN,
        BGI_ROLE_IDARA_ATTENDANCE_ADMIN,
        BGI_ROLE_IDARA_ADMIN,
        BGI_ROLE_MOHALLA_ADMIN,
    ], true);
}

function bgi_normalize_admin_role(?string $role): string
{
    $normalizedRole = strtolower(trim((string) $role));

    if ($normalizedRole === BGI_ROLE_SUPER_ADMIN) {
        return BGI_ROLE_SUPER_ADMIN;
    }

    if ($normalizedRole === BGI_ROLE_MOHALLA_ADMIN) {
        return BGI_ROLE_MOHALLA_ADMIN;
    }

    if ($normalizedRole === BGI_ROLE_IDARA_ATTENDANCE_ADMIN) {
        return BGI_ROLE_IDARA_ATTENDANCE_ADMIN;
    }

    if ($normalizedRole === 'admin' || $normalizedRole === BGI_ROLE_IDARA_ADMIN) {
        return BGI_ROLE_IDARA_ADMIN;
    }

    return BGI_ROLE_IDARA_ADMIN;
}

function bgi_role_label(?string $role): string
{
    $normalizedRole = bgi_normalize_admin_role($role);

    if ($normalizedRole === BGI_ROLE_SUPER_ADMIN) {
        return 'Super Admin';
    }

    if ($normalizedRole === BGI_ROLE_MOHALLA_ADMIN) {
        return 'Mohalla Admin';
    }

    if ($normalizedRole === BGI_ROLE_IDARA_ATTENDANCE_ADMIN) {
        return 'Idara Attendance Admin';
    }

    return 'Idara Admin';
}

function bgi_staff_roles(): array
{
    return [
        BGI_ROLE_SUPER_ADMIN,
        BGI_ROLE_IDARA_ATTENDANCE_ADMIN,
        BGI_ROLE_IDARA_ADMIN,
        BGI_ROLE_MOHALLA_ADMIN,
    ];
}

function bgi_member_positions(): array
{
    return [
        BGI_POSITION_MEMBER,
        BGI_POSITION_TEAM_LEADER,
        BGI_POSITION_CAPTAIN,
    ];
}

function bgi_normalize_member_position(?string $position): string
{
    $normalizedPosition = strtolower(trim((string) $position));

    if ($normalizedPosition === BGI_POSITION_TEAM_LEADER) {
        return BGI_POSITION_TEAM_LEADER;
    }

    if ($normalizedPosition === BGI_POSITION_CAPTAIN) {
        return BGI_POSITION_CAPTAIN;
    }

    return BGI_POSITION_MEMBER;
}

function bgi_member_position_label(?string $position): string
{
    $normalizedPosition = bgi_normalize_member_position($position);

    if ($normalizedPosition === BGI_POSITION_TEAM_LEADER) {
        return 'Team Leader';
    }

    if ($normalizedPosition === BGI_POSITION_CAPTAIN) {
        return 'Captain';
    }

    return 'Member';
}

function bgi_normalize_scope_value(?string $value, string $fallback = ''): string
{
    $normalized = preg_replace('/\s+/', ' ', trim((string) $value));
    return $normalized === '' ? $fallback : $normalized;
}

function bgi_sql_string(mysqli $conn, string $value): string
{
    return "'" . $conn->real_escape_string($value) . "'";
}

function bgi_ensure_table_column(mysqli $conn, string $table, string $column, string $definition): void
{
    $columnName = $conn->real_escape_string($column);
    $columnResult = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$columnName'");
    if ($columnResult && $columnResult->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function bgi_ensure_admin_role_schema(mysqli $conn): void
{
    $columnResult = $conn->query("SHOW COLUMNS FROM admin_users LIKE 'role'");
    if (!$columnResult) {
        return;
    }

    if ($columnResult->num_rows === 0) {
        $conn->query("ALTER TABLE admin_users ADD COLUMN role VARCHAR(40) NOT NULL DEFAULT 'idara_admin' AFTER password");
    }

    $conn->query(
        "UPDATE admin_users
         SET role = 'idara_admin'
         WHERE role IS NULL
            OR TRIM(role) = ''
            OR role = 'admin'
            OR role NOT IN ('super_admin', 'idara_admin', 'mohalla_admin', 'idara_attendance_admin')"
    );
}

function bgi_ensure_scope_map_schema(mysqli $conn): void
{
    $defaultIdara = bgi_sql_string($conn, BGI_DEFAULT_IDARA);
    $defaultMohalla = bgi_sql_string($conn, BGI_DEFAULT_MOHALLA);

    $conn->query(
        "CREATE TABLE IF NOT EXISTS idara_mohalla_map (
            idara VARCHAR(100) NOT NULL,
            mohalla VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (idara, mohalla)
        )"
    );

    $primaryColumns = [];
    $indexResult = $conn->query("SHOW INDEX FROM idara_mohalla_map WHERE Key_name = 'PRIMARY'");
    if ($indexResult) {
        while ($row = $indexResult->fetch_assoc()) {
            $primaryColumns[(int) ($row['Seq_in_index'] ?? 0)] = $row['Column_name'] ?? '';
        }
    }

    ksort($primaryColumns);
    $primaryColumns = array_values(array_filter($primaryColumns));

    if ($primaryColumns !== ['idara', 'mohalla']) {
        if ($primaryColumns !== []) {
            $conn->query("ALTER TABLE idara_mohalla_map DROP PRIMARY KEY");
        }

        $conn->query("ALTER TABLE idara_mohalla_map ADD PRIMARY KEY (idara, mohalla)");
    }

    $conn->query(
        "INSERT IGNORE INTO idara_mohalla_map (idara, mohalla)
         VALUES ($defaultIdara, $defaultMohalla)"
    );
}

function bgi_register_scope(mysqli $conn, string $idara, string $mohalla, ?string &$error = null): bool
{
    $error = null;
    $idara = bgi_normalize_scope_value($idara, BGI_DEFAULT_IDARA);
    $mohalla = bgi_normalize_scope_value($mohalla, BGI_DEFAULT_MOHALLA);

    if ($idara === '' || $mohalla === '') {
        $error = 'Idara and Mohalla are required.';
        return false;
    }

    bgi_ensure_scope_map_schema($conn);

    $lookupStmt = $conn->prepare("SELECT 1 FROM idara_mohalla_map WHERE idara = ? AND mohalla = ? LIMIT 1");
    if (!$lookupStmt) {
        $error = 'Unable to validate the Idara and Mohalla mapping right now.';
        return false;
    }

    $lookupStmt->bind_param("ss", $idara, $mohalla);
    $lookupStmt->execute();
    $existingScope = $lookupStmt->get_result()->fetch_assoc();
    $lookupStmt->close();

    if ($existingScope) {
        return true;
    }

    $insertStmt = $conn->prepare("INSERT INTO idara_mohalla_map (idara, mohalla) VALUES (?, ?)");
    if (!$insertStmt) {
        $error = 'Unable to save the Idara and Mohalla mapping right now.';
        return false;
    }

    $insertStmt->bind_param("ss", $idara, $mohalla);
    $saved = $insertStmt->execute();
    $insertError = $insertStmt->errno;
    $insertStmt->close();

    if (!$saved && $insertError === 1062) {
        return true;
    }

    if (!$saved) {
        $error = 'Unable to save the Idara and Mohalla mapping right now.';
    }

    return $saved;
}

function bgi_get_scope_options(mysqli $conn): array
{
    bgi_ensure_scope_map_schema($conn);

    $options = [];
    $result = $conn->query("SELECT idara, mohalla FROM idara_mohalla_map ORDER BY mohalla ASC, idara ASC");
    if (!$result) {
        return $options;
    }

    while ($row = $result->fetch_assoc()) {
        $options[] = [
            'idara' => bgi_normalize_scope_value($row['idara'] ?? '', BGI_DEFAULT_IDARA),
            'mohalla' => bgi_normalize_scope_value($row['mohalla'] ?? '', BGI_DEFAULT_MOHALLA),
        ];
    }

    return $options;
}

function bgi_ensure_event_code_schema(mysqli $conn): void
{
    bgi_ensure_table_column($conn, 'events', 'event_code', "VARCHAR(40) NULL AFTER event_name");

    $missingCodes = $conn->query("SELECT id, event_date FROM events WHERE event_code IS NULL OR TRIM(event_code) = '' ORDER BY id ASC");
    if ($missingCodes) {
        while ($row = $missingCodes->fetch_assoc()) {
            $eventId = (int) ($row['id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }

            $datePart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['event_date'] ?? ''))
                ? str_replace('-', '', (string) $row['event_date'])
                : date('Ymd');

            $candidate = 'EVT-' . $datePart . '-' . str_pad((string) $eventId, 4, '0', STR_PAD_LEFT);
            $suffix = 1;

            while (true) {
                $codeStmt = $conn->prepare("SELECT id FROM events WHERE event_code = ? AND id <> ? LIMIT 1");
                if (!$codeStmt) {
                    break;
                }

                $codeStmt->bind_param("si", $candidate, $eventId);
                $codeStmt->execute();
                $exists = $codeStmt->get_result()->num_rows > 0;
                $codeStmt->close();

                if (!$exists) {
                    break;
                }

                $candidate = 'EVT-' . $datePart . '-' . str_pad((string) $eventId, 4, '0', STR_PAD_LEFT) . '-' . $suffix;
                $suffix++;
            }

            $updateStmt = $conn->prepare("UPDATE events SET event_code = ? WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("si", $candidate, $eventId);
                $updateStmt->execute();
                $updateStmt->close();
            }
        }
    }

    $indexResult = $conn->query("SHOW INDEX FROM events WHERE Key_name = 'uniq_events_event_code'");
    if ($indexResult && $indexResult->num_rows === 0) {
        $conn->query("ALTER TABLE events ADD UNIQUE KEY uniq_events_event_code (event_code)");
    }
}

function bgi_generate_event_code(mysqli $conn, ?string $eventDate = null): string
{
    bgi_ensure_event_code_schema($conn);

    $datePart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $eventDate)
        ? str_replace('-', '', (string) $eventDate)
        : date('Ymd');

    do {
        $randomPart = strtoupper(bin2hex(random_bytes(3)));
        $candidate = 'EVT-' . $datePart . '-' . $randomPart;
        $codeStmt = $conn->prepare("SELECT 1 FROM events WHERE event_code = ? LIMIT 1");
        if (!$codeStmt) {
            return $candidate;
        }

        $codeStmt->bind_param("s", $candidate);
        $codeStmt->execute();
        $exists = $codeStmt->get_result()->num_rows > 0;
        $codeStmt->close();
    } while ($exists);

    return $candidate;
}

function bgi_bootstrap_access_schema(mysqli $conn): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    bgi_ensure_admin_role_schema($conn);
    bgi_ensure_scope_map_schema($conn);
    bgi_ensure_event_code_schema($conn);

    $defaultIdaraSql = bgi_sql_string($conn, BGI_DEFAULT_IDARA);
    $defaultMohallaSql = bgi_sql_string($conn, BGI_DEFAULT_MOHALLA);
    $superAdminIdaraSql = bgi_sql_string($conn, BGI_SUPER_ADMIN_IDARA);
    $superAdminMohallaSql = bgi_sql_string($conn, BGI_SUPER_ADMIN_MOHALLA);

    bgi_ensure_table_column($conn, 'members', 'idara', "VARCHAR(100) NOT NULL DEFAULT 'BGI' AFTER bgi_id");
    bgi_ensure_table_column($conn, 'members', 'mohalla', "VARCHAR(100) NOT NULL DEFAULT 'Badri' AFTER idara");
    bgi_ensure_table_column($conn, 'members', 'position', "VARCHAR(40) NOT NULL DEFAULT 'member' AFTER member_name");
    bgi_ensure_table_column($conn, 'members', 'team_leader_its_id', "VARCHAR(8) NULL DEFAULT NULL AFTER position");
    bgi_ensure_table_column($conn, 'members', 'captain_its_id', "VARCHAR(8) NULL DEFAULT NULL AFTER team_leader_its_id");
    bgi_ensure_table_column($conn, 'admin_users', 'idara', "VARCHAR(100) NOT NULL DEFAULT 'BGI' AFTER role");
    bgi_ensure_table_column($conn, 'admin_users', 'mohalla', "VARCHAR(100) NOT NULL DEFAULT 'Badri' AFTER idara");
    bgi_ensure_table_column($conn, 'events', 'idara', "VARCHAR(100) NOT NULL DEFAULT 'BGI' AFTER event_name");
    bgi_ensure_table_column($conn, 'events', 'mohalla', "VARCHAR(100) NOT NULL DEFAULT 'Badri' AFTER idara");
    bgi_ensure_table_column($conn, 'attendance', 'idara', "VARCHAR(100) NULL DEFAULT NULL AFTER bgi_id");
    bgi_ensure_table_column($conn, 'attendance', 'mohalla', "VARCHAR(100) NULL DEFAULT NULL AFTER idara");

    $conn->query("UPDATE admin_users SET role = 'super_admin' WHERE username = 'admin'");
    $conn->query("UPDATE admin_users SET role = 'idara_admin' WHERE username <> 'admin' AND role = 'admin'");

    $conn->query("UPDATE members SET idara = $defaultIdaraSql WHERE idara IS NULL OR TRIM(idara) = ''");
    $conn->query("UPDATE members SET mohalla = $defaultMohallaSql WHERE mohalla IS NULL OR TRIM(mohalla) = ''");
    $conn->query(
        "UPDATE members
         SET position = 'member'
         WHERE position IS NULL
            OR TRIM(position) = ''
            OR LOWER(TRIM(position)) NOT IN ('member', 'team_leader', 'captain')"
    );
    $conn->query("UPDATE members SET team_leader_its_id = NULL WHERE team_leader_its_id IS NOT NULL AND TRIM(team_leader_its_id) = ''");
    $conn->query("UPDATE members SET captain_its_id = NULL WHERE captain_its_id IS NOT NULL AND TRIM(captain_its_id) = ''");
    $conn->query("UPDATE admin_users SET idara = $defaultIdaraSql WHERE username <> 'admin' AND (idara IS NULL OR TRIM(idara) = '')");
    $conn->query("UPDATE admin_users SET mohalla = $defaultMohallaSql WHERE username <> 'admin' AND (mohalla IS NULL OR TRIM(mohalla) = '')");
    $conn->query("UPDATE admin_users SET idara = $superAdminIdaraSql, mohalla = $superAdminMohallaSql WHERE username = 'admin'");
    $conn->query("UPDATE events SET idara = $defaultIdaraSql WHERE idara IS NULL OR TRIM(idara) = ''");
    $conn->query("UPDATE events SET mohalla = $defaultMohallaSql WHERE mohalla IS NULL OR TRIM(mohalla) = ''");

    $conn->query(
        "UPDATE attendance a
         LEFT JOIN members m ON a.its_id = m.its_id
         LEFT JOIN events e ON a.event_id = e.id
         SET a.idara = COALESCE(NULLIF(a.idara, ''), NULLIF(m.idara, ''), NULLIF(e.idara, ''), $defaultIdaraSql),
             a.mohalla = COALESCE(NULLIF(a.mohalla, ''), NULLIF(m.mohalla, ''), NULLIF(e.mohalla, ''), $defaultMohallaSql)
         WHERE a.idara IS NULL OR TRIM(a.idara) = '' OR a.mohalla IS NULL OR TRIM(a.mohalla) = ''"
    );

    $scopeSources = [
        "SELECT DISTINCT idara, mohalla FROM members",
        "SELECT DISTINCT idara, mohalla FROM admin_users WHERE role NOT IN ('super_admin', 'mohalla_admin') AND idara <> 'All Idara'",
        "SELECT DISTINCT idara, mohalla FROM events",
        "SELECT DISTINCT idara, mohalla FROM attendance",
    ];

    foreach ($scopeSources as $sql) {
        $result = $conn->query($sql);
        if (!$result) {
            continue;
        }

        while ($row = $result->fetch_assoc()) {
            $scopeError = null;
            bgi_register_scope(
                $conn,
                bgi_normalize_scope_value($row['idara'] ?? '', BGI_DEFAULT_IDARA),
                bgi_normalize_scope_value($row['mohalla'] ?? '', BGI_DEFAULT_MOHALLA),
                $scopeError
            );
        }
    }
}

function bgi_clear_auth_session(): void
{
    $keys = [
        'user_id',
        'username',
        'user_role',
        'admin_logged_in',
        'member_logged_in',
        'member_id',
        'member_name',
        'member_its_id',
        'member_position',
        'scope_idara',
        'scope_mohalla',
    ];

    foreach ($keys as $key) {
        unset($_SESSION[$key]);
    }
}

function bgi_set_scope_session(?string $idara, ?string $mohalla): void
{
    $_SESSION['scope_idara'] = bgi_normalize_scope_value($idara, BGI_DEFAULT_IDARA);
    $_SESSION['scope_mohalla'] = bgi_normalize_scope_value($mohalla, BGI_DEFAULT_MOHALLA);
}

function bgi_current_user_role(): string
{
    $role = $_SESSION['user_role'] ?? '';

    if ($role === BGI_ROLE_MEMBER) {
        return BGI_ROLE_MEMBER;
    }

    if ($role !== '') {
        return bgi_normalize_admin_role($role);
    }

    if (!empty($_SESSION['admin_logged_in'])) {
        return BGI_ROLE_SUPER_ADMIN;
    }

    return '';
}

function bgi_is_logged_in(): bool
{
    return bgi_current_user_role() !== '';
}

function bgi_is_staff(): bool
{
    return in_array(bgi_current_user_role(), bgi_staff_roles(), true);
}

function bgi_is_member(): bool
{
    return bgi_current_user_role() === BGI_ROLE_MEMBER;
}

function bgi_is_super_admin(): bool
{
    return bgi_current_user_role() === BGI_ROLE_SUPER_ADMIN;
}

function bgi_is_idara_admin(): bool
{
    return bgi_current_user_role() === BGI_ROLE_IDARA_ADMIN;
}

function bgi_is_idara_attendance_admin(): bool
{
    return bgi_current_user_role() === BGI_ROLE_IDARA_ATTENDANCE_ADMIN;
}

function bgi_is_mohalla_admin(): bool
{
    return bgi_current_user_role() === BGI_ROLE_MOHALLA_ADMIN;
}

function bgi_is_pair_scoped_role(): bool
{
    return in_array(bgi_current_user_role(), [BGI_ROLE_IDARA_ADMIN, BGI_ROLE_IDARA_ATTENDANCE_ADMIN, BGI_ROLE_MEMBER], true);
}

function bgi_is_scope_restricted(): bool
{
    return bgi_is_pair_scoped_role() || bgi_is_mohalla_admin();
}

function bgi_can_delete(): bool
{
    return bgi_is_super_admin();
}

function bgi_can_manage_admins(): bool
{
    return bgi_is_super_admin();
}

function bgi_can_view_admin_directory(): bool
{
    return in_array(bgi_current_user_role(), [BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN], true);
}

function bgi_can_manage_members(): bool
{
    return in_array(bgi_current_user_role(), [BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN], true);
}

function bgi_can_manage_events(): bool
{
    return in_array(bgi_current_user_role(), [BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN], true);
}

function bgi_can_take_attendance(): bool
{
    return in_array(bgi_current_user_role(), [BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN, BGI_ROLE_IDARA_ATTENDANCE_ADMIN], true);
}

function bgi_can_view_reports(): bool
{
    return in_array(bgi_current_user_role(), [BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN, BGI_ROLE_MEMBER], true);
}

function bgi_can_view_attendance_records(): bool
{
    return in_array(bgi_current_user_role(), [BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN], true);
}

function bgi_current_member_id(): int
{
    return (int) ($_SESSION['member_id'] ?? 0);
}

function bgi_current_member_its_id(): string
{
    return (string) ($_SESSION['member_its_id'] ?? '');
}

function bgi_current_member_name(): string
{
    return (string) ($_SESSION['member_name'] ?? '');
}

function bgi_current_member_position(): string
{
    return bgi_normalize_member_position($_SESSION['member_position'] ?? BGI_POSITION_MEMBER);
}

function bgi_is_team_leader_member(): bool
{
    return bgi_is_member() && bgi_current_member_position() === BGI_POSITION_TEAM_LEADER;
}

function bgi_is_captain_member(): bool
{
    return bgi_is_member() && bgi_current_member_position() === BGI_POSITION_CAPTAIN;
}

function bgi_member_report_scope_mode(): string
{
    if (!bgi_is_member()) {
        return 'staff';
    }

    if (bgi_is_captain_member()) {
        return 'scope';
    }

    if (bgi_is_team_leader_member()) {
        return 'team';
    }

    return 'self';
}

function bgi_current_scope_idara(): string
{
    if (bgi_is_super_admin()) {
        return BGI_SUPER_ADMIN_IDARA;
    }

    if (bgi_is_mohalla_admin()) {
        return BGI_SUPER_ADMIN_IDARA;
    }

    return bgi_normalize_scope_value($_SESSION['scope_idara'] ?? '', BGI_DEFAULT_IDARA);
}

function bgi_current_scope_mohalla(): string
{
    if (bgi_is_super_admin()) {
        return BGI_SUPER_ADMIN_MOHALLA;
    }

    return bgi_normalize_scope_value($_SESSION['scope_mohalla'] ?? '', BGI_DEFAULT_MOHALLA);
}

function bgi_current_scope_label(): string
{
    return bgi_current_scope_idara() . ' / ' . bgi_current_scope_mohalla();
}

function bgi_scope_matches_current(?string $idara, ?string $mohalla): bool
{
    if (!bgi_is_scope_restricted()) {
        return true;
    }

    if (bgi_is_mohalla_admin()) {
        return strcasecmp(
            bgi_normalize_scope_value($mohalla, BGI_DEFAULT_MOHALLA),
            bgi_current_scope_mohalla()
        ) === 0;
    }

    return strcasecmp(
        bgi_normalize_scope_value($idara, BGI_DEFAULT_IDARA),
        bgi_current_scope_idara()
    ) === 0 && strcasecmp(
        bgi_normalize_scope_value($mohalla, BGI_DEFAULT_MOHALLA),
        bgi_current_scope_mohalla()
    ) === 0;
}

function bgi_home_path_for_current_user(): string
{
    if (bgi_is_idara_attendance_admin()) {
        return 'admin_attendance.php';
    }

    return bgi_is_member() ? 'report_members.php' : 'dashboard.php';
}

function bgi_scope_filter_sql(string $idaraExpression = 'idara', string $mohallaExpression = 'mohalla'): array
{
    if (!bgi_is_scope_restricted()) {
        return ['', '', []];
    }

    if (bgi_is_mohalla_admin()) {
        return [$mohallaExpression . ' = ?', 's', [bgi_current_scope_mohalla()]];
    }

    return [
        $idaraExpression . ' = ? AND ' . $mohallaExpression . ' = ?',
        'ss',
        [bgi_current_scope_idara(), bgi_current_scope_mohalla()],
    ];
}

function bgi_set_flash(string $message, string $type = 'error'): void
{
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

function bgi_require_roles(array $roles): void
{
    $role = bgi_current_user_role();

    if ($role === '') {
        header('Location: login.php');
        exit;
    }

    if (!in_array($role, $roles, true)) {
        header('Location: ' . bgi_home_path_for_current_user());
        exit;
    }
}
?>
