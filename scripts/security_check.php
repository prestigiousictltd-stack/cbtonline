<?php
declare(strict_types=1);
/**
 * scripts/security_check.php
 * CLI-only static analysis for the ServiceHub application.
 *
 * Usage:
 *   php scripts/security_check.php             # scan + summary
 *   php scripts/security_check.php --strict    # exit 1 on ANY finding
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root   = dirname(__DIR__);
$strict = in_array('--strict', $argv ?? [], true);

$skips = [
    '/storage/logs/',
    '/storage/uploads/',
    '/.git/',
    '/vendor/',
    '/node_modules/',
];

$high = [];
$med  = [];
$low  = [];

$phpFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($phpFiles as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) continue;
    $path = str_replace('\\', '/', $file->getPathname());
    $rel  = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');

    foreach ($skips as $s) {
        if (str_contains('/' . $rel, $s)) continue 2;
    }

    if ($file->getExtension() === 'php') {
        $src = (string) file_get_contents($path);
        scanPhp($rel, $src, $high, $med, $low);
    } elseif (in_array(strtolower($file->getExtension()), ['phtml','php3','php4','php5','php7','phar'], true)) {
        $high[] = "$rel: dangerous executable extension present.";
    }
}

// --- Structural checks ----------------------------------------------------
$dirsNeedingHtaccess = ['config', 'app', 'storage', 'database', 'scripts', 'cron'];
foreach ($dirsNeedingHtaccess as $d) {
    $ht = $root . '/' . $d . '/.htaccess';
    if (!is_file($ht)) {
        $high[] = "Missing $d/.htaccess";
    }
}

if (is_file($root . '/.env')) {
    $perms = fileperms($root . '/.env') & 0o777;
    if (($perms & 0o004) !== 0) {
        $med[] = ".env is world-readable (perms " . decoct($perms) . "). Recommend 0600.";
    }
}

foreach (['storage/logs', 'storage/uploads'] as $d) {
    $full = $root . '/' . $d;
    if (is_dir($full)) {
        $perms = fileperms($full) & 0o777;
        if (($perms & 0o002) !== 0) {
            $high[] = "$d is world-writable (perms " . decoct($perms) . "). Recommend 0755 (or 0700).";
        }
    }
}

// --- Report ---------------------------------------------------------------
$renderGroup = function (string $title, array $items, string $color): void {
    if (!$items) return;
    echo "\n" . $color . "== $title ==\033[0m\n";
    foreach ($items as $it) {
        echo "  • $it\n";
    }
};

echo "=== ServiceHub NG — security check ===\n";
echo 'Root: ' . $root . "\n";

$renderGroup('HIGH', $high, "\033[31m");
$renderGroup('MEDIUM', $med, "\033[33m");
$renderGroup('LOW', $low, "\033[36m");

$totalHigh = count($high);
$totalMed  = count($med);
$totalLow  = count($low);

echo "\n=== Summary ===\n";
echo "HIGH: $totalHigh  MEDIUM: $totalMed  LOW: $totalLow\n";

if ($totalHigh > 0) {
    echo "\n\033[31mFAILED with $totalHigh high-severity finding(s).\033[0m\n";
    exit(1);
}
if ($strict && ($totalMed + $totalLow) > 0) {
    echo "\n\033[33mStrict mode: exiting 1 on $totalMed medium / $totalLow low findings.\033[0m\n";
    exit(1);
}
echo "\n\033[32mNo high-severity issues found.\033[0m\n";
exit(0);

// ==========================================================================

function scanPhp(string $rel, string $src, array &$high, array &$med, array &$low): void
{
    // 1. Dangerous functions
    $dangerousFuncs = ['eval', 'assert', 'system', 'exec', 'passthru',
                       'shell_exec', 'popen', 'proc_open', 'pcntl_exec'];
    foreach ($dangerousFuncs as $fn) {
        if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $src, $m, PREG_OFFSET_CAPTURE)) {
            // Skip the scanner's own name list
            if (str_contains($rel, 'scripts/security_check.php')) continue;
            $high[] = "$rel: call to " . $m[0][0];
        }
    }

    // 2. include/require with a variable — dynamic path inclusion
    if (preg_match('/\b(include|include_once|require|require_once)\s*\(?\s*[\$]/i', $src)) {
        if (!str_contains($rel, 'bootstrap.php') && !str_contains($rel, 'security_check.php')) {
            $high[] = "$rel: include/require with variable path (LFI risk).";
        }
    }

    // 3. extract() and parse_str() with single argument
    if (preg_match('/\bextract\s*\(/i', $src)) {
        $high[] = "$rel: extract() used (variable injection).";
    }
    if (preg_match('/\bparse_str\s*\(\s*[^,\)]+\s*\)\s*;/i', $src)) {
        $med[] = "$rel: parse_str() without second argument (writes into local scope).";
    }

    // 4. Direct echoes of superglobals
    $superGlobals = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', '_SESSION', '_FILES'];
    foreach ($superGlobals as $sg) {
        if (preg_match('/\b(echo|print)\s+\$' . $sg . '\b/i', $src)) {
            $high[] = "$rel: direct output of \$$sg without escaping (XSS risk).";
        }
    }

    // 5. String interpolation of superglobals inside SQL-ish strings
    $sqlKeywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'WHERE', 'ORDER BY', 'GROUP BY'];
    foreach ($superGlobals as $sg) {
        // e.g.  "SELECT * FROM t WHERE id = {$_GET['id']}"
        if (preg_match_all('/"[^"]*\{?\$' . $sg . '[^\}"]*\}?[^"]*"/i', $src, $mm)) {
            foreach ($mm[0] as $candidate) {
                foreach ($sqlKeywords as $kw) {
                    if (stripos($candidate, $kw) !== false) {
                        $high[] = "$rel: possible SQL with \$$sg interpolation.";
                        break 2;
                    }
                }
            }
        }
    }

    // 6. move_uploaded_file target must live inside storage/uploads/
    if (preg_match_all('/move_uploaded_file\s*\([^,]+,\s*([^\)]+)\)/i', $src, $mm)) {
        foreach ($mm[1] as $dest) {
            $normalised = preg_replace('/\s+/', '', $dest);
            if (!str_contains($normalised, 'storage/uploads') && !str_contains($normalised, 'storage\/uploads')) {
                // Allow when the destination is passed as a variable
                if (!preg_match('/\$/', $normalised)) {
                    $med[] = "$rel: move_uploaded_file destination does not reference storage/uploads.";
                }
            }
        }
    }

    // 7. display_errors=On hard-coded outside bootstrap
    if (preg_match("/ini_set\\s*\\(\\s*['\"]display_errors['\"]\\s*,\\s*['\"]1['\"]\\s*\\)/i", $src)) {
        if (!str_contains($rel, 'bootstrap.php') && !str_contains($rel, 'security_check.php')) {
            $med[] = "$rel: display_errors enabled explicitly (make it DEBUG-only).";
        }
    }

    // 8. Hard-coded credentials heuristics (very rough, low-signal)
    if (preg_match('/password\s*=\s*[\'"][^\'"\s]{6,}[\'"]/i', $src, $m)
        && !str_contains($rel, 'create_super_admin.php')
        && !str_contains($rel, 'security_check.php')) {
        $low[] = "$rel: possible hard-coded password literal.";
    }

    // 9. base64-decode + eval combo
    if (preg_match('/base64_decode\s*\(/i', $src) && preg_match('/\beval\s*\(/i', $src)) {
        $high[] = "$rel: base64_decode + eval (obfuscated payload).";
    }

    // 10. `$_REQUEST` usage (ambiguous source)
    if (preg_match('/\$_REQUEST\b/', $src)) {
        $low[] = "$rel: \$_REQUEST used — prefer explicit \$_GET / \$_POST.";
    }
}