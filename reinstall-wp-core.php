<?php
// Script: reinstall-wp-core.php
// Run in root WordPress. Make sure to backup before execution!

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(300);

session_start();

// Debugging & environment checks
if (!function_exists('curl_init')) exit('cURL is not enabled on this server.');
if (!class_exists('ZipArchive')) exit('ZipArchive is not enabled on this server.');
if (!function_exists('file_get_contents')) exit('file_get_contents is not enabled on this server.');
if (session_status() !== PHP_SESSION_ACTIVE) exit('Session could not be started.');
try { $test = bin2hex(random_bytes(4)); } catch (Exception $e) { exit('random_bytes() failed: ' . $e->getMessage()); }

// Multi-language array
$langs = [
    'id' => [
        'title' => 'Wordpress Core Update',
        'current_version' => 'Versi WordPress saat ini',
        'latest_version' => 'Versi WordPress terbaru',
        'already_latest' => 'WordPress Anda sudah versi terbaru.',
        'continue_update' => 'Lanjutkan Update',
        'no_update' => 'Tidak, Kembali',
        'update_running' => 'Proses update sedang berjalan...Mohon tunggu beberapa saat.',
        'success' => 'Install ulang WordPress core selesai!',
        'download_failed' => 'Gagal mengunduh WordPress terbaru.',
        'extract_failed' => 'Gagal mengekstrak file zip',
        'extract_not_found' => 'Gagal menemukan folder hasil ekstrak',
        'delete_old' => 'Menghapus file core WordPress lama...',
        'copy_new' => 'Menyalin file core WordPress terbaru...',
        'clean_temp' => 'Membersihkan file sementara...',
        'force_mode' => 'Mode force aktif. Install ulang WordPress core...',
        'read_version_failed' => 'Gagal membaca versi WordPress. Pastikan file <code>wp-includes/version.php</code> ada dan server dapat mengakses internet.',
        'back' => 'Kembali',
        'security_delete' => 'Demi keamanan, hapus file <code>reinstall-wp-core.php</code> setelah selesai digunakan.',
        'perm_warn' => 'PERINGATAN: Permission file/folder berikut terlalu longgar (777). Segera ubah ke 755 (folder) atau 644 (file):',
        'file_copy_failed' => 'Gagal menyalin file:',
        'file_delete_failed' => 'Gagal menghapus file:',
        'dir_delete_failed' => 'Gagal menghapus folder:',
    ],
    'en' => [
        'title' => 'WordPress Core Update',
        'current_version' => 'Current WordPress version',
        'latest_version' => 'Latest WordPress version',
        'already_latest' => 'Your WordPress is already up to date.',
        'continue_update' => 'Continue Update',
        'no_update' => 'No, Back',
        'update_running' => 'Update process is running...Please wait.',
        'success' => 'WordPress core reinstall completed!',
        'download_failed' => 'Failed to download the latest WordPress.',
        'extract_failed' => 'Failed to extract zip file',
        'extract_not_found' => 'Failed to find extracted folder',
        'delete_old' => 'Deleting old WordPress core files...',
        'copy_new' => 'Copying new WordPress core files...',
        'clean_temp' => 'Cleaning up temporary files...',
        'force_mode' => 'Force mode active. Reinstalling WordPress core...',
        'read_version_failed' => 'Failed to read WordPress version. Make sure <code>wp-includes/version.php</code> exists and the server can access the internet.',
        'back' => 'Back',
        'security_delete' => 'For security, please delete <code>reinstall-wp-core.php</code> after use.',
        'perm_warn' => 'WARNING: The following file/folder permissions are too loose (777). Change to 755 (folder) or 644 (file) immediately:',
        'file_copy_failed' => 'Failed to copy file:',
        'file_delete_failed' => 'Failed to delete file:',
        'dir_delete_failed' => 'Failed to delete directory:',
    ],
];
// Detection language from ?lang= parameter, default to 'id'
$lang_code = isset($_GET['lang']) && isset($langs[$_GET['lang']]) ? $_GET['lang'] : 'id';
$lang = $langs[$lang_code];

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// Permission check
function check_permission($path) {
    if (!file_exists($path)) return false;
    $perm = substr(sprintf('%o', fileperms($path)), -3);
    return $perm === '777';
}
$perm_warnings = [];
$perm_targets = [
    'wp-content',
    'wp-includes',
    'wp-config.php',
];
foreach ($perm_targets as $target) {
    $full = __DIR__ . DIRECTORY_SEPARATOR . $target;
    if (file_exists($full) && check_permission($full)) {
        $perm_warnings[] = $target;
    }
}

function get_current_wp_version() {
    $version_file = __DIR__ . '/wp-includes/version.php';
    if (!file_exists($version_file)) return false;
    $version = null;
    $fp = fopen($version_file, 'r');
    while (($line = fgets($fp)) !== false) {
        if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $line, $matches)) {
            $version = $matches[1];
            break;
        }
    }
    fclose($fp);
    return $version;
}

function get_latest_wp_version() {
    $api_url = 'https://api.wordpress.org/core/version-check/1.7/';
    $json = file_get_contents($api_url);
    if (!$json) return false;
    $data = json_decode($json, true);
    if (isset($data['offers'][0]['version'])) {
        return $data['offers'][0]['version'];
    }
    return false;
}

function download_latest_wp($dest) {
    $latest_url = 'https://wordpress.org/latest.zip';
    $file = fopen($dest, 'w');
    $ch = curl_init($latest_url);
    curl_setopt($ch, CURLOPT_FILE, $file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_exec($ch);
    curl_close($ch);
    fclose($file);
    return file_exists($dest);
}

function rrmdir($dir) {
    global $lang;
    if (!is_dir($dir)) return;
    $objects = scandir($dir);
    foreach ($objects as $object) {
        if ($object == "." || $object == "..") continue;
        $path = $dir . DIRECTORY_SEPARATOR . $object;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            if (!unlink($path)) {
                echo "<div class='error'>{$lang['file_delete_failed']} $path</div>";
            }
        }
    }
    if (!rmdir($dir)) {
        echo "<div class='error'>{$lang['dir_delete_failed']} $dir</div>";
    }
}

function delete_wp_core_files($exclude = ['wp-content', 'wp-config.php', 'reinstall-wp-core.php', 'wordpress-core', '.', '..']) {
    global $lang;
    $root = __DIR__;
    $files = scandir($root);
    foreach ($files as $file) {
        if (in_array($file, $exclude)) continue;
        $path = $root . DIRECTORY_SEPARATOR . $file;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            if (!unlink($path)) {
                echo "<div class='error'>{$lang['file_delete_failed']} $path</div>";
            }
        }
    }
}

function copy_dir($src, $dst, $exclude = ['wp-content', 'wp-config.php']) {
    global $lang;
    if (!is_dir($src)) {
        echo "<div class='error'>Folder sumber tidak ditemukan: $src</div>";
        return;
    }
    $dir = opendir($src);
    @mkdir($dst);
    while(false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (in_array($file, $exclude)) continue;
            if (is_dir($src . '/' . $file)) {
                copy_dir($src . '/' . $file, $dst . '/' . $file, $exclude);
            } else {
                if (!copy($src . '/' . $file, $dst . '/' . $file)) {
                    echo "<div class='error'>{$lang['file_copy_failed']} $file</div>";
                }
            }
        }
    }
    closedir($dir);
}

// Main logic
$force = false;
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['force']) && $_POST['force'] == '1'
) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        exit('CSRF token mismatch.');
    }
    $force = true;
}

$current_version = get_current_wp_version();
$latest_version = get_latest_wp_version();

