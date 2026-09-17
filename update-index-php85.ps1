$ErrorActionPreference = 'Stop'

function Update-PhpFile([string]$path, [scriptblock]$transform) {
    if (-not (Test-Path $path)) {
        throw "File not found: $path"
    }
    $content = [System.IO.File]::ReadAllText($path)
    $updated = & $transform $content
    if ($updated -eq $content) {
        Write-Host "No changes required: $path"
        return
    }
    [System.IO.File]::WriteAllText($path, $updated, [System.Text.UTF8Encoding]::new($false))
    Write-Host "Updated: $path"
}

$indexPath = Join-Path $PSScriptRoot 'index.php'
$interfacePath = Join-Path $PSScriptRoot 'interface.php'

Update-PhpFile $indexPath {
    param($content)

    # PHP session is shared by index.php and interface.php.
    if ($content -notmatch '(?m)^session_start\(\);') {
        $content = $content.Replace("<?php`r`n", "<?php`r`nsession_start();`r`n")
        if ($content -notmatch '(?m)^session_start\(\);') {
            $content = $content.Replace("<?php`n", "<?php`nsession_start();`n")
        }
    }

    $content = $content.Replace("`$routerHost = '192.168.0.1';", "`$routerHost = '192.168.0.1:8443';")
    $content = [regex]::Replace($content, '(?m)^\s*curl_close\(\$ch\);\s*\r?\n', '')

    # Allow AJAX to reuse the authenticated PHP session when credentials are not posted.
    $oldRequest = @'
    $user = trim((string)($body['username'] ?? $_POST['username'] ?? ''));
    $pass = (string)($body['password'] ?? $_POST['password'] ?? '');

    if ($user === '') {
        $user = 'root';
    }

    if ($pass === '') {
        throw new RuntimeException('No router password supplied.');
    }
'@
    $newRequest = @'
    $user = trim((string)($body['username'] ?? $_POST['username'] ?? ($_SESSION['router_user'] ?? '')));
    $pass = (string)($body['password'] ?? $_POST['password'] ?? ($_SESSION['router_pass'] ?? ''));

    if ($user === '') {
        $user = 'root';
    }

    if ($pass === '') {
        throw new RuntimeException('No router password supplied.');
    }
'@
    $content = $content.Replace($oldRequest, $newRequest)

    # Only save credentials after the router calls have succeeded, so a bad login is never persisted.
    $marker = "        header('Content-Type: application/json; charset=utf-8');`r`n        echo json_encode(["
    $sessionSave = "        `$_SESSION['router_user'] = `$routerUser;`r`n        `$_SESSION['router_pass'] = `$routerPass;`r`n`r`n        header('Content-Type: application/json; charset=utf-8');`r`n        echo json_encode(["
    if ($content.Contains($marker) -and $content -notmatch "\$_SESSION\['router_user'\] = \$routerUser") {
        $content = $content.Replace($marker, $sessionSave)
    } else {
        $markerLf = "        header('Content-Type: application/json; charset=utf-8');`n        echo json_encode(["
        $sessionSaveLf = "        `$_SESSION['router_user'] = `$routerUser;`n        `$_SESSION['router_pass'] = `$routerPass;`n`n        header('Content-Type: application/json; charset=utf-8');`n        echo json_encode(["
        if ($content.Contains($markerLf) -and $content -notmatch "\$_SESSION\['router_user'\] = \$routerUser") {
            $content = $content.Replace($markerLf, $sessionSaveLf)
        }
    }

    return $content
}

Update-PhpFile $interfacePath {
    param($content)

    if ($content -notmatch '(?m)^session_start\(\);') {
        $content = $content.Replace("<?php`r`n", "<?php`r`nsession_start();`r`n")
        if ($content -notmatch '(?m)^session_start\(\);') {
            $content = $content.Replace("<?php`n", "<?php`nsession_start();`n")
        }
    }

    $content = $content.Replace("`$routerHost = '192.168.0.1';", "`$routerHost = '192.168.0.1:8443';")
    $content = [regex]::Replace($content, '(?m)^\s*curl_close\(\$ch\);\s*\r?\n', '')

    # Remove hard-coded RouterOS credentials and use the authenticated dashboard session.
    $content = [regex]::Replace($content, "(?m)^\s*\$routerUser\s*=\s*'[^']*';\s*\r?\n", '')
    $content = [regex]::Replace($content, "(?m)^\s*\$routerPass\s*=\s*'[^']*';.*\r?\n", '')

    $configNeedle = "`$sampleDelayUs = 1000000;"
    if ($content.Contains($configNeedle) -and $content -notmatch "\$_SESSION\['router_user'\]") {
        $sessionConfig = @'
$sampleDelayUs = 1000000;

$routerUser = (string)($_SESSION['router_user'] ?? '');
$routerPass = (string)($_SESSION['router_pass'] ?? '');

if ($routerUser === '' || $routerPass === '') {
    header('Location: index.php');
    exit;
}
'@
        $content = $content.Replace($configNeedle, $sessionConfig.TrimEnd())
    }

    return $content
}

Write-Host ''
Write-Host 'Shared authentication migration complete.'
Write-Host 'index.php now stores a successful RouterOS login in the PHP session.'
Write-Host 'interface.php reuses that session and redirects to index.php when not logged in.'
Write-Host 'Both pages use 192.168.0.1:8443 and contain no curl_close() call.'