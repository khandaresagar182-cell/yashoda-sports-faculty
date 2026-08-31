<?php
/**
 * Shared student-rendering helpers used by the faculty dashboard
 * (admin/dashboard.php) and the student search (student-search.php).
 *
 * The dashboard's "Players by Game" card and the search results table
 * both need the same per-student game chip rendering logic — extracting
 * it here keeps them in lockstep.
 *
 * Functions:
 *   - load_picker_dept_ids()              : array<int>
 *   - load_games_by_student(array $ids)   : array<int, array<string>>
 *   - render_sports_cell(...)             : string
 *   - load_department_game_names(?int)    : array<string>
 */

declare(strict_types=1);

/**
 * Return the department IDs that have at least one active row in
 * `dept_game_catalog` — i.e. the "picker" depts. Used everywhere we
 * need to branch picker-dept vs legacy-dept rendering.
 *
 * @return array<int> department_id values (as ints)
 */
function load_picker_dept_ids(): array {
    $rows = db_select(
        'SELECT DISTINCT department_id FROM dept_game_catalog WHERE is_active = 1'
    );
    return array_map(static fn($r) => (int)$r['department_id'], $rows);
}

/**
 * For a set of student IDs, return the games they have picked in
 * `student_selected_games`. Format: [student_id => [game_code, ...]]
 * sorted alphabetically by game_code (deterministic order for chips).
 *
 * Empty input returns an empty array.
 *
 * @param  array<int> $student_ids
 * @return array<int, array<string>>
 */
function load_games_by_student(array $student_ids): array {
    $out = [];
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $student_ids),
        static fn($v) => $v > 0
    )));
    if (empty($ids)) return $out;

    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $typ = str_repeat('i', count($ids));
    $rows = db_select(
        "SELECT student_id, game_code FROM student_selected_games
          WHERE student_id IN ($ph)
          ORDER BY student_id, game_code",
        $ids,
        $typ
    );
    foreach ($rows as $r) {
        $out[(int)$r['student_id']][] = (string)$r['game_code'];
    }
    return $out;
}

/**
 * Render the "Sports" cell for one student row.
 *
 * - Picker-dept students get up to 4 green game chips (one per game they
 *   picked in the wizard).
 * - Legacy (non-picker) dept students get sport_1 + sport_2 chips.
 * - No data → italic em-dash.
 *
 * $row must include at least: id, department_id, sport_1, sport_2.
 *
 * @param array{id:int,department_id:int,sport_1:?string,sport_2:?string,...} $row
 * @param array<int> $picker_dept_ids    from load_picker_dept_ids()
 * @param array<int, array<string>> $games_by_student    from load_games_by_student()
 */
function render_sports_cell(array $row, array $picker_dept_ids, array $games_by_student): string {
    $is_picker = in_array((int)$row['department_id'], $picker_dept_ids, true);
    if ($is_picker) {
        $games = $games_by_student[(int)$row['id']] ?? [];
        if (empty($games)) {
            return '<em style="color:var(--medium-gray);font-style:italic">— no games —</em>';
        }
        $html = '';
        foreach ($games as $g) {
            $html .= '<span class="sport-tag" style="background:rgba(5,150,105,.1);color:#0a3622;border-color:rgba(5,150,105,.25)">'
                   . h(ucwords(str_replace('_', ' ', $g)))
                   . '</span> ';
        }
        return rtrim($html);
    }
    $html = '';
    if (!empty($row['sport_1'])) $html .= '<span class="sport-tag">' . h($row['sport_1']) . '</span> ';
    if (!empty($row['sport_2'])) $html .= '<span class="sport-tag">' . h($row['sport_2']) . '</span> ';
    return $html !== '' ? rtrim($html) : '<em style="color:var(--medium-gray);font-style:italic">—</em>';
}

/**
 * Per-game student counts (Male / Female / Other / Total) for one
 * department's game catalog. Counts are picks, not distinct students —
 * a student who picked N games appears in N rows. Used by the
 * "Game-wise Student Count" card on student-search.php and its
 * Excel report (admin/gamewise_report_xlsx.php).
 *
 * Only submitted profiles (form_submitted_at IS NOT NULL) are counted,
 * matching faculty_visible_student_filter() used everywhere else.
 *
 * @return array<int, array{game_code:string,display_name:string,male:int,female:int,other:int,total:int}>
 */
