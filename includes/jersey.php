<?php
/**
 * Shared helpers for jersey form department scoping.
 */

declare(strict_types=1);

function jersey_forms_has_department_id(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }

    $rows = db_select("SHOW COLUMNS FROM jersey_forms LIKE 'department_id'");
    $has = !empty($rows);
    return $has;
}

function jersey_request_department_id(): ?int
{
    $f = current_faculty();
    if (!$f) {
        return null;
    }

    if ($f['role'] === 'FACULTY') {
        return $f['department_id'] ? (int)$f['department_id'] : null;
    }

    $raw = $_REQUEST['dept'] ?? null;
    if ($raw !== null && ctype_digit((string)$raw)) {
        $dept_id = (int)$raw;
        $exists = db_one(
            'SELECT id FROM departments WHERE id = ? AND is_active = 1',
            [$dept_id],
            'i'
        );
        if ($exists) {
            return $dept_id;
        }
    }

    return null;
}

function jersey_department_for_team(string $game, string $event, ?string $academic_year, ?string $gender = null): ?int
{
    $dept_id = jersey_request_department_id();
    if ($dept_id !== null) {
        return $dept_id;
    }

    $where  = 'ft.game_name = ? AND ft.event_label = ? AND ft.academic_year <=> ?';
    $params = [$game, $event, $academic_year];
    $types  = 'sss';
    if ($gender !== null && $gender !== '') {
        $where   .= ' AND ft.gender <=> ?';
        $params[] = $gender;
        $types   .= 's';
    }

    $rows = db_select(
        "SELECT DISTINCT s.department_id
           FROM final_teams ft
           JOIN students s ON s.id = ft.student_id
          WHERE $where",
        $params,
        $types
    );

    return count($rows) === 1 ? (int)$rows[0]['department_id'] : null;
}

function jersey_form_department_filter(?int $department_id, string $alias = ''): array
{
    if (!jersey_forms_has_department_id() || $department_id === null) {
        return ['', [], ''];
    }

    $prefix = $alias !== '' ? $alias . '.' : '';
    return [" AND {$prefix}department_id = ? ", [$department_id], 'i'];
}

/**
 * Whether `jersey_forms` has been migrated to include `gender` yet
 * (added in migration-v36-jersey-gender.sql, after department_id was
 * bolted on ad-hoc — same "don't assume, check" reasoning applies).
 */
function jersey_forms_has_gender(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }

    $rows = db_select("SHOW COLUMNS FROM jersey_forms LIKE 'gender'");
    $has = !empty($rows);
    return $has;
}

function jersey_form_gender_filter(?string $gender, string $alias = ''): array
{
    if (!jersey_forms_has_gender() || $gender === null || $gender === '') {
        return ['', [], ''];
    }

    $prefix = $alias !== '' ? $alias . '.' : '';
    return [" AND {$prefix}gender <=> ? ", [$gender], 's'];
}
