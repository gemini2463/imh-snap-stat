<?php
// SnapStat
/**
 * sys-snap Web Interface for cPanel/WHM and CWP
 *
 * Provides a web interface to view sys-snap system snapshots,
 * and display 24-hour server statistics.
 *
 * Compatible with:
 *   - cPanel/WHM: /usr/local/cpanel/whostmgr/docroot/cgi/imh-snap-stat/index.php
 *   - CWP:       /usr/local/cwpsrv/htdocs/resources/admin/modules/imh-snap-stat.php
 *
 * Maintainer: InMotion Hosting
 * Version: 0.1.8
 */


// ==========================
// 1. Environment Detection
// 2. Session & Security
// 3. HTML Header & CSS
// 4. Main Interface
// 5. sys-snap Tab
// 6. 24-hour Statistics Tab
// 7. HTML Footer
// ==========================





// ==========================
// 1. Environment Detection
// ==========================

declare(strict_types=1);

$isCPanelServer = (
    (is_dir('/usr/local/cpanel') || is_dir('/var/cpanel') || is_dir('/etc/cpanel')) && (is_file('/usr/local/cpanel/cpanel') || is_file('/usr/local/cpanel/version'))
);

$isCWPServer = (
    is_dir('/usr/local/cwp')
);

if ($isCPanelServer) {
    if (getenv('REMOTE_USER') !== 'root') exit('Access Denied');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} else { // CWP
    if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1 || !isset($_SESSION['username']) || $_SESSION['username'] !== 'root') {
        exit('Access Denied');
    }
};










// ==========================
// 2. Session & Security
// ==========================

$CSRF_TOKEN = NULL;

if (!isset($_SESSION['csrf_token'])) {
    $CSRF_TOKEN = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $CSRF_TOKEN;
} else {
    $CSRF_TOKEN = $_SESSION['csrf_token'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        exit("Invalid CSRF token");
    }
}

define('IMH_SAR_CACHE_DIR', '/root/tmp/imh-snap-stat');

if (!is_dir(IMH_SAR_CACHE_DIR)) {
    mkdir(IMH_SAR_CACHE_DIR, 0700, true);
}

// Clear old cache files

$cache_dir = IMH_SAR_CACHE_DIR;
$expire_seconds = 3600; // e.g. 1 hour

foreach (glob("$cache_dir/*.cache") as $file) {
    if (is_file($file) && (time() - filemtime($file) > $expire_seconds)) {
        unlink($file);
    }
}

function imh_safe_cache_filename(string $tag): string
{
    // 1. Sanitize the tag by allowing only a safe subset of characters.
    $safe_tag = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tag);
    // 2. Truncate the sanitized tag to a reasonable length to prevent filesystem errors.
    $truncated_tag = substr($safe_tag, 0, 55); // Truncate to 55 to leave room for the hash
    // 3. Append a short, fast hash of the ORIGINAL tag to guarantee uniqueness and avoid collisions.
    $hash = hash('crc32b', $tag); // crc32b is very fast and sufficient for this purpose.
    return IMH_SAR_CACHE_DIR . '/sar_' . $truncated_tag . '_' . $hash . '.cache';
}


// Returns the sar sample interval in seconds (default 600).

function imh_guess_sar_interval()
{
    $cmd = "LANG=C sar -q 2>&1 | grep -E '^[0-9]{2}:[0-9]{2}:[0-9]{2}' | head -2 | awk '{print $1}'";
    $out = safe_shell_exec($cmd, 3);
    if (!is_string($out)) {
        return 600; // fallback if shell_exec failed
    }
    $lines = array_filter(array_map('trim', explode("\n", $out)));
    if (count($lines) < 2) return 600; // fallback
    $t1 = strtotime($lines[0]);
    $t2 = strtotime($lines[1]);
    if ($t1 === false || $t2 === false) return 600;
    $interval = $t2 - $t1;
    if ($interval > 0 && $interval < 3600) return $interval;
    return 600;
}

function imh_cached_shell_exec($tag, $command, $sar_interval)
{
    $cache_file = imh_safe_cache_filename($tag);
    $lock_file  = $cache_file . '.lock';
    $fp = fopen($lock_file, 'c');
    if ($fp === false) return false;

    $maxRetries = 10; // Try up to 10 times
    $retryDelay = 200000; // 200ms (in microseconds)

    $locked = false;
    for ($i = 0; $i < $maxRetries; $i++) {
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            $locked = true;
            break;
        }
        // Another process has the lock → wait briefly
        usleep($retryDelay);
    }

    if (!$locked) {
        fclose($fp);
        return false; // Give up after retries
    }

    try {
        // At this point we hold the lock
        if (file_exists($cache_file)) {
            $mtime = filemtime($cache_file);
            if ($mtime && (time() - $mtime < $sar_interval)) {
                $cached = file_get_contents($cache_file);
                if ($cached !== false && strlen(trim($cached)) > 0) {
                    return $cached;
                }
            }
        }

        $out = shell_exec($command);
        if (!is_string($out) || trim($out) === '') {
            return false;
        }
        file_put_contents($cache_file, $out);
        chmod($cache_file, 0600);
        return $out;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($lock_file);
    }
}




// Runs a shell command safely with a timeout, preventing hangs.

