<?php
/**
 * Eligibility Archive — server-side backup of every Word (.docx) final-team
 * eligibility form generated from admin/final_export_docx.php.
 *
 * Files live under:
 *   uploads/eligibility_archive/<dept_code>/<academic_year>/<file>.docx
 * (that folder carries a deny-all .htaccess; the only way to read a file
 *  back out is the authenticated admin/eligibility_download.php endpoint.)
 *
 * Every generate = a new timestamped row in `eligibility_archive`. Reads are
 * always scoped to one department_id.
 *
 * The `zip` PHP extension is not available on this stack (same reason the
 * DOCX builders hand-roll their container), so a tiny store/deflate ZIP
 * writer is included below — lifted from the proven SimpleDocxZip in
 * includes/polytechnic_eligibility_docx.php.
 */

declare(strict_types=1);

if (!defined('ELIGIBILITY_ARCHIVE_ROOT')) {
    define('ELIGIBILITY_ARCHIVE_ROOT', dirname(__DIR__) . '/uploads/eligibility_archive');
}

/** Max size (bytes) for an emailed ZIP bundle — real files are 10–160 KB. */
if (!defined('ELIGIBILITY_ARCHIVE_ZIP_CAP')) {
    define('ELIGIBILITY_ARCHIVE_ZIP_CAP', 20 * 1024 * 1024);
}

/**
 * Minimal ZIP writer (deflate). Produces a spec-valid archive without the
 * ext/zip module. Mirrors SimpleDocxZip in polytechnic_eligibility_docx.php.
 */
final class SimpleZipArchive
{
    /** @var array<int,array{name:string,crc:int,size:int,compressed:string,offset:int}> */
    private array $entries = [];
    private string $body = '';

    public function addFile(string $name, string $data): void
    {
        $compressed = gzdeflate($data, 9);
        if ($compressed === false) {
            throw new RuntimeException('Unable to compress ZIP entry.');
        }
        $name   = str_replace('\\', '/', $name);
        $offset = strlen($this->body);
        $crc    = crc32($data);
        $this->body .= pack(
            'VvvvvvVVVvv',
            0x04034b50, 20, 0, 8, 0, 0,
            $crc, strlen($compressed), strlen($data), strlen($name), 0
        ) . $name . $compressed;
        $this->entries[] = [
            'name' => $name, 'crc' => $crc, 'size' => strlen($data),
            'compressed' => $compressed, 'offset' => $offset,
        ];
    }

    public function finish(): string
    {
        $central = '';
        foreach ($this->entries as $e) {
            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50, 20, 20, 0, 8, 0, 0,
                $e['crc'], strlen($e['compressed']), $e['size'],
                strlen($e['name']), 0, 0, 0, 0, 0, $e['offset']
            ) . $e['name'];
        }
        $count = count($this->entries);
        return $this->body . $central . pack(
            'VvvvvVVv',
            0x06054b50, 0, 0, $count, $count, strlen($central), strlen($this->body), 0
        );
    }
}

/**
 * Normalise a free-text bit for use in a filename / folder name.
 */
function eligibility_archive_slug(string $value, string $fallback = ''): string
{
    $slug = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $value), '_');
    return $slug !== '' ? $slug : $fallback;
}

/**
 * Folder-safe academic-year token. Valid form is "YYYY-YY"; anything else
 * (legacy/blank) collapses to "unspecified".
 */
function eligibility_archive_year_slug(string $academicYear): string
{
    $academicYear = trim($academicYear);
    return preg_match('/^\d{4}-\d{2}$/', $academicYear) ? $academicYear : 'unspecified';
}

/**
 * Store one generated Word eligibility form. Best-effort: any failure is
 * logged and swallowed so the browser download is never affected.
 */
