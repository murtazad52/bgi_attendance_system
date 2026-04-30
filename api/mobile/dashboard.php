<?php
require_once __DIR__ . '/bootstrap.php';

bgi_mobile_require_login();

$metrics = [];
$notices = [];

if (bgi_is_member()) {
    $scopeFilter = bgi_scope_filter_sql('idara', 'mohalla');
    $scopeSql = $scopeFilter[0];
    $scopeTypes = $scopeFilter[1];
    $scopeParams = $scopeFilter[2];
    $accessibleEvents = bgi_mobile_query_count(
        $conn,
        'SELECT COUNT(*) FROM events' . ($scopeSql !== '' ? ' WHERE ' . $scopeSql : ''),
        $scopeTypes,
        $scopeParams
    );

    if (bgi_member_report_scope_mode() === 'self') {
        $itsId = bgi_current_member_its_id();
        $records = bgi_mobile_query_count($conn, "SELECT COUNT(*) FROM attendance WHERE its_id = ?", 's', [$itsId]);
        $present = bgi_mobile_query_count($conn, "SELECT COUNT(*) FROM attendance WHERE its_id = ? AND status = 'Present'", 's', [$itsId]);
        $late = bgi_mobile_query_count($conn, "SELECT COUNT(*) FROM attendance WHERE its_id = ? AND status = 'Late'", 's', [$itsId]);

        $metrics = [
            ['label' => 'Scope Events', 'value' => $accessibleEvents, 'subtitle' => 'Events in your Idara and Mohalla', 'tone' => 'pine'],
            ['label' => 'Saved Records', 'value' => $records, 'subtitle' => 'Attendance entries already recorded', 'tone' => 'neutral'],
            ['label' => 'Present', 'value' => $present, 'subtitle' => 'Marked on time or accepted as present', 'tone' => 'success'],
            ['label' => 'Late', 'value' => $late, 'subtitle' => 'Saved as late', 'tone' => 'late'],
        ];

        $recentEvents = bgi_mobile_query_rows(
            $conn,
            "SELECT e.id, e.event_name, COALESCE(e.event_code, '') AS event_code,
                    DATE_FORMAT(e.event_date, '%Y-%m-%d') AS event_date,
                    COALESCE(DATE_FORMAT(e.reporting_time, '%H:%i'), '') AS reporting_time,
                    e.idara, e.mohalla, a.status AS user_status
             FROM attendance a
             INNER JOIN events e ON e.id = a.event_id
             WHERE a.its_id = ?
             ORDER BY e.event_date DESC, e.reporting_time DESC
             LIMIT 6",
            's',
            [$itsId]
        );

        $notices[] = 'Member mode shows only your own saved attendance history.';
    } elseif (bgi_member_report_scope_mode() === 'team') {
        $itsId = bgi_current_member_its_id();
        $teamMembers = bgi_mobile_query_count($conn, "SELECT COUNT(*) FROM members WHERE team_leader_its_id = ?", 's', [$itsId]);
        $records = bgi_mobile_query_count(
            $conn,
            "SELECT COUNT(*)
             FROM attendance a
             INNER JOIN members m ON m.its_id = a.its_id
             WHERE m.team_leader_its_id = ?",
            's',
            [$itsId]
        );
        $late = bgi_mobile_query_count(
            $conn,
            "SELECT COUNT(*)
             FROM attendance a
             INNER JOIN members m ON m.its_id = a.its_id
             WHERE m.team_leader_its_id = ? AND a.status = 'Late'",
            's',
            [$itsId]
        );

        $metrics = [
            ['label' => 'Team Members', 'value' => $teamMembers, 'subtitle' => 'Members currently assigned to you', 'tone' => 'gold'],
            ['label' => 'Scope Events', 'value' => $accessibleEvents, 'subtitle' => 'Events available in your scope', 'tone' => 'pine'],
            ['label' => 'Team Records', 'value' => $records, 'subtitle' => 'Saved attendance rows for your team', 'tone' => 'neutral'],
            ['label' => 'Late Rows', 'value' => $late, 'subtitle' => 'Team records currently marked late', 'tone' => 'late'],
        ];

        $recentEvents = bgi_mobile_query_rows(
            $conn,
            "SELECT e.id, e.event_name, COALESCE(e.event_code, '') AS event_code,
                    DATE_FORMAT(e.event_date, '%Y-%m-%d') AS event_date,
                    COALESCE(DATE_FORMAT(e.reporting_time, '%H:%i'), '') AS reporting_time,
                    e.idara, e.mohalla, COUNT(a.id) AS recorded_count
             FROM attendance a
             INNER JOIN members m ON m.its_id = a.its_id
             INNER JOIN events e ON e.id = a.event_id
             WHERE m.team_leader_its_id = ?
             GROUP BY e.id, e.event_name, e.event_code, e.event_date, e.reporting_time, e.idara, e.mohalla
             ORDER BY e.event_date DESC, e.reporting_time DESC
             LIMIT 6",
            's',
            [$itsId]
        );

        $notices[] = 'Team Leader mode follows the members assigned to your ITS ID.';
    } else {
        $memberCount = bgi_mobile_query_count(
            $conn,
            'SELECT COUNT(*) FROM members' . ($scopeSql !== '' ? ' WHERE ' . $scopeSql : ''),
            $scopeTypes,
            $scopeParams
        );
        $teamLeaders = bgi_mobile_query_count(
            $conn,
            "SELECT COUNT(*) FROM members" . ($scopeSql !== '' ? ' WHERE ' . $scopeSql . " AND position = 'team_leader'" : " WHERE position = 'team_leader'"),
            $scopeTypes,
            $scopeParams
        );
        $records = bgi_mobile_query_count(
            $conn,
            "SELECT COUNT(*)
             FROM attendance a
             INNER JOIN members m ON m.its_id = a.its_id" . ($scopeSql !== '' ? ' WHERE ' . str_replace(['idara', 'mohalla'], ['m.idara', 'm.mohalla'], $scopeSql) : ''),
            $scopeTypes,
            $scopeParams
        );

        $metrics = [
            ['label' => 'Members', 'value' => $memberCount, 'subtitle' => 'Members in your full scope', 'tone' => 'gold'],
            ['label' => 'Events', 'value' => $accessibleEvents, 'subtitle' => 'Events in this Idara and Mohalla', 'tone' => 'pine'],
            ['label' => 'Team Leaders', 'value' => $teamLeaders, 'subtitle' => 'Leadership rows linked below the captain', 'tone' => 'neutral'],
            ['label' => 'Records', 'value' => $records, 'subtitle' => 'Attendance rows saved in this scope', 'tone' => 'success'],
        ];

        $recentEvents = bgi_mobile_query_rows(
            $conn,
            "SELECT e.id, e.event_name, COALESCE(e.event_code, '') AS event_code,
                    DATE_FORMAT(e.event_date, '%Y-%m-%d') AS event_date,
                    COALESCE(DATE_FORMAT(e.reporting_time, '%H:%i'), '') AS reporting_time,
                    e.idara, e.mohalla, COUNT(a.id) AS recorded_count
             FROM events e
             LEFT JOIN attendance a ON a.event_id = e.id" . ($scopeSql !== '' ? ' WHERE ' . $scopeSql : '') . "
             GROUP BY e.id, e.event_name, e.event_code, e.event_date, e.reporting_time, e.idara, e.mohalla
             ORDER BY e.event_date DESC, e.reporting_time DESC
             LIMIT 6",
            $scopeTypes,
            $scopeParams
        );

        $notices[] = 'Captain mode shows the full scope below your Idara and Mohalla.';
    }
} else {
    $memberScope = bgi_scope_filter_sql('idara', 'mohalla');
    $memberSql = $memberScope[0];
    $memberTypes = $memberScope[1];
    $memberParams = $memberScope[2];

    $memberCount = bgi_mobile_query_count(
        $conn,
        'SELECT COUNT(*) FROM members' . ($memberSql !== '' ? ' WHERE ' . $memberSql : ''),
        $memberTypes,
        $memberParams
    );
    $eventCount = bgi_mobile_query_count(
        $conn,
        'SELECT COUNT(*) FROM events' . ($memberSql !== '' ? ' WHERE ' . $memberSql : ''),
        $memberTypes,
        $memberParams
    );
    $teamLeaderCount = bgi_mobile_query_count(
        $conn,
        "SELECT COUNT(*) FROM members" . ($memberSql !== '' ? ' WHERE ' . $memberSql . " AND position = 'team_leader'" : " WHERE position = 'team_leader'"),
        $memberTypes,
        $memberParams
    );
    $captainCount = bgi_mobile_query_count(
        $conn,
        "SELECT COUNT(*) FROM members" . ($memberSql !== '' ? ' WHERE ' . $memberSql . " AND position = 'captain'" : " WHERE position = 'captain'"),
        $memberTypes,
        $memberParams
    );

    $metrics = [
        ['label' => 'Members', 'value' => $memberCount, 'subtitle' => 'Visible in your current access scope', 'tone' => 'gold'],
        ['label' => 'Events', 'value' => $eventCount, 'subtitle' => 'Accessible from mobile right now', 'tone' => 'pine'],
        ['label' => 'Team Leaders', 'value' => $teamLeaderCount, 'subtitle' => 'Configured below the active captains', 'tone' => 'neutral'],
        ['label' => 'Captains', 'value' => $captainCount, 'subtitle' => 'Leadership rows for this scope', 'tone' => 'success'],
    ];

    $recentEvents = bgi_mobile_query_rows(
        $conn,
        "SELECT e.id, e.event_name, COALESCE(e.event_code, '') AS event_code,
                DATE_FORMAT(e.event_date, '%Y-%m-%d') AS event_date,
                COALESCE(DATE_FORMAT(e.reporting_time, '%H:%i'), '') AS reporting_time,
                e.idara, e.mohalla, COUNT(a.id) AS recorded_count
         FROM events e
         LEFT JOIN attendance a ON a.event_id = e.id" . ($memberSql !== '' ? ' WHERE ' . $memberSql : '') . "
         GROUP BY e.id, e.event_name, e.event_code, e.event_date, e.reporting_time, e.idara, e.mohalla
         ORDER BY e.event_date DESC, e.reporting_time DESC
         LIMIT 6",
        $memberTypes,
        $memberParams
    );

    if (bgi_can_take_attendance()) {
        $notices[] = 'This role can record attendance directly from the mobile app.';
    }
}

bgi_mobile_respond([
    'ok' => true,
    'user' => bgi_mobile_current_user_payload(),
    'metrics' => $metrics,
    'recentEvents' => bgi_mobile_format_event_rows($recentEvents),
    'notices' => $notices,
]);