function safe_shell_exec(string $command, int $timeout = 3): string
{
    static $timeout_bin = null;
    if ($timeout_bin === null) {
        // Find the timeout binary path once
        $found = trim(shell_exec('command -v timeout 2>/dev/null') ?: '');
        $timeout_bin = $found !== '' ? $found : false;
    }

    if ($timeout_bin) {
        // Only escape the path to timeout, not the actual command
        $cmd = escapeshellarg($timeout_bin) . ' ' . (int)$timeout . 's ' . $command;
        $out = shell_exec($cmd);
        return is_string($out) ? $out : '';
    }

    // Fallback: no timeout binary, use proc_open() with stream_select timeout
    $descriptorspec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($command, $descriptorspec, $pipes);
    if (!is_resource($process)) return '';

    $output = '';
    $start = time();
    $readStreams = [$pipes[1], $pipes[2]];

    while (!empty($readStreams) && (time() - $start) < $timeout) {
        $readCopy = $readStreams;
        $write = null;
        $except = null;

        if (stream_select($readCopy, $write, $except, 1) > 0) {
            foreach ($readCopy as $stream) {
                $chunk = stream_get_contents($stream);
                if ($chunk !== false) {
                    $output .= $chunk;
                }
                $key = array_search($stream, $readStreams, true);
                unset($readStreams[$key]);
            }
        }
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_terminate($process);
    proc_close($process);

    // Return raw output (don't trim so whitespace/newlines are preserved)
    return is_string($output) ? $output : '';
}


function find_executable(string $command, array $fallback_paths = [])
{
    // 1. Check the system's PATH first (most flexible method).
    // 'command -v' is a reliable, POSIX-standard way to find an executable's path.
    $path_from_shell = trim(safe_shell_exec('command -v ' . escapeshellarg($command), 2));
    if (!empty($path_from_shell) && is_executable($path_from_shell)) {
        return $path_from_shell;
    }
    // 2. If not in PATH, check the list of common fallback locations.
    foreach ($fallback_paths as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }
    // 3. If not found anywhere, return false.
    return false;
}

// Define the list of common locations for sys-snap.pl
const SYS_SNAP_FALLBACK_PATHS = [
    '/opt/imh-sys-snap/bin/sys-snap.pl',
    '/root/sys-snap.pl',
    '/usr/local/bin/sys-snap.pl',
    '/usr/bin/sys-snap.pl'
];
// Find the executable and store its path in a constant.
define('SYS_SNAP_EXECUTABLE', find_executable('sys-snap.pl', SYS_SNAP_FALLBACK_PATHS));




// Defaults and validation

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_time'])) {
    // Reset to defaults
    $start_hour = 0;
    $start_min  = 0;
    $end_hour   = 23;
    $end_min    = 59;
} else {
    $start_hour = min(23, max(0, (int)($_POST['start_hour'] ?? 0)));
    $start_min  = min(59, max(0, (int)($_POST['start_min'] ?? 0)));
    $end_hour   = min(23, max(0, (int)($_POST['end_hour'] ?? 23)));
    $end_min    = min(59, max(0, (int)($_POST['end_min'] ?? 59)));
}





// Force PHP to use system local timezone so it matches sys-snap logging

$server_tz = trim(shell_exec('date +%Z')); // e.g. "EDT"
$tz_name = @timezone_name_from_abbr($server_tz);
if ($tz_name !== false) {
    date_default_timezone_set($tz_name);
} else {
    // fallback: use system-configured timezone
    date_default_timezone_set(@date_default_timezone_get());
}





// Find local time

$server_time_full = safe_shell_exec('timedatectl', 2);
if (!$server_time_full) {
    $server_time = 'Time unavailable';
} else {
    $server_time_lines = explode("\n", trim($server_time_full));
    $server_time = $server_time_lines[0] ?? 'Time unavailable';
}








// ==========================
// 3. HTML Header & CSS
// ==========================

if ($isCPanelServer) {
    require_once('/usr/local/cpanel/php/WHM.php');
    WHM::header('imh-snap-stat WHM Interface', 0, 0);
} else {
    echo '<div class="panel-body">';
};








// Styles for the tabs and buttons

?>

