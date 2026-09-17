$path = Join-Path $PSScriptRoot 'index.php'
$content = [System.IO.File]::ReadAllText($path)
$content = $content.Replace("`$routerHost = '192.168.0.1';", "`$routerHost = '192.168.0.1:8443';")
$content = [regex]::Replace($content, '(?m)^\s*curl_close\(\$ch\);\s*\r?\n', '')
[System.IO.File]::WriteAllText($path, $content, [System.Text.UTF8Encoding]::new($false))
Write-Host 'Updated index.php: RouterOS REST port 8443 and removed curl_close().'