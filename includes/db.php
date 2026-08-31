<?php
/**
 * MySQL connection + prepared-statement helpers.
 *
 * Every read/write in the app flows through these helpers.
 * Zero raw mysqli_query() calls anywhere else in the codebase.
 */

declare(strict_types=1);

/* ---------------- configuration ---------------- */

// Prefer app-specific DB_* variables, then Railway's native MySQL variables,
// then a composite DATABASE_URL (DigitalOcean App Platform binding), and
// finally the local XAMPP defaults.
//
// In production (APP_ENV=production) we refuse to fall back to XAMPP defaults
// because that would silently try to connect to a non-existent local MySQL
// (Connection refused) instead of failing loudly with a clear configuration
// error. Falling back to localhost in production is a foot-gun.
$isLocal = (getenv('APP_ENV') === 'local') || (getenv('APP_ENV') === false);

/**
 * Optional local credentials file. Used on shared hosts (GoDaddy cPanel)
 * that block env[] in .user.ini / MultiPHP INI Editor. Returns [] when
 * the file is absent so this stays a no-op on hosts that prefer env vars.
 * The file is .gitignored — see includes/config.local.example.php for shape.
 */
function _db_local_config(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $path = __DIR__ . '/config.local.php';
    if (is_file($path)) {
        $val = require $path;
        $cached = is_array($val) ? $val : [];
    } else {
        $cached = [];
    }
    return $cached;
}

if ($isLocal) {
    $local = _db_local_config();
    if (!defined('DB_HOST'))  define('DB_HOST',  getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: ($local['DB_HOST'] ?? '127.0.0.1'));
    if (!defined('DB_USER'))  define('DB_USER',  getenv('DB_USER') ?: getenv('MYSQLUSER') ?: ($local['DB_USER'] ?? 'root'));
    if (!defined('DB_PASS'))  define('DB_PASS',  getenv('DB_PASS') ?: getenv('MYSQLPASSWORD') ?: ($local['DB_PASS'] ?? ''));
    if (!defined('DB_NAME'))  define('DB_NAME',  getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: ($local['DB_NAME'] ?? 'csf_portal'));
    if (!defined('DB_PORT'))  define('DB_PORT',  (int)(getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: ($local['DB_PORT'] ?? 3306)));
} else {
    // Production: try multiple sources for DB credentials.
    //
    // On DigitalOcean App Platform, env vars set via the dashboard are
    // visible via $_SERVER and $_ENV, but NOT necessarily via getenv().
    // Also, DO managed database bindings are often exposed as a single
    // composite DATABASE_URL (e.g. mysql://user:pass@host:port/db?ssl-mode=REQUIRED)
    // rather than individual DB_* vars.
    $envLookup = function (string $key) {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
        if (isset($_ENV[$key])    && $_ENV[$key]    !== '') return $_ENV[$key];
        // Try the MySQL* alias (Railway, Render, some DO setups).
        if (strpos($key, 'DB_') === 0) {
            $alt = 'MYSQL' . substr($key, 3);
            $v = getenv($alt);
            if ($v !== false && $v !== '') return $v;
            if (isset($_SERVER[$alt]) && $_SERVER[$alt] !== '') return $_SERVER[$alt];
            if (isset($_ENV[$alt])    && $_ENV[$alt]    !== '') return $_ENV[$alt];
        }
        return false;
    };

    // Source 1: individual DB_* env vars.
    $host = $envLookup('DB_HOST');
    $user = $envLookup('DB_USER');
    $pass = $envLookup('DB_PASS');
    $name = $envLookup('DB_NAME');
    $port = $envLookup('DB_PORT');

    // Source 2: composite DATABASE_URL (DO App Platform binding).
    // Format: mysql://user:password@host:port/database?ssl-mode=REQUIRED
    if (!$host) {
        $url = $envLookup('DATABASE_URL');
        if ($url && strpos($url, '${') === false && strpos($url, '://') !== false) {
            // Only parse if the binding has been resolved (no leftover template).
            $parts = parse_url($url);
            if ($parts && isset($parts['host'])) {
                $host = $parts['host'];
                $port = isset($parts['port']) ? (int)$parts['port'] : 3306;
                $user = $parts['user'] ?? $user;
                $pass = $parts['pass'] ?? $pass;
                $name = ltrim($parts['path'] ?? '', '/') ?: $name;
            }
        }
    }

    $missing = [];
    foreach (['host' => 'DB_HOST', 'user' => 'DB_USER', 'name' => 'DB_NAME'] as $field => $const) {
        if (empty(${$field})) $missing[] = $const;
    }

    // Fallback: a local PHP file with credentials, used on hosts (e.g. GoDaddy
    // shared cPanel) that block env[] in .user.ini / MultiPHP INI Editor.
    // The file is .gitignored. If it exists and the env vars are still missing,
    // pull from it. See includes/config.local.example.php for the shape.
    if (!empty($missing)) {
        $local = _db_local_config();
        if (!$host && !empty($local['DB_HOST'])) $host = $local['DB_HOST'];
        if (!$user && !empty($local['DB_USER'])) $user = $local['DB_USER'];
        if (!$pass && !empty($local['DB_PASS'])) $pass = $local['DB_PASS'];
        if (!$name && !empty($local['DB_NAME'])) $name = $local['DB_NAME'];
        if (!$port && !empty($local['DB_PORT'])) $port = $local['DB_PORT'];
        // Recompute missing list with the new values.
        $missing = [];
        foreach (['host' => 'DB_HOST', 'user' => 'DB_USER', 'name' => 'DB_NAME'] as $field => $const) {
            if (empty(${$field})) $missing[] = $const;
        }
    }

    if (!empty($missing)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Database configuration error: missing env vars: " . implode(', ', $missing) . "\n";
        echo "Set DB_HOST, DB_USER, DB_NAME, DB_PORT, DB_PASS as Environment Variables on the App,\n";
        echo "OR fix the DigitalOcean managed database binding so DATABASE_URL resolves,\n";
        echo "OR create includes/config.local.php with the credentials (see includes/config.local.example.php).\n";
        // Show DATABASE_URL for diagnostics but never print the password it carries
        // (format: mysql://user:PASSWORD@host:port/db).
        $dbUrl = $envLookup('DATABASE_URL');
        echo "Currently DATABASE_URL = " . (is_string($dbUrl) && $dbUrl !== ''
            ? preg_replace('#://([^:/@]+):[^@]*@#', '://$1:***@', $dbUrl)
            : var_export($dbUrl, true)) . "\n";
        exit;
    }

    if (!defined('DB_HOST')) define('DB_HOST', $host);
    if (!defined('DB_USER')) define('DB_USER', $user);
    if (!defined('DB_PASS')) define('DB_PASS', $pass ?? '');
    if (!defined('DB_NAME')) define('DB_NAME', $name);
    if (!defined('DB_PORT')) define('DB_PORT', (int)($port ?: 3306));
}
if (!defined('APP_ENV'))  define('APP_ENV',  getenv('APP_ENV')  ?: 'local'); // 'local' | 'production'

