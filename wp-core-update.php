<?php
/**
 * WPCOREUPDATE Modern WordPress Core Updater & Reinstaller
 * Description: Safely reinstall or update WordPress core files.
 * Warning: Always backup your website before running this script!
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(600); // Ditingkatkan ke 10 menit

session_start();

// Environment checks
$env_errors =[];
if (!function_exists('curl_init')) $env_errors[] = 'cURL is not enabled.';
if (!class_exists('ZipArchive')) $env_errors[] = 'ZipArchive is not enabled.';
if (!function_exists('file_get_contents')) $env_errors[] = 'file_get_contents is disabled.';
if (session_status() !== PHP_SESSION_ACTIVE) $env_errors[] = 'Session failed to start.';
try { bin2hex(random_bytes(4)); } catch (Exception $e) { $env_errors[] = 'random_bytes() failed.'; }

if (!empty($env_errors)) {
    die("<div style='font-family:sans-serif; padding: 20px; background:#fee2e2; color:#991b1b; border:1px solid #f87171; border-radius:8px; max-width:600px; margin:40px auto;'>
        <h3>Server Configuration Error</h3><ul><li>" . implode("</li><li>", $env_errors) . "</li></ul></div>");
}

// Multi-language definitions
$langs = [
    'id' =>[
        'title' => 'WordPress Core Updater',
        'subtitle' => 'Perbaiki atau perbarui file inti WordPress dengan aman.',
        'curr_ver' => 'Versi Saat Ini',
        'latest_ver' => 'Versi Terbaru',
        'status_uptodate' => 'WordPress Anda sudah versi terbaru.',
        'status_outdated' => 'Pembaruan tersedia untuk WordPress Anda.',
        'btn_update' => 'Mulai Proses Update',
        'btn_reinstall' => 'Install Ulang (Force Mode)',
        'btn_back' => 'Kembali',
        'lang_toggle' => 'English',
        'lang_link' => 'en',
        'processing' => 'Memproses...',
        'wait_msg' => 'Mengunduh dan mengekstrak file. Jangan tutup halaman ini.',
        'warn_perm' => 'Izin File Terlalu Longgar (777)',
        'warn_perm_desc' => 'Segera ubah izin folder ke 755 dan file ke 644 demi keamanan server Anda:',
        'sec_msg' => 'Sangat Disarankan: Hapus file script ini (<code>reinstall-wp-core.php</code>) setelah proses selesai.',
        'log_start' => 'Memulai proses...',
        'log_dl' => 'Mengunduh WordPress terbaru...',
        'log_ext' => 'Mengekstrak file ZIP...',
        'log_del' => 'Menghapus folder wp-admin & wp-includes lama...',
        'log_copy' => 'Menyalin file core terbaru...',
        'log_clean' => 'Membersihkan file sementara...',
        'err_dl' => 'Gagal mengunduh file WordPress.',
        'err_ext' => 'Gagal mengekstrak ZIP.',
        'err_read' => 'Tidak dapat membaca versi WordPress. Pastikan file wp-includes/version.php ada.',
    ],
    'en' =>[
        'title' => 'WordPress Core Updater',
        'subtitle' => 'Safely repair or update your WordPress core files.',
        'curr_ver' => 'Current Version',
        'latest_ver' => 'Latest Version',
        'status_uptodate' => 'Your WordPress is up to date.',
        'status_outdated' => 'An update is available for your WordPress.',
        'btn_update' => 'Start Update Process',
        'btn_reinstall' => 'Reinstall Core (Force)',
        'btn_back' => 'Go Back',
        'lang_toggle' => 'Bahasa Indonesia',
        'lang_link' => 'id',
        'processing' => 'Processing...',
        'wait_msg' => 'Downloading and extracting files. Please do not close this page.',
        'warn_perm' => 'Insecure File Permissions (777)',
        'warn_perm_desc' => 'Please change folder permissions to 755 and files to 644 immediately:',
        'sec_msg' => 'Highly Recommended: Delete this script file (<code>reinstall-wp-core.php</code>) after use.',
        'log_start' => 'Starting process...',
        'log_dl' => 'Downloading latest WordPress...',
        'log_ext' => 'Extracting ZIP file...',
        'log_del' => 'Deleting old wp-admin & wp-includes...',
        'log_copy' => 'Copying new core files...',
        'log_clean' => 'Cleaning up temporary files...',
        'err_dl' => 'Failed to download WordPress file.',
        'err_ext' => 'Failed to extract ZIP.',
        'err_read' => 'Cannot read WordPress version. Ensure wp-includes/version.php exists.',
    ]
];

$lang_code = (isset($_GET['lang']) && array_key_exists($_GET['lang'], $langs)) ? $_GET['lang'] : 'id';
$l = $langs[$lang_code];

// CSRF Protection
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf_token = $_SESSION['csrf_token'];

// Helper Functions
function check_permission($path) {
    if (!file_exists($path)) return false;
    return substr(sprintf('%o', fileperms($path)), -3) === '777';
}

function get_current_wp_version() {
    $file = __DIR__ . '/wp-includes/version.php';
    if (!file_exists($file)) return false;
    $content = file_get_contents($file);
    if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) return $matches[1];
    return false;
}

function get_latest_wp_version() {
    $json = @file_get_contents('https://api.wordpress.org/core/version-check/1.7/');
    if (!$json) return false;
    $data = json_decode($json, true);
    return $data['offers'][0]['version'] ?? false;
}

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    $objects = scandir($dir);
    foreach ($objects as $object) {
        if ($object == "." || $object == "..") continue;
        $path = $dir . DIRECTORY_SEPARATOR . $object;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function copy_dir($src, $dst, $exclude =[]) {
    $dir = opendir($src);
    @mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if ($file != '.' && $file != '..') {
            if (in_array($file, $exclude)) continue;
            $src_path = $src . '/' . $file;
            $dst_path = $dst . '/' . $file;
            if (is_dir($src_path)) {
                copy_dir($src_path, $dst_path, $exclude);
            } else {
                @copy($src_path, $dst_path);
            }
        }
    }
    closedir($dir);
}

// Security Check
$perm_warnings = [];
foreach (['wp-content', 'wp-includes', 'wp-config.php'] as $target) {
    if (check_permission(__DIR__ . '/' . $target)) $perm_warnings[] = $target;
}

$current_ver = get_current_wp_version();
$latest_ver = get_latest_wp_version();
$is_up_to_date = $current_ver && $latest_ver && version_compare($current_ver, $latest_ver, '>=');

// Process Update Logic
$process_logs =[];
$update_success = false;
$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($is_post && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $tmp_zip = __DIR__ . '/latest.zip';
    $tmp_dir = __DIR__ . '/wordpress-core-tmp';
    
    $process_logs[] =['type' => 'info', 'msg' => $l['log_start']];
    $process_logs[] =['type' => 'info', 'msg' => $l['log_dl']];
    
    // Download
    $fp = fopen($tmp_zip, 'w+');
    $ch = curl_init('https://wordpress.org/latest.zip');
    curl_setopt_array($ch,[
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ($http_code !== 200 || !file_exists($tmp_zip) || filesize($tmp_zip) < 10000000) {
        $process_logs[] =['type' => 'error', 'msg' => $l['err_dl']];
        @unlink($tmp_zip);
    } else {
        $process_logs[] = ['type' => 'success', 'msg' => 'Download OK.'];
        $process_logs[] = ['type' => 'info', 'msg' => $l['log_ext']];
        
        // Extract
        @rrmdir($tmp_dir);
        $zip = new ZipArchive;
        if ($zip->open($tmp_zip) === TRUE) {
            $zip->extractTo($tmp_dir);
            $zip->close();
            
            $wp_ext_path = is_dir($tmp_dir . '/wordpress') ? $tmp_dir . '/wordpress' : $tmp_dir;
            
            // SAFER DELETION: Only delete wp-admin and wp-includes
            $process_logs[] = ['type' => 'info', 'msg' => $l['log_del']];
            rrmdir(__DIR__ . '/wp-admin');
            rrmdir(__DIR__ . '/wp-includes');
            
            // Copy new core files (Skip wp-content entirely to protect user themes/plugins)
            $process_logs[] = ['type' => 'info', 'msg' => $l['log_copy']];
            copy_dir($wp_ext_path, __DIR__, ['wp-content']);
            
            $process_logs[] = ['type' => 'info', 'msg' => $l['log_clean']];
            rrmdir($tmp_dir);
            @unlink($tmp_zip);
            
            $update_success = true;
            $process_logs[] =['type' => 'success', 'msg' => 'WordPress Core updated successfully!'];
        } else {
            $process_logs[] =['type' => 'error', 'msg' => $l['err_ext']];
            @unlink($tmp_zip);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang_code ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $l['title'] ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2271b1;
            --primary-hover: #135e96;
            --bg-body: #f0f4f8;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: var(--text-main); line-height: 1.6; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .container { background: var(--card-bg); max-width: 600px; width: 100%; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); overflow: hidden; position: relative; }
        .header { background: #1e293b; padding: 30px 20px; text-align: center; color: white; position: relative; }
        .header svg { width: 50px; height: 50px; fill: white; margin-bottom: 10px; }
        .header h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 5px; }
        .header p { color: #94a3b8; font-size: 0.9rem; }
        .lang-switch { position: absolute; top: 15px; right: 15px; font-size: 0.8rem; color: #cbd5e1; text-decoration: none; border: 1px solid #475569; padding: 4px 10px; border-radius: 20px; transition: all 0.2s; }
        .lang-switch:hover { background: #334155; color: white; }
        .content { padding: 30px; }
        
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.95rem; display: flex; gap: 12px; align-items: flex-start; }
        .alert svg { flex-shrink: 0; width: 20px; height: 20px; }
        .alert-warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .alert-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        
        .version-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; }
        .card { border: 1px solid var(--border); border-radius: 12px; padding: 20px; text-align: center; background: #f8fafc; }
        .card-title { font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; font-weight: 600; }
        .card-value { font-size: 1.5rem; font-weight: 700; color: var(--text-main); }
        .card-value.outdated { color: var(--danger); }
        .card-value.uptodate { color: var(--success); }
        
        .actions { display: flex; gap: 12px; flex-direction: column; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 14px; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: all 0.2s; }
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 4px 6px -1px rgba(34, 113, 177, 0.2); }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .btn-outline { background: white; color: var(--text-main); border: 1px solid var(--border); }
        .btn-outline:hover { background: #f1f5f9; }
        
        .logs-container { background: #1e293b; border-radius: 12px; padding: 20px; margin-top: 20px; color: #e2e8f0; font-family: monospace; font-size: 0.9rem; max-height: 250px; overflow-y: auto; box-shadow: inset 0 2px 4px rgba(0,0,0,0.2); }
        .log-line { margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px dashed #334155; }
        .log-line:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .log-info { color: #60a5fa; }
        .log-success { color: #34d399; }
        .log-error { color: #f87171; }
        
        /* Loading Overlay */
        .overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(4px); display: flex; flex-direction: column; justify-content: center; align-items: center; z-index: 100; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
        .overlay.active { opacity: 1; pointer-events: all; }
        .spinner { width: 50px; height: 50px; border: 4px solid #e2e8f0; border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 20px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        @media (max-width: 480px) { .version-cards { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <a href="?lang=<?= $l['lang_link'] ?>" class="lang-switch"><?= $l['lang_toggle'] ?></a>
        <svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
            <path d="M60 0C26.9 0 0 26.9 0 60s26.9 60 60 60 60-26.9 60-60S93.1 0 60 0zm0 115.5C29.4 115.5 4.5 90.6 4.5 60c0-13.8 5-26.4 13.3-36.1l32.5 92.2c-2.4 1-5 1.7-7.6 2l1.6-4.6-21.6-61.4c-1.2-3.4-1.8-5.7-1.8-7 0-2.8 1.6-4.4 3.7-4.4 1.3 0 3 .5 4.3 1.3 4.2-7 10-12.8 17-17.1C51.6 22 55.7 20 60 20c15 0 28.1 8.2 34.6 20.4-3.5-.8-7-1-10.3-1-11.8 0-22 4.1-27.4 9.4L60 115.5zM83 45.4c0-5 2.1-9.2 5.6-11.6-5.8-5.3-13.5-8.5-21.9-8.5-6.6 0-12.8 2-17.9 5.5l26.2 73.6L83 45.4zm32.5 14.6c0 13.8-5 26.4-13.3 36.1l-24.1-68.4c7.6.5 14.5 2 20.3 4.4 5.3 5.4 17.1 19.3 17.1 27.9z"/>
        </svg>
        <h1><?= $l['title'] ?></h1>
        <p><?= $l['subtitle'] ?></p>
    </div>

    <div class="content">
        <?php if (!empty($perm_warnings)): ?>
        <div class="alert alert-warning">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            <div>
                <strong><?= $l['warn_perm'] ?></strong><br>
                <span style="font-size:0.85rem; color:#92400e;"><?= $l['warn_perm_desc'] ?></span>
                <ul style="margin-top: 5px; padding-left: 15px; font-size: 0.85rem;">
                    <?php foreach($perm_warnings as $pw) echo "<li><code>$pw</code></li>"; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_post && !empty($process_logs)): ?>
            <!-- Status After Process -->
            <?php if ($update_success): ?>
                <div class="alert alert-success">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div>
                        <strong>Success!</strong><br>
                        <?= $l['sec_msg'] ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-danger">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div>Update Failed. Check logs below.</div>
                </div>
            <?php endif; ?>

            <div class="logs-container">
                <?php foreach ($process_logs as $log): ?>
                    <div class="log-line log-<?= $log['type'] ?>">
                        [<?= strtoupper($log['type']) ?>] <?= htmlspecialchars($log['msg']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="actions" style="margin-top: 25px;">
                <a href="?" class="btn btn-outline"><?= $l['btn_back'] ?></a>
            </div>

        <?php else: ?>
            <!-- Normal View (Before Process) -->
            <?php if (!$current_ver): ?>
                <div class="alert alert-danger">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div><?= $l['err_read'] ?></div>
                </div>
            <?php endif; ?>

            <div class="version-cards">
                <div class="card">
                    <div class="card-title"><?= $l['curr_ver'] ?></div>
                    <div class="card-value <?= $is_up_to_date ? 'uptodate' : 'outdated' ?>">
                        <?= $current_ver ? htmlspecialchars($current_ver) : 'Unknown' ?>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title"><?= $l['latest_ver'] ?></div>
                    <div class="card-value uptodate">
                        <?= $latest_ver ? htmlspecialchars($latest_ver) : 'Error' ?>
                    </div>
                </div>
            </div>

            <form method="post" id="updateForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="actions">
                    <?php if (!$is_up_to_date && $current_ver): ?>
                        <div style="text-align:center; margin-bottom:10px; color:var(--warning); font-weight:500;">
                            <?= $l['status_outdated'] ?>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            <?= $l['btn_update'] ?>
                        </button>
                    <?php else: ?>
                        <div style="text-align:center; margin-bottom:10px; color:var(--success); font-weight:500;">
                            <?= $l['status_uptodate'] ?>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            <?= $l['btn_reinstall'] ?>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <h2 style="color: var(--text-main); margin-bottom: 10px;"><?= $l['processing'] ?></h2>
    <p style="color: var(--text-muted); font-size: 0.95rem; text-align: center; max-width: 300px;">
        <?= $l['wait_msg'] ?>
    </p>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('updateForm');
        if (form) {
            form.addEventListener('submit', function() {
                document.getElementById('loadingOverlay').classList.add('active');
                form.querySelector('button[type="submit"]').disabled = true;
            });
        }
    });
</script>

</body>
</html>