<style>
    .panel-body a,
    .imh-box a,
    .imh-footer-box a,
    .imh-box--narrow a,
    .panel-body a,
    .imh-box a,
    .imh-footer-box a,
    .imh-box--narrow a {
        color: #C52227;
    }

    .panel-body a:hover,
    .imh-box a:hover,
    .imh-footer-box a:hover,
    .imh-box--narrow a:hover,
    .panel-body a:focus,
    .imh-box a:focus,
    .imh-footer-box a:focus,
    .imh-box--narrow a:focus {
        color: #d33a41;
    }

    .imh-btn {
        margin-left: 15px;
        padding: 5px 15px;
        border-radius: 6px;
    }

    .imh-red-btn {
        background: #C52227;
        color: #fff;
        border: none;
    }

    .imh-piechart-col {
        vertical-align: top;
    }

    .imh-title {
        margin: 0.25em 0 1em 0;
    }

    .imh-title-img {
        margin-right: 0.5em;
    }

    .sys-snap-tables {
        border-collapse: collapse;
        margin: 2em 0;
        background: #fafcff;
    }

    .sys-snap-tables,
    .sys-snap-tables th,
    .sys-snap-tables td {
        border: 1px solid #000;
    }

    .sys-snap-tables th,
    .sys-snap-tables td {
        padding: 4px 8px;
    }

    .legend-cell {
        padding: 4px 8px;
        border: 1px solid #000;
    }

    .sys-snap-tables thead {
        background: #e6f2ff;
        color: #333;
        font-weight: 600;
    }

    .sys-snap-tables tr.odd-num-table-row {
        background: #f4f4f4;
    }

    .tabs-nav {
        display: flex;
        border-bottom: 1px solid #e3e3e3;
        margin-bottom: 2em;
    }

    .tabs-nav button {
        border: none;
        background: #f8f8f8;
        color: #333;
        padding: 12px 28px;
        cursor: pointer;
        border-top-left-radius: 6px;
        border-top-right-radius: 6px;
        font-size: 1em;
        margin-bottom: -1px;
        border-bottom: 2px solid transparent;
        transition: background 0.15s, border-color 0.15s;
    }

    .tabs-nav button.active {
        background: #fff;
        border-bottom: 2px solid #C52227;
        color: #C52227;
        font-weight: 600;
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    .imh-status {
        display: inline-block;
        padding: 6px 18px;
        border-radius: 14px;
        font-weight: 600;
        margin-right: 18px;
        border: 1px solid;
    }

    .imh-status-running {
        background: #e6ffee;
        color: #26a042;
        border-color: #8fd19e;
    }

    .imh-status-notrunning {
        background: #ffeaea;
        color: #c22626;
        border-color: #e99;
    }

    .imh-box {
        margin: 2em 0;
        padding: 1em;
        border: 1px solid #ccc;
        border-radius: 8px;
        display: block;
        background: #f9f9f9;
    }

    .imh-width-full {
        table-layout: fixed;
        width: 100%;
    }

    .imh-box--narrow {
        margin: 1em 0 1em 0;
        padding: 1em;
        border: 1px solid #ccc;
        border-radius: 8px;
        display: block;
        background: #f9f9f9;
    }

    .imh-box--footer {
        margin: 2em 0 2em 0;
        padding: 1em;
        border: 1px solid #ccc;
        border-radius: 8px;
        display: block;
    }

    .imh-pre {
        background: #f8f8f8;
        border: 1px solid #ccc;
        padding: 1em;
        margin: 2em;
    }

    .imh-server-time {
        margin-left: 1em;
        color: #444;
        font-weight: 600;
    }

    .imh-spacer {
        margin-top: 2em;
    }

    .imh-user-section {
        display: block;
        padding: 0.5em 1em;
        border-top: 1px solid black;
    }

    .imh-user-name {
        color: rgb(42, 73, 94);
    }

    .imh-table-alt {
        background: #f4f4f4;
    }

    .high-load-cell {
        background-color: #ffe5e5 !important;
        /* A light, noticeable red */
        color: #9c1010 !important;
        /* Darker red text for contrast */
        font-weight: bold;
        outline: 1px solid #ffb8b8;
        /* A subtle border to contain the highlight */
    }

    .moderate-load-cell {
        background-color: #fff3cd !important;
        /* pale amber/yellow */
        color: #856404 !important;
        /* darker amber text */
        font-weight: bold;
        outline: 1px solid #ffeeba;
        /* subtle amber border */
    }

    .very-low-load-cell {
        background-color: #e6ffea !important;
        /* a very light green */
        color: #0a6b2e !important;
        /* darker green text for contrast */
        font-weight: bold;
        outline: 1px solid #b8ffd1;
        /* subtle green border */
    }

    .low-load-cell {
        background-color: #e6f0ff !important;
        /* a very light blue */
        color: #0a3e8a !important;
        /* darker blue text for contrast */
        font-weight: bold;
        outline: 1px solid #cfe3ff;
        /* subtle blue border */
    }

    .imh-alert {
        color: #c00;
        margin: 1em;
    }

    .imh-footer-img {
        margin-bottom: 1em;
    }

    .imh-footer-box {
        margin: 2em 0 2em 0;
        padding: 1em;
        border: 1px solid #ccc;
        border-radius: 8px;
        display: block;
        background: #f9f9f9;
    }

    .imh-small-note {
        font-size: 0.9em;
        color: #555;
    }

    .text-right {
        text-align: right;
    }

    .imh-monospace {
        font-family: monospace;
    }

    .imh-box.margin-bottom {
        margin-bottom: 1em;
    }

    .imh-pid {
        color: #888;
    }

    .panel-body {
        padding-bottom: 5px;
        display: block;
    }

    .imh-collapsible-content {
        max-height: 33333px;
        overflow: hidden;
        transition: max-height 0.3s ease;
    }

    .imh-collapsible-content[aria-hidden="true"] {
        max-height: 0;
    }

    .imh-toggle-btn {
        background: #eee;
        border: 1px solid #999;
        border-radius: 4px;
        cursor: pointer;
        margin-left: 0.5em;
        padding: 2px 10px;
        font-family: monospace;
        font-size: larger;
    }

    .imh-toggle-btn:hover {
        background: #ddd;
        font-weight: bold;
        color: #333;
    }

    .imh-larger-text {
        font-size: 1.5em;
    }

    .imh-table-responsive {
        width: 100%;
        overflow-x: auto;
    }

    .imh-legend td {
        padding: 6px 10px;
        font-size: 0.9em;
        font-weight: normal;
    }

    .imh-legend .sys-snap-tables {
        width: auto;
        margin: 0.5em 0;
    }

    @media (max-width: 600px) {

        .sys-snap-tables,
        .imh-box,
        .imh-box--narrow,
        .imh-footer-box {
            width: 100% !important;
            min-width: 350px;
            font-size: 0.97em;
        }

        .imh-piechart-col {
            width: 100% !important;
            display: block;
            box-sizing: border-box;
        }

        .sys-snap-tables th,
        .sys-snap-tables td {
            padding: 4px 4px;
        }

        /* Optionally stack the pie chart columns vertically */
        .sys-snap-tables tr {
            display: flex;
            flex-direction: column;
        }
    }

    .chart-container {
        max-height: 800px !important;
        max-width: 800px !important;
        display: block;
        margin-left: auto;
        margin-right: auto;
        background: #fff;
    }

    #PiechartUsersCPU,
    #PiechartUsersMemory {
        width: 100% !important;
        max-width: 100%;
    }
</style>

<?php





// ==========================
// 4. Main Interface
// ==========================

$img_src = $isCWPServer ? 'design/img/imh-snap-stat.png' : 'imh-snap-stat.png';
echo '<h1 class="imh-title"><img src="' . htmlspecialchars($img_src) . '" alt="sys-snap" class="imh-title-img" />SnapStat</h1>';



// This is the tab selector for the two main sections: sys-snap and 24-hour statistics.

echo '<div class="tabs-nav" id="imh-tabs-nav">
    <button type="button" class="active" data-tab="tab-sys-snap" aria-label="System Snapshot tab">System Snapshot (sys-snap)</button>
    <button type="button" data-tab="tab-loadavg" aria-label="24 Hour Statistics tab">24 Hour Statistics (sysstat)</button>
</div>';





// Tab selector script

?>

<script>
    // Tab navigation functionality

    document.querySelectorAll('#imh-tabs-nav button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            // Remove 'active' class from all buttons and tab contents
            document.querySelectorAll('#imh-tabs-nav button').forEach(btn2 => btn2.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            // Activate this button and the corresponding tab
            btn.classList.add('active');
            var tabId = btn.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
        });
    });

    // Each user section can be collapsed or expanded with a button.

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.imh-toggle-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var targetId = btn.getAttribute('data-target');
                var collapsed = btn.getAttribute('data-collapsed') === '1';
                var content = document.getElementById(targetId);

                if (collapsed) {
                    // Expand
                    content.setAttribute('aria-hidden', 'false');
                    btn.innerText = '[–]';
                    btn.setAttribute('data-collapsed', '0');
                    btn.setAttribute('aria-expanded', 'true');
                } else {
                    // Collapse
                    content.setAttribute('aria-hidden', 'true');
                    btn.innerText = '[+]';
                    btn.setAttribute('data-collapsed', '1');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        });
    });