/* ---------------- singleton connection ---------------- */

/**
 * Returns the process-wide mysqli connection (lazy-init).
 */
function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    // Decide whether to enforce SSL before opening the socket.
    //
    // Managed databases (DigitalOcean, AWS RDS, etc.) reject non-SSL
    // connections; local XAMPP/development does not support SSL out of the
    // box; and **shared hosting** (Namecheap, GoDaddy cPanel) connects to
    // MySQL on localhost — which also does NOT support SSL.
    //
    // Logic:
    //   1. If DB_SSL env/config is explicitly 'false' → SSL off.
    //   2. If DB_SSL env/config is explicitly 'true'  → SSL on.
    //   3. If DB_HOST is localhost or 127.0.0.1       → SSL off (shared host).
    //   4. If APP_ENV is 'local'                      → SSL off.
    //   5. Otherwise (remote managed DB)              → SSL on.
    $db_ssl_env = getenv('DB_SSL');
    if ($db_ssl_env === false && isset($_SERVER['DB_SSL'])) {
        $db_ssl_env = $_SERVER['DB_SSL'];
    }
    if ($db_ssl_env === 'false' || $db_ssl_env === '0') {
        $use_ssl = false;
    } elseif ($db_ssl_env === 'true' || $db_ssl_env === '1') {
        $use_ssl = true;
    } elseif (in_array(DB_HOST, ['localhost', '127.0.0.1', '::1'], true)) {
        // Shared hosting (Namecheap, GoDaddy): MySQL on localhost never
        // supports SSL. Skip it so the connection doesn't fail silently.
        $use_ssl = false;
    } else {
        $use_ssl = (APP_ENV !== 'local');
    }

    $conn = mysqli_init();
    if ($conn === false) {
        http_response_code(500);
        exit('Database connection failed (init).');
    }
    if ($use_ssl) {
        // No cert pinning — use the system CA store. mysqli_ssl_set with all
        // nulls is the documented way to ask for "any valid server cert".
        // Some sandboxed PHP builds don't link OpenSSL into mysqli; in that
        // case MYSQLI_CLIENT_SSL is undefined. Fall back to 0 (no SSL flag)
        // so the connection still attempts — better to fail with a clear
        // "Connection refused" than to fatal out on an undefined constant.
        @mysqli_ssl_set($conn, null, null, null, null, null);
        $sslFlag = defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 0;
    } else {
        $sslFlag = 0;
    }
    $ok = mysqli_real_connect(
        $conn, DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT,
        null, $sslFlag
    );
    if (!$ok) {
        // Never leak credentials in the error message
        $err  = 'Database connection failed.';
        // After mysqli_real_connect() fails, error info lives on the connection.
        $code = mysqli_connect_errno();
        $msg  = mysqli_connect_error();
        // Older PHP keeps the global slot populated too; in case it doesn't,
        // fall back to the per-conn getter so the local-env hint still works.
        if (!$msg) {
            $msg = mysqli_error($conn);
        }
        if (APP_ENV === 'local') {
            $err .= ' (' . $code . ': ' . htmlspecialchars($msg) . ')';
        }
        error_log('[db] connect failed: ' . $code . ' ' . $msg);
        http_response_code(500);
        exit($err);
    }

    mysqli_set_charset($conn, 'utf8mb4');
    mysqli_query($conn, "SET time_zone = '+05:30'");
    mysqli_query($conn, "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

    return $conn;
}