function load_gamewise_gender_counts(int $department_id): array {
    $rows = db_select(
        "SELECT gc.game_code, gc.display_name, gc.display_order,
                SUM(CASE WHEN s.id IS NOT NULL AND s.gender = 'Male' THEN 1 ELSE 0 END) AS male_count,
                SUM(CASE WHEN s.id IS NOT NULL AND s.gender = 'Female' THEN 1 ELSE 0 END) AS female_count,
                SUM(CASE WHEN s.id IS NOT NULL AND (s.gender IS NULL OR s.gender NOT IN ('Male','Female')) THEN 1 ELSE 0 END) AS other_count,
                SUM(CASE WHEN s.id IS NOT NULL THEN 1 ELSE 0 END) AS total_count
           FROM dept_game_catalog gc
           LEFT JOIN student_selected_games ssg ON ssg.game_code = gc.game_code
           LEFT JOIN students s ON s.id = ssg.student_id
                                AND s.department_id = gc.department_id
                                AND s.form_submitted_at IS NOT NULL
          WHERE gc.department_id = ? AND gc.is_active = 1
          GROUP BY gc.id, gc.game_code, gc.display_name, gc.display_order
          ORDER BY gc.display_order",
        [$department_id], 'i'
    );
    return array_map(static function (array $r): array {
        return [
            'game_code'    => (string)$r['game_code'],
            'display_name' => (string)$r['display_name'],
            'male'         => (int)$r['male_count'],
            'female'       => (int)$r['female_count'],
            'other'        => (int)$r['other_count'],
            'total'        => (int)$r['total_count'],
        ];
    }, $rows);
}

/**
 * Ordered list of game display-names in one department's active catalog
 * (`dept_game_catalog`). This is the set of games "belonging to" a
 * faculty — used to populate the Game dropdown on the provisional and
 * final list picker cards.
 *
 * Returns [] when $department_id is null (e.g. an unscoped SUPER_ADMIN)
 * or the department has no catalog rows.
 *
 * @return array<string> display_name values, in display_order
 */
function load_department_game_names(?int $department_id): array {
    if ($department_id === null) return [];
    $rows = db_select(
        "SELECT display_name
           FROM dept_game_catalog
          WHERE department_id = ? AND is_active = 1
          ORDER BY display_order, display_name",
        [$department_id], 'i'
    );
    return array_map(static fn($r) => (string)$r['display_name'], $rows);
}

/**
 * Resolve a game DISPLAY NAME (e.g. "Cricket") to its `dept_game_catalog`
 * game_code for one department. Case-insensitive.
 *
 * Returns null when the name isn't a catalogued game for that dept — e.g.
 * a free-text custom game typed into the provisional/final picker. Callers
 * use that as "no picker data exists, don't filter the add-list by game".
 */
function resolve_department_game_code(?int $department_id, string $display_name): ?string {
    $display_name = trim($display_name);
    if ($department_id === null || $display_name === '') return null;
    $row = db_one(
        "SELECT game_code
           FROM dept_game_catalog
          WHERE department_id = ? AND is_active = 1
            AND LOWER(display_name) = LOWER(?)",
        [$department_id, $display_name], 'is'
    );
    return $row ? (string)$row['game_code'] : null;
}

/**
 * Distinct headcount (Male / Female / Other / Total) of submitted
 * students in one department — the "how many students total" figure
 * that sits above the per-game breakdown (a student picks multiple
 * games, so the game rows don't sum to this).
 *
 * @return array{male:int,female:int,other:int,total:int}
 */
function load_department_gender_totals(int $department_id): array {
    $row = db_one(
        "SELECT SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) AS male_count,
                SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) AS female_count,
                SUM(CASE WHEN gender IS NULL OR gender NOT IN ('Male','Female') THEN 1 ELSE 0 END) AS other_count,
                COUNT(*) AS total_count
           FROM students
          WHERE department_id = ? AND form_submitted_at IS NOT NULL",
        [$department_id], 'i'
    );
    return [
        'male'   => (int)($row['male_count'] ?? 0),
        'female' => (int)($row['female_count'] ?? 0),
        'other'  => (int)($row['other_count'] ?? 0),
        'total'  => (int)($row['total_count'] ?? 0),
    ];
}