</script>
<?php






// ==========================
// 5. sys-snap Tab
// ==========================

$action_output = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'start') {
        $start_cmd = "echo y | " . escapeshellarg(SYS_SNAP_EXECUTABLE) . " --start 2>&1";
        $action_output = safe_shell_exec($start_cmd, 5);
    }
}


// Check current status
$status_cmd = escapeshellarg(SYS_SNAP_EXECUTABLE) . ' --check 2>&1';
$status_output = safe_shell_exec($status_cmd, 3);
$is_running = false;
$pid = null;

if (preg_match("/Sys-snap is running, PID:\s*'(\d+)'/", $status_output, $m)) {
    $is_running = true;
    $pid = $m[1];
}

echo '<div id="tab-sys-snap" class="tab-content active">';


// Check if the executable was found
if (SYS_SNAP_EXECUTABLE === false) {
    echo "<div class='imh-box imh-alert'>
              <strong>Error:</strong> The <code>sys-snap.pl</code> executable could not be found.
              <br/><br/>
              Please ensure that sys-snap is installed and that the <code>sys-snap.pl</code> script is located in the system's PATH or in one of the common directories:
              <ul>
                  <li>/opt/imh-sys-snap/bin/</li>
                  <li>/root/</li>
                  <li>/usr/local/bin/</li>
                  <li>/usr/bin/</li>
              </ul>
          </div>";
} else {




    // Info box

    function formatElapsedTime($seconds)
    {
        $seconds = (int)$seconds;
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $mins = floor(($seconds % 3600) / 60);

        $out = [];
        if ($days > 0) $out[] = "{$days}d";
        if ($hours > 0 || $days > 0) $out[] = "{$hours}h";
        $out[] = "{$mins}m";

        return implode(', ', $out);
    }

    echo "<div class='imh-box imh-box.margin-bottom'><p class='imh-larger-text'><a target='_blank' href='https://github.com/CpanelInc/tech-SysSnapv2'>sys-snap</a> logs CPU and memory usage on a rolling 24-hour cycle, every minute.</p>";

    // Status box

    echo '<div class="imh-box">';
    if ($is_running) {
        $etime = null;
        if ($pid) {
            $etime = trim(safe_shell_exec('ps -p ' . intval($pid) . ' -o etimes= 2>/dev/null', 2));
        }
        $runtime_str = '';
        if (ctype_digit($etime)) {
            $runtime_str = formatElapsedTime($etime);
        }
        echo '<span class="imh-status imh-status-running">';
        echo 'Running';
        if ($runtime_str) {
            echo ' for ' . htmlspecialchars($runtime_str);
        }
        echo '</span>';
        echo "<span class='imh-pid'>PID: " . intval($pid) . '</span>';
    } else {
        echo '<span class="imh-status imh-status-notrunning">';
        echo 'Not Running';
        echo '</span>';

        // Start button.
        echo '
<form method="post">
  <input type="hidden" name="csrf_token" value="' . htmlspecialchars($CSRF_TOKEN) . '">
  <input type="hidden" name="form"       value="sys_snap_control">
  <input type="hidden" name="action"     value="start"> 
  <button type="submit">Start sys-snap</button>
</form>
    ';
    }
    echo '</div>';


    // System output, if the button was used to start sys-snap

    if ($action_output) {
        echo "<pre class='imh-pre'><b>System Output:</b><br/><br/>"
            . htmlspecialchars($action_output) . "</pre>";
    }

    echo "<p>Review the <code>24 Hour Statistics</code> to identify time ranges of interest.</p><br/>";

    echo "<p><strong>CPU Score</strong>: 1 = 1% of a CPU<br/>
<strong>Memory Score</strong>: 1 = 1% of total memory</p>";

    echo "</div>";







    // Set Time Range form

    echo '<form method="post" class="imh-box">
<input type="hidden" name="csrf_token" value="' . htmlspecialchars($CSRF_TOKEN) . '">
<input type="hidden" name="form" value="time_range">
';
    echo "<p class='imh-server-time'>" . htmlspecialchars($server_time) . "</p>";

    echo 'Start: <select name="start_hour">';
    for ($i = 0; $i < 24; $i++) echo "<option value='$i'" . ($i == $start_hour ? ' selected' : '') . ">$i</option>";
    echo '</select> : <select name="start_min">';
    for ($i = 0; $i < 60; $i++) {
        echo "<option value='$i'" . ($i == $start_min ? ' selected' : '') . ">" .
            sprintf("%02d", $i) .
            "</option>";
    }
    echo '</select>';

    echo ' &nbsp; End: <select name="end_hour">';
    for ($i = 0; $i < 24; $i++) echo "<option value='$i'" . ($i == $end_hour ? ' selected' : '') . ">$i</option>";
    echo '</select> : <select name="end_min">';
    for ($i = 0; $i < 60; $i++) {
        echo "<option value='$i'" . ($i == $end_min ? ' selected' : '') . ">" .
            sprintf("%02d", $i) .
            "</option>";
    }
    echo '</select>';

    echo ' <input type="submit" name="set_time" value="Set New Time Range" class="imh-btn">';
    if ($start_hour != 0 || $start_min != 0 || $end_hour != 23 || $end_min != 59) {
        echo ' <input type="submit" name="reset_time" value="Reset Time Range" class="imh-btn imh-red-btn">';
    }
    echo '</form>';










    //Sys-snap output

    // Determine display time range dynamically
    $display_start = sprintf('%02d:%02d', $start_hour, $start_min);
    $display_end   = sprintf('%02d:%02d', $end_hour, $end_min);

    if ($is_running && ctype_digit($etime)) {
        $elapsed = (int)$etime;
        if ($elapsed < 86400) { // less than 24h
            $now = time();
            $start_ts = $now - $elapsed;
            $display_start = date('H:i', $start_ts);
            $display_end   = date('H:i', $now);
        } else {
            // running more than 24h, default full day
            $display_start = '00:00';
            $display_end   = '23:59';
        }
    }

    $start_time_arg = sprintf('%02d:%02d', $start_hour, $start_min);
    $end_time_arg = sprintf('%02d:%02d', $end_hour, $end_min);

    $sys_snap_cmd = escapeshellarg(SYS_SNAP_EXECUTABLE) . ' --print ' .
        escapeshellarg($start_time_arg) . ' ' .
        escapeshellarg($end_time_arg) . ' -v 2>&1';

    $cache_ttl = 60; // seconds
    $cache_tag = "sys_snap_{$start_hour}_{$start_min}_{$end_hour}_{$end_min}";
    $output = imh_cached_shell_exec($cache_tag, $sys_snap_cmd, $cache_ttl);



    // Handle null/empty output gracefully

    if (!$output || $output === null) {
        echo "<div class='alert alert-danger imh-spacer imh-alert'>Could not get output from sys-snap.<br/>Check time range and try again.</div>";
        echo '</div>'; // Close tab-sys-snap

        echo '<div id="tab-loadavg" class="tab-content">';
        echo "<div class='alert alert-danger imh-spacer imh-alert'>Could not get output from sys-snap.<br/>Check time range and try again.</div>";

        echo '<form method="post" class="imh-box">
    <input type="hidden" name="csrf_token" value="' . htmlspecialchars($CSRF_TOKEN) . '">
    <input type="hidden" name="form" value="time_range">
    <input type="submit" name="reset_time" value="Reset Time Range" class="imh-btn imh-red-btn">
    </form>';

        echo '</div>'; // Close tab-loadavg

        if ($isCPanelServer) {
            WHM::footer();
        } else {
            echo '</div>'; // Close panel-body
        }
        return;
    }




    $tz_label = date('T'); // e.g. "EDT", "PDT", "GMT"
    echo '<h2 class="imh-spacer">Scores from ' . htmlspecialchars($display_start) .
        ' to ' . htmlspecialchars($display_end) . ' ' . htmlspecialchars($tz_label) . '</h2>';




    // Parse output

    function parseSysSnap($text)
    {
        $lines = explode("\n", $text);
        $results = [];
        $currentUser = null;
        $currentSection = null;

        foreach ($lines as $line) {
            $trim = trim($line);

            // User Section
            if (preg_match('/^user:\s+(\S+)/', $line, $m)) {
                $currentUser = $m[1];
                $results[$currentUser] = [
                    'cpu-score' => null,
                    'cpu-list' => [],
                    'memory-score' => null,
                    'memory-list' => []
                ];
                $currentSection = null;
                continue;
            }

            // CPU score
            if (preg_match('/cpu-score:\s+([0-9\.]+)/', $line, $m) && $currentUser) {
                $results[$currentUser]['cpu-score'] = $m[1];
                $currentSection = 'cpu-list';
                continue;
            }

            // memory score
            if (preg_match('/memory-score:\s+([0-9\.]+)/', $line, $m) && $currentUser) {
                $results[$currentUser]['memory-score'] = $m[1];
                $currentSection = 'memory-list';
                continue;
            }

            // CPU process
            if (preg_match('/C:\s*([0-9\.]+)\s*proc:\s*(.*)$/', $trim, $m) && $currentUser && $currentSection == 'cpu-list') {
                $results[$currentUser]['cpu-list'][] = ['score' => $m[1], 'proc' => $m[2]];
                continue;
            }

            // Memory process
            if (preg_match('/M:\s*([0-9\.]+)\s*proc:\s*(.*)$/', $trim, $m) && $currentUser && $currentSection == 'memory-list') {
                $results[$currentUser]['memory-list'][] = ['score' => $m[1], 'proc' => $m[2]];
                continue;
            }
        }

        return $results;
    }








    // Display sys-snap data

    function cleanProcName($proc)
    {
        // Remove all leading "tree" decorations (like "| ", "\_ ", any whitespace), possibly repeated.
        return preg_replace('/^[\|\s\\\\\_]+/', '', $proc);
    }

    $data = parseSysSnap($output);






    // Output data for the pie graph

    $pieDataCPU = [];
    foreach ($data as $user => $vals) {
        $pieDataCPU[] = [
            'user' => $user,
            'cpuScore' => floatval($vals['cpu-score'])
        ];
    };

    $pieDataMemory = [];
    // Sort $data by memory-score descending for the pie chart
    $sortedByMemory = $data;
    uasort($sortedByMemory, function ($a, $b) {
        return floatval($b['memory-score']) <=> floatval($a['memory-score']);
    });
    foreach ($sortedByMemory as $user => $vals) {
        $pieDataMemory[] = [
            'user' => $user,
            'memoryScore' => floatval($vals['memory-score'])
        ];
    };

    echo "<script>
window.sysSnapPieDataCPU = " . json_encode($pieDataCPU) . ";
window.sysSnapPieDataMemory = " . json_encode($pieDataMemory) . ";
</script>";







    // --- User Summary Table ---
    echo '<div class="imh-box">';
    echo '<table class="sys-snap-tables">';
    echo '<thead>';
    echo '<th>User</th>';
    echo '<th>CPU Score<br/>1 = 1% of a CPU</th>';
    echo '<th>Memory Score<br/>1 = 1% of total memory</th>';
    echo '</thead>';

    $row_idx = 0;

    foreach ($data as $user => $vals) {
        // Anchor ID for the user section
        $anchor = 'user-' . rawurlencode($user);
        $row_class = ($row_idx % 2 === 1) ? " class='odd-num-table-row'" : "";
        echo "<tr$row_class>";

        $row_idx++;

        if ($isCPanelServer) {
            echo '<td><strong><a href="#' . $anchor . '">' . htmlspecialchars($user) . '</a></strong></td>';
        } else {
            echo '<td><strong>' . htmlspecialchars($user) . '</strong></td>';
        }
        echo '<td class="text-right">' . htmlspecialchars($vals['cpu-score']) . '</td>';
        echo '<td class="text-right">' . htmlspecialchars($vals['memory-score']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</div>';

    // Pie chart container
    echo '
<div class="imh-box imh-table-responsive">
<table class="sys-snap-tables imh-width-full">
    <thead>
        <th class="imh-piechart-col">CPU Usage by User<br/>1 = 1% of a CPU</th>
        <th class="imh-piechart-col">Memory Usage by User<br/>1 = 1% of total memory</th>
    </thead>
    <tr class="imh-piechart-row">
        <td class="imh-piechart-col">
            <div id="PiechartUsersCPU"></div>
        </td>
        <td class="imh-piechart-col">
            <div id="PiechartUsersMemory"></div>
        </td>
    </tr>
</table>
</div>
';

    // --- User Details Sections ---
    // Each user has a collapsible section with CPU and Memory details.

    echo '<div class="imh-spacer imh-monospace">';
    foreach ($data as $user => $vals) {
        $anchor = 'user-' . rawurlencode($user);

        // Unique ID for JS target
        $collapse_id = 'user-details-' . md5($user);

        echo "<div id='$anchor' class='imh-spacer imh-monospace imh-user-section'>";
        echo "<h2 style='display:inline-block;'>User: <span class='imh-user-name'>" . htmlspecialchars($user) . "</span></h2>";


        // Use data attributes for the toggle target and state
        echo " <button class='imh-toggle-btn' data-target='$collapse_id' data-collapsed='0' aria-expanded='true' style='margin-left:1em;font-size:1em;vertical-align:middle;'>[–]</button>";


        // Collapsible content container

        echo "<div id='$collapse_id' class='imh-collapsible-content'>";


        // CPU
        echo "<h3>CPU Score: " . htmlspecialchars($vals['cpu-score']) . "</h3>";
        echo "<table class='sys-snap-tables'>";
        echo "<thead><th>CPU</th><th>Process</th></thead>";

        $cpu_row_idx = 0;

        foreach ($vals['cpu-list'] as $row) {
            $cpu_row_class = ($cpu_row_idx % 2 === 1) ? " class='imh-table-alt'" : "";
            echo "<tr$cpu_row_class>";
            $cpu_row_idx++;
            echo "<td class='text-right'>" . htmlspecialchars($row['score']) . "</td>";
            echo "<td>" . htmlspecialchars(cleanProcName($row['proc'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        // Memory
        echo "<h3>Memory Score: " . htmlspecialchars($vals['memory-score']) . "</h3>";
        echo "<table class='sys-snap-tables'>";
        echo "<thead><th>Memory</th><th>Process</th></thead>";

        $mem_row_idx = 0;

        foreach ($vals['memory-list'] as $row) {
            $mem_row_class = ($mem_row_idx % 2 === 1) ? " class='imh-table-alt'" : "";
            echo "<tr$mem_row_class>";
            $mem_row_idx++;
            echo "<td class='text-right'>" . htmlspecialchars($row['score']) . "</td>";
            echo "<td>" . htmlspecialchars(cleanProcName($row['proc'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        // End collapsible
        echo "</div>";

        echo "</div>";
    }
    echo "</div>";
} // End of the 'else' block for valid output

//End of sys-snap tab content
echo "</div>";












// ==========================
// 6. 24-hour Statistics Tab
// ==========================

class SarDataProcessor
{
    private const SAR_LOG_PATHS = [
        '/var/log/sa/sa',      // Standard location
        '/var/log/sysstat/sa'  // Alternative location
    ];
    private const DEFAULT_Q_HEADER = ['Time', 'runq-sz', 'plist-sz', 'ldavg-1', 'ldavg-5', 'ldavg-15', 'blocked'];
    private const DEFAULT_B_HEADER = ['Time', 'pgpgin/s', 'pgpgout/s', 'fault/s', 'majflt/s', 'pgfree/s', 'pgscank/s', 'pgscand/s', 'pgsteal/s', '%vmeff'];
    private const B_COLUMNS_TO_MERGE = ['pgpgin/s', 'pgpgout/s', 'fault/s', 'majflt/s'];
    private $sarLogPath;
    private $currentTime;
    private $todayShort;
    private $yesterdayShort;
    private $todayLong;
    private $yesterdayLong;
    private $sarInterval;
    private $dateFormat;
    private $yesterday;
    private $today;

    public function __construct()
    {
        $this->initializeTimezone();
        $this->initializeDates();
        $this->sarInterval = imh_guess_sar_interval();
        $this->determineSarLogPath();
    }

    public function getSarData(): array
    {
        try {
            $sarQData = $this->getSarQData();
            if (!$sarQData['data']) {
                return ['success' => false, 'error' => 'Could not get sar data'];
            }
            $sarBData = $this->getSarBData();
            $mergedData = $this->mergeSarData($sarQData['data'], $sarBData['data']);
            $finalHeader = $this->createFinalHeader($sarQData['header']);
            return [
                'success' => true,
                'header' => $finalHeader,
                'data' => $mergedData
            ];
        } catch (Exception $e) {
            error_log('SarDataProcessor Error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'System statistics temporarily unavailable'];
        }
    }

    private function initializeTimezone(): void
    {
        $shortName = exec('date +%Z');
        $longName = timezone_name_from_abbr($shortName);
        if ($longName) {
            date_default_timezone_set($longName);
        }
    }

    private function initializeDates(): void
    {
        $now = time();
        $this->currentTime = date('H:i:s', $now);

        // Short format: "03"
        $this->todayShort = date('d', $now);
        $this->yesterdayShort = date('d', strtotime('yesterday', $now));

        // Long format: "20250903"
        $this->todayLong = date('Ymd', $now);
        $this->yesterdayLong = date('Ymd', strtotime('yesterday', $now));

        // Default until we know format
        $this->today = $this->todayShort;
        $this->yesterday = $this->yesterdayShort;
    }

    private function getSarQData(): array
    {
        $output = $this->executeSarCommands('-q');
        $lines = $this->mergeAndFilterLines($output, 'runq-sz');
        $header = $this->extractHeader($lines, '/runq-sz\s+plist-sz\s+ldavg-1\s+ldavg-5\s+ldavg-15\s+blocked/', self::DEFAULT_Q_HEADER);
        $data = $this->parseDataLines($lines, $header);
        return ['header' => $header, 'data' => $data];
    }

    private function getSarBData(): array
    {
        $output = $this->executeSarCommands('-B');
        $lines = $this->mergeAndFilterLines($output, 'pgpgin/s');
        $header = $this->extractHeader($lines, '/pgpgin\/s\s+pgpgout\/s\s+fault\/s\s+majflt\/s/', self::DEFAULT_B_HEADER);
        $data = $this->parseDataLines($lines, $header);
        return ['header' => $header, 'data' => $data];
    }

    private function determineSarLogPath(): void
    {
        $candidates = [
            $this->yesterdayShort,
            $this->todayShort,
            $this->yesterdayLong,
            $this->todayLong
        ];

        foreach (self::SAR_LOG_PATHS as $path) {
            foreach ($candidates as $date) {
                if (file_exists($path . $date)) {
                    $this->sarLogPath = $path;
                    $this->dateFormat = (strlen($date) === 2) ? 'short' : 'long';

                    if ($this->dateFormat === 'long') {
                        $this->today = $this->todayLong;
                        $this->yesterday = $this->yesterdayLong;
                    } else {
                        $this->today = $this->todayShort;
                        $this->yesterday = $this->yesterdayShort;
                    }

                    return;
                }
            }
        }

        // fallback: assume short format
        $this->sarLogPath = self::SAR_LOG_PATHS[0];
        $this->dateFormat = 'short';
        $this->today = $this->todayShort;
        $this->yesterday = $this->yesterdayShort;
    }

    private function resolveDate(string $day): string
    {
        return ($day === 'yesterday') ? $this->yesterday : $this->today;
    }

    private function executeSarCommands(string $option): array
    {
        // For yesterday: cache for the day
        $tag1 = 'sar' . preg_replace('/[^a-zA-Z0-9]/', '', $option) . '_yesterday_' . $this->yesterday;

        // For today: cache by "hour" or "quarter hour" to balance freshness and not over-caching
        $now = time();
        $hour = date('H', $now);
        // Optionally, use 10-min intervals to be more granular
        $ten_min = floor(date('i', $now) / 10) * 10;
        $tag2 = 'sar' . preg_replace('/[^a-zA-Z0-9]/', '', $option) . '_today_' . $this->today . "_h{$hour}_m{$ten_min}";

        $cmd1 = "LC_ALL=C sar " . escapeshellarg($option) . " -f " . $this->sarLogPath . $this->resolveDate('yesterday') . " -s {$this->currentTime}";
        $cmd2 = "LC_ALL=C sar " . escapeshellarg($option) . " -f " . $this->sarLogPath . $this->resolveDate('today') . " -e {$this->currentTime}";
        return [
            imh_cached_shell_exec($tag1, $cmd1, $this->sarInterval),
            imh_cached_shell_exec($tag2, $cmd2, $this->sarInterval)
        ];
    }

    private function mergeAndFilterLines(array $outputs, string $headerPattern): array
    {
        $allLines = [];

        foreach ($outputs as $index => $output) {
            $lines = array_filter(array_map('trim', explode("\n", $output)));

            // Remove headers from second output
            if ($index > 0) {
                $lines = array_filter($lines, function ($line) use ($headerPattern) {
                    return strpos($line, $headerPattern) === false;
                });
            }

            $allLines = array_merge($allLines, $lines);
        }
        return $this->filterDataLines($allLines);
    }

    private function filterDataLines(array $lines): array
    {
        return array_filter($lines, function ($line) {
            $trimmed = trim($line);
            return $trimmed
                && strpos($trimmed, 'Average:') !== 0
                && strpos($trimmed, 'Linux') !== 0
                && !preg_match('/runq-sz\s+plist-sz\s+ldavg-1/', $trimmed)
                && !preg_match('/pgpgin\/s\s+pgpgout\/s\s+fault\/s/', $trimmed);
        });
    }

    private function extractHeader(array $lines, string $pattern, array $defaultHeader): array
    {
        foreach ($lines as $line) {
            if (preg_match($pattern, $line)) {
                $headerParts = preg_split('/\s+/', trim($line));
                return array_merge(['Time'], array_slice($headerParts, 1));
            }
        }

        return $defaultHeader;
    }

    private function parseDataLines(array $lines, array $header): array
    {
        $data = [];

        foreach ($lines as $line) {
            $row = preg_split('/\s+/', trim($line));

            if (count($row) < count($header)) {
                continue;
            }
            $time = $row[0];
            $values = array_slice($row, 1, count($header) - 1);

            if (count($values) === count($header) - 1) {
                $data[] = array_combine($header, array_merge([$time], $values));
            }
        }

        return $data;
    }

    private function mergeSarData(array $sarQData, array $sarBData): array
    {
        $bDataMap = [];
        foreach ($sarBData as $row) {
            $bDataMap[$row['Time']] = $row;
        }
        $merged = [];
        foreach ($sarQData as $qRow) {
            $time = $qRow['Time'];
            $mergedRow = $qRow;

            if (isset($bDataMap[$time])) {
                foreach (self::B_COLUMNS_TO_MERGE as $column) {
                    $mergedRow[$column] = $bDataMap[$time][$column] ?? '';
                }
            } else {
                foreach (self::B_COLUMNS_TO_MERGE as $column) {
                    $mergedRow[$column] = '';
                }
            }

            $merged[] = $mergedRow;
        }

        return $merged;
    }

    private function createFinalHeader(array $qHeader): array
    {
        return array_merge($qHeader, array_diff(self::B_COLUMNS_TO_MERGE, $qHeader));
    }
}

class SarTableRenderer
{
    private $csrfToken;

    public function __construct(string $csrfToken)
    {
        $this->csrfToken = $csrfToken;
    }

    public function render(array $sarData): string
    {
        if (!$sarData['success']) {
            return "<div class='imh-alert'>{$sarData['error']}</div>";
        }
        $output = $this->renderExplanation();
        $output .= $this->renderTable($sarData['header'], $sarData['data']);
        $output .= $this->renderFooterNotes();
        return $output;
    }

    private function renderExplanation(): string
    {
        return "
        <div class='imh-box--narrow'>
            <p class='imh-larger-text imh-box.margin-bottom'><a href='https://github.com/sysstat/sysstat' target='_blank'>sysstat</a> collects, reports and saves system activity information.</p>
            <h3>Queue length and load average statistics (<code>sar -q</code>)</h3>
            <p>
                <strong>runq-sz</strong> - Number of processes waiting for run time (run queue size)<br/>
                <strong>plist-sz</strong> - Number of processes in the process list<br/>
                <strong>ldavg-1</strong> - System load average for the last 1 minute<br/>
                <strong>ldavg-5</strong> - System load average for the last 5 minutes<br/>
                <strong>ldavg-15</strong> - System load average for the last 15 minutes<br/>
                <strong>blocked</strong> - Number of processes currently blocked, waiting for I/O<br/>
            </p>
            <h3>Paging statistics (<code>sar -B</code>)</h3>
            <p>
                <strong>pgpgin/s</strong> - Kilobytes paged in from disk per second<br/>
                <strong>pgpgout/s</strong> - Kilobytes paged out to disk per second<br/>
                <strong>fault/s</strong> - Number of page faults per second<br/>
                <strong>majflt/s</strong> - Number of major page faults per second (requiring disk access)
            </p>
            <h3>Legend</h3>
            <table>
                <tr>
                <td class='high-load-cell legend-cell'>High Outlier</td>
                </tr>
                <tr>
                <td class='moderate-load-cell legend-cell'>Moderate High</td>
                </tr>
                <tr>
                <td class='low-load-cell legend-cell'>Moderate Low</td>
                </tr>
                <tr>
                <td class='very-low-load-cell legend-cell'>Low Outlier</td>
                </tr>
            </table>
        </div>";
    }

    private function renderTable(array $header, array $data): string
    {
        $output = "<table class='sys-snap-tables'><thead>";

        foreach ($header as $column) {
            $output .= "<th>" . htmlspecialchars($column) . "</th>";
        }

        $output .= "</thead><tbody>";
        $previousTime = null;
        foreach ($data as $index => $row) {
            $timeInterval = $this->calculateTimeInterval($row['Time'], $previousTime);
            $rowClass = ($index % 2 === 1) ? " class='imh-table-alt'" : "";

            $output .= "<tr{$rowClass}>";

            foreach ($header as $columnIndex => $column) {
                if ($columnIndex === 0) {
                    $output .= $this->renderTimeCell($row[$column], $timeInterval);
                } else {
                    $output .= "<td class='text-right'>" . htmlspecialchars($row[$column] ?? '') . "</td>";
                }
            }

            $output .= "</tr>";
            $previousTime = $this->parseTime($row['Time']);
        }
        return $output . "</tbody></table>";
    }

    private function calculateTimeInterval(string $currentTimeStr, ?DateTime $previousTime): array
    {
        $currentTime = $this->parseTime($currentTimeStr);
        if (!$currentTime) {
            return ['start_hour' => 0, 'start_min' => 0, 'end_hour' => 0, 'end_min' => 0];
        }
        if ($previousTime) {
            $startTime = $previousTime;
            $endTime = $currentTime;
        } else {
            $startTime = clone $currentTime;
            $endTime = clone $currentTime;
            $endTime->modify('+1 minute');
        }
        return [
            'start_hour' => (int)$startTime->format('H'),
            'start_min' => (int)$startTime->format('i'),
            'end_hour' => (int)$endTime->format('H'),
            'end_min' => (int)$endTime->format('i')
        ];
    }

    private function parseTime(string $timeStr): ?DateTime
    {
        $format = (stripos($timeStr, 'AM') !== false || stripos($timeStr, 'PM') !== false)
            ? 'h:i:s A'
            : 'H:i:s';

        return DateTime::createFromFormat($format, $timeStr) ?: null;
    }

    private function renderTimeCell(string $time, array $interval): string
    {
        return "
        <td class='text-right'>
            <form method='post' style='display:inline;' class='sar-time-link-form'>
                <input type='hidden' name='csrf_token' value='" . htmlspecialchars($this->csrfToken) . "'>
                <input type='hidden' name='form' value='time_range'>
                <input type='hidden' name='start_hour' value='{$interval['start_hour']}'>
                <input type='hidden' name='start_min' value='{$interval['start_min']}'>
                <input type='hidden' name='end_hour' value='{$interval['end_hour']}'>
                <input type='hidden' name='end_min' value='{$interval['end_min']}'>
                <a href='#' onclick='this.closest(\"form\").submit(); return false;' title='View sys-snap for this interval'>" . htmlspecialchars($time) . "</a>
            </form>
        </td>";
    }

    private function renderFooterNotes(): string
    {
        return "
        <p class='imh-small-note'>Click a time to load sys-snap for that interval.</p>
        <p class='imh-small-note'>Values are from the most recent sar -q and sar -B samples.</p>";
    }
}

// Usage
echo '<div id="tab-loadavg" class="tab-content">';


$processor = new SarDataProcessor();
$renderer = new SarTableRenderer($CSRF_TOKEN);
$sarData = $processor->getSarData();
echo $renderer->render($sarData);

echo "<script>
window.sysSnapSarLoadavgData = " . json_encode($sarData['data']) . ";
window.sysSnapSarPagingData = " . json_encode($sarData['data']) . ";
</script>";
echo '<div class="imh-box imh-box.margin-bottom"><div id="LinechartLoadavg"></div></div>';
echo '<div class="imh-box imh-box.margin-bottom"><div id="LinechartPaging"></div></div>';

echo '</div>'; // Close tab-loadavg







// JavaScript for charts and interactivity

$jsPath = $isCWPServer ? 'design/js/imh-snap-stat.js' : 'imh-snap-stat.js';

if ($isCPanelServer) {
    echo '<script type="module" crossorigin src="imh-snap-stat.js"></script>';
} else {
    echo '<script type="module" crossorigin src="' . htmlspecialchars($jsPath) . '"></script>';
}



// ==========================
// 7. HTML Footer
// ==========================

echo '<div class="imh-footer-box"><img src="' . htmlspecialchars($img_src) . '" alt="sys-snap" class="imh-footer-img" /><p><a href="https://github.com/CpanelInc/tech-SysSnapv2" target="_blank">sys-snap</a> by Bryan Christensen.</p><p>Plugin by <a href="https://inmotionhosting.com" target="_blank">InMotion Hosting</a>.</p></div>';




if ($isCPanelServer) {
    WHM::footer();
} else {
    echo '</div>';
};