/* ---------------- parameter binding helpers ---------------- */

/**
 * Build the type string for bind_param() from a list of values.
 * Supports: int, float, string, null, bool, blob (b).
 */
function db_types(array $params): string
{
    $types = '';
    foreach ($params as $p) {
        if (is_int($p))         $types .= 'i';
        elseif (is_float($p))   $types .= 'd';
        elseif (is_bool($p))    $types .= 'i';
        elseif (is_null($p))    $types .= 's'; // nulls are bound as string 'NULL'
        elseif (is_string($p))  $types .= 's';
        else                    $types .= 's';
    }
    return $types;
}

/**
 * Prepare + bind + execute. Returns the mysqli_stmt on success.
 * Throws RuntimeException on failure (caller decides how to respond).
 */
function db_query(string $sql, array $params = [], ?string $types = null): mysqli_stmt
{
    $stmt = mysqli_prepare(db(), $sql);
    if (!$stmt) {
        $err = mysqli_error(db());
        error_log("[db] prepare failed: $err | sql=$sql");
        throw new RuntimeException('Database error (prepare).');
    }
    if (!empty($params)) {
        $types  = $types ?? db_types($params);
        // spread by reference is required by bind_param
        $bind = [$types];
        foreach ($params as $k => $v) $bind[] = &$params[$k];
        if (!@call_user_func_array([$stmt, 'bind_param'], $bind)) {
            $err = $stmt->error ?: 'unknown bind_param error';
            error_log("[db] bind_param failed: $err | sql=$sql");
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Database error (bind).');
        }
    }
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        error_log("[db] execute failed: $err | sql=$sql");
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Database error (execute).');
    }
    return $stmt;
}

/**
 * SELECT — returns an array of associative rows.
 */
function db_select(string $sql, array $params = [], ?string $types = null): array
{
    $stmt = db_query($sql, $params, $types);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * SELECT — returns one associative row, or null.
 */
function db_one(string $sql, array $params = [], ?string $types = null): ?array
{
    // Strip trailing whitespace + semicolons before checking for a LIMIT clause,
    // so a query that already ends in LIMIT 1 doesn't get a second one appended.
    // SHOW/DESCRIBE/EXPLAIN statements don't accept LIMIT — skip the append for them.
    $trimmed = rtrim(trim($sql), ';');
    $isMeta  = (bool)preg_match('/^\s*(SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $trimmed);
    if (!$isMeta && !preg_match('/\bLIMIT\s+\d+/i', $trimmed)) {
        $trimmed .= ' LIMIT 1';
    }
    $rows = db_select($trimmed, $params, $types);
    return $rows[0] ?? null;
}

/**
 * INSERT / UPDATE / DELETE — returns affected rows.
 */
function db_execute(string $sql, array $params = [], ?string $types = null): int
{
    $stmt = db_query($sql, $params, $types);
    $n    = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $n;
}

/**
 * INSERT — returns last insert id.
 */
function db_insert(string $sql, array $params = [], ?string $types = null): int
{
    $stmt = db_query($sql, $params, $types);
    $id   = mysqli_insert_id(db());
    mysqli_stmt_close($stmt);
    return $id;
}