?><!DOCTYPE html>
<html lang="<?= $lang_code ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $lang['title'] ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #e0e7ef 0%, #b6e0fe 100%);
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background: rgba(255, 255, 255, 0.18);
            border-radius: 18px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.18);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1.5px solid rgba(255, 255, 255, 0.28);
            padding: 32px 24px;
        }
        h1 {
            color: #0394d8;
            font-size: 1.7em;
            margin-bottom: 0.5em;
            letter-spacing: 1px;
            text-align: center;
        }
        .info {
            margin-bottom: 1.5em;
            font-size: 1.1em;
            color: #222;
        }
        .success {
            color: #27ae60;
        }
        .error {
            color: #c0392b;
        }
        .btn-group {
            display: flex;
            gap: 16px;
            margin-top: 24px;
            justify-content: center;
        }
        button, .btn {
            background: rgba(3, 148, 216, 0.85);
            color: #fff;
            border: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-size: 1em;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 8px 0 rgba(3, 148, 216, 0.10);
            border: 1.5px solid rgba(255,255,255,0.25);
            backdrop-filter: blur(2px);
        }
        button:hover, .btn:hover {
            background: rgba(3, 148, 216, 1);
            box-shadow: 0 4px 16px 0 rgba(3, 148, 216, 0.18);
        }
        .btn-secondary {
            background: rgba(170, 170, 170, 0.7);
            color: #fff;
        }
        .log {
            background: rgba(255,255,255,0.22);
            border-radius: 12px;
            padding: 16px;
            font-size: 0.98em;
            margin-top: 24px;
            white-space: pre-line;
            border: 1.5px solid rgba(255,255,255,0.18);
            box-shadow: 0 2px 8px 0 rgba(31, 38, 135, 0.08);
            color: #222;
        }
        .perm-warning {
            background: #fffbe6;
            color: #b8860b;
            border: 1px solid #ffe58f;
            border-radius: 8px;
            padding: 12px 18px;
            margin-bottom: 18px;
            font-size: 1em;
        }
        /* Overlay Loading */
        .overlay {
            display: none;
            position: fixed;
            z-index: 9999;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255,255,255,0.35);
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .overlay.active {
            display: flex;
        }
        .spinner {
            border: 6px solid rgba(255,255,255,0.4);
            border-top: 6px solid #0394d8;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg);}
            100% { transform: rotate(360deg);}
        }
        .loading-text {
            margin-top: 18px;
            font-size: 1.1em;
            color: #0394d8;
            text-align: center;
            text-shadow: 0 1px 8px rgba(255,255,255,0.5);
        }
        @media (max-width: 600px) {
            .container { padding: 16px 6px; }
            .btn-group { flex-direction: column; gap: 10px; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><?= $lang['title'] ?></h1>
    <?php if (!empty($perm_warnings)): ?>
        <div class="perm-warning">
            <?= $lang['perm_warn'] ?><br>
            <ul style="margin:8px 0 0 18px; padding:0; text-align:left;">
                <?php foreach($perm_warnings as $pw): ?>
                    <li><?= htmlspecialchars($pw) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <div class="info">
        <strong><?= $lang['current_version'] ?>:</strong> <?= htmlspecialchars($current_version ?: ($lang_code == 'id' ? 'Tidak diketahui' : 'Unknown')) ?><br>
        <strong><?= $lang['latest_version'] ?>:</strong> <?= htmlspecialchars($latest_version ?: ($lang_code == 'id' ? 'Tidak diketahui' : 'Unknown')) ?>
    </div>
    <?php if (!$current_version || !$latest_version): ?>
        <div class='error'><?= $lang['read_version_failed'] ?></div>
    <?php elseif (version_compare($current_version, $latest_version, '>=') && !$force): ?>
        <div class="success"><?= $lang['already_latest'] ?></div>
        <form method="post" style="margin-top: 24px;">
            <input type="hidden" name="force" value="1">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <div class="btn-group">
                <button type="submit"><?= $lang['continue_update'] ?></button>
                <a href="./?lang=<?= $lang_code ?>" class="btn btn-secondary"><?= $lang['no_update'] ?></a>
            </div>
        </form>
    <?php else: ?>
        <div class='log'>
        <?php
        if (version_compare($current_version, $latest_version, '>=') && $force) {
            echo $lang['force_mode'] . "\n";
        }
        // Download
        $tmp_zip = __DIR__ . '/latest.zip';
        $tmp_dir = __DIR__ . '/wordpress-core';
        if (is_dir($tmp_dir)) rrmdir($tmp_dir);
        echo $lang['download_failed'] . "\n";
        if (!download_latest_wp($tmp_zip)) {
            echo "<span class='error'>" . $lang['download_failed'] . "</span></div>";
        } else {
            // Extract
            $zip = new ZipArchive;
            $res = $zip->open($tmp_zip);
            if ($res === TRUE) {
                $zip->extractTo($tmp_dir);
                $zip->close();
                echo "Ekstrak selesai.\n";
            } else {
                echo "<span class='error'>" . $lang['extract_failed'] . " (Kode: $res).</span></div>";
                if (file_exists($tmp_zip)) unlink($tmp_zip);
                exit;
            }
            // Check extract result
            $wp_extract_path = $tmp_dir . '/wordpress';
            if (!is_dir($wp_extract_path)) {
                $wp_files = array_diff(scandir($tmp_dir), ['.', '..']);
                if (in_array('index.php', $wp_files) && in_array('wp-includes', $wp_files)) {
                    $wp_extract_path = $tmp_dir;
                    echo "File WordPress diekstrak langsung di $tmp_dir\n";
                } else {
                    rrmdir($tmp_dir);
                    if (file_exists($tmp_zip)) unlink($tmp_zip);
                    echo "<span class='error'>" . $lang['extract_not_found'] . ": $wp_extract_path</span></div>";
                    exit;
                }
            }
            // Delete old core
            echo $lang['delete_old'] . "\n";
            delete_wp_core_files();
            // Copy new core
            echo $lang['copy_new'] . "\n";
            copy_dir($wp_extract_path, __DIR__);
            // Delete temporary files
            echo $lang['clean_temp'] . "\n";
            rrmdir($tmp_dir);
            if (file_exists($tmp_zip)) unlink($tmp_zip);
            echo "<span class='success'>" . $lang['success'] . "</span>\n";
            echo '<div class="btn-group" style="margin-top:20px;"><a href="./?lang=' . $lang_code . '" class="btn btn-secondary">' . $lang['back'] . '</a></div>';
            echo '<div class="perm-warning" style="margin-top:18px;">' . $lang['security_delete'] . '</div>';
            echo '</div>';
        }
        ?>
        </div>
    <?php endif; ?>
</div>
<div class="overlay" id="overlay-loading">
    <div>
        <div class="spinner"></div>
        <div class="loading-text"><?= nl2br(htmlspecialchars($lang['update_running'])) ?></div>
    </div>
</div>
<script>
    // Show overlay loading when form submit
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.querySelector('form[method="post"]');
        if (form) {
            form.addEventListener('submit', function() {
                document.getElementById('overlay-loading').classList.add('active');
            });
        }
    });
</script>
</body>
</html>