function eligibility_archive_store(
    int $departmentId,
    string $departmentCode,
    string $academicYear,
    string $game,
    string $gender,
    string $eventLabel,
    string $docxBinary,
    int $playerCount,
    ?int $facultyId
): void {
    try {
        if ($departmentId <= 0 || $docxBinary === '') {
            return;
        }

        $deptSlug = eligibility_archive_slug($departmentCode, 'dept_' . $departmentId);
        $yearSlug = eligibility_archive_year_slug($academicYear);
        $dir      = ELIGIBILITY_ARCHIVE_ROOT . '/' . $deptSlug . '/' . $yearSlug;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[eligibility_archive] mkdir failed: ' . $dir);
            return;
        }

        $genderLabel = gender_list_options()[$gender] ?? '';
        $parts = array_filter([
            'Eligibility',
            eligibility_archive_slug($game, 'Team'),
            eligibility_archive_slug($genderLabel),
            eligibility_archive_slug($eventLabel),
            $yearSlug,
            date('Ymd-His'),
        ], 'strlen');
        $fileName = implode('_', $parts) . '.docx';
        $absPath  = $dir . '/' . $fileName;

        if (@file_put_contents($absPath, $docxBinary) === false) {
            error_log('[eligibility_archive] write failed: ' . $absPath);
            return;
        }

        $relPath = 'uploads/eligibility_archive/' . $deptSlug . '/' . $yearSlug . '/' . $fileName;

        db_insert(
            'INSERT INTO eligibility_archive
                (department_id, academic_year, game_name, gender, event_label,
                 file_name, file_path, player_count, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $departmentId,
                preg_match('/^\d{4}-\d{2}$/', trim($academicYear)) ? trim($academicYear) : '',
                mb_substr($game, 0, 80),
                $gender !== '' ? mb_substr($gender, 0, 10) : null,
                mb_substr($eventLabel, 0, 120),
                mb_substr($fileName, 0, 255),
                mb_substr($relPath, 0, 255),
                max(0, $playerCount),
                $facultyId && $facultyId > 0 ? $facultyId : null,
            ],
            'issssssii'
        );
    } catch (\Throwable $e) {
        error_log('[eligibility_archive] store failed: ' . $e->getMessage());
    }
}

/**
 * Year folders for one department: [{academic_year, n, last_at}], newest first.
 * @return array<int,array<string,mixed>>
 */
function eligibility_archive_years(int $departmentId): array
{
    if ($departmentId <= 0) return [];
    return db_select(
        "SELECT academic_year,
                COUNT(*)        AS n,
                MAX(created_at) AS last_at
           FROM eligibility_archive
          WHERE department_id = ?
          GROUP BY academic_year
          ORDER BY (academic_year = '') ASC, academic_year DESC",
        [$departmentId], 'i'
    );
}

/**
 * Files in one department's year folder, newest first.
 * @return array<int,array<string,mixed>>
 */
function eligibility_archive_files(int $departmentId, string $academicYear): array
{
    if ($departmentId <= 0) return [];
    $ay = preg_match('/^\d{4}-\d{2}$/', trim($academicYear)) ? trim($academicYear) : '';
    return db_select(
        'SELECT id, academic_year, game_name, gender, event_label,
                file_name, file_path, player_count, created_at
           FROM eligibility_archive
          WHERE department_id = ? AND academic_year = ?
          ORDER BY created_at DESC, id DESC',
        [$departmentId, $ay], 'is'
    );
}

/**
 * One archived file, scoped to a department. Null if it doesn't belong.
 */
function eligibility_archive_row(int $id, ?int $departmentId): ?array
{
    if ($id <= 0 || !$departmentId || $departmentId <= 0) return null;
    return db_one(
        'SELECT id, department_id, academic_year, game_name, gender, event_label,
                file_name, file_path, player_count, created_at
           FROM eligibility_archive
          WHERE id = ? AND department_id = ?',
        [$id, $departmentId], 'ii'
    );
}

/**
 * Absolute, containment-checked path for an archive row, or null.
 */
function eligibility_archive_abs_path(array $row): ?string
{
    $rel = ltrim(str_replace('\\', '/', (string)($row['file_path'] ?? '')), '/');
    if ($rel === '' || strpos($rel, '..') !== false) return null;

    $root = realpath(ELIGIBILITY_ARCHIVE_ROOT);
    $abs  = realpath(dirname(__DIR__) . '/' . $rel);
    if ($root === false || $abs === false) return null;
    if (strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0) return null;
    if (!is_file($abs) || !is_readable($abs)) return null;
    if (strtolower(pathinfo($abs, PATHINFO_EXTENSION)) !== 'docx') return null;

    return $abs;
}

/**
 * Bundle N archive rows into a single ZIP (bytes). Missing files are
 * skipped. Throws RuntimeException if the total exceeds the cap or nothing
 * usable was found.
 *
 * @param array<int,array<string,mixed>> $rows
 */
function eligibility_archive_zip(array $rows): string
{
    $zip   = new SimpleZipArchive();
    $total = 0;
    $added = 0;
    $seen  = [];

    foreach ($rows as $row) {
        $abs = eligibility_archive_abs_path($row);
        if ($abs === null) continue;

        $data = @file_get_contents($abs);
        if ($data === false) continue;

        $total += strlen($data);
        if ($total > ELIGIBILITY_ARCHIVE_ZIP_CAP) {
            throw new RuntimeException('The selected files are too large to email as one ZIP.');
        }

        $name = (string)($row['file_name'] ?? basename($abs));
        if (isset($seen[$name])) {
            $name = pathinfo($name, PATHINFO_FILENAME) . '_' . (++$seen[$name]) . '.docx';
        } else {
            $seen[$name] = 1;
        }

        $zip->addFile($name, $data);
        $added++;
    }

    if ($added === 0) {
        throw new RuntimeException('None of the selected files could be read.');
    }

    return $zip->finish();
}
