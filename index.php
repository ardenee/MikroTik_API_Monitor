<?php
// =========================
// Configuration
// =========================
$routerHost = '192.168.0.1';
$verifyTls = false;
$timeoutSec = 10;
$sampleDelayUs = 1000000;
$preferredInterfaceOrder = [
    'FibernetInternet',
    'wan_vlan10',
    'bridge-lan',
    'LocalNetwork',
    'MGMT',
];

// =========================
// Helpers
// =========================
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatBytes(float $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max(0, $bytes);
    $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $value = $bytes / (1024 ** $pow);
    return round($value, $precision) . ' ' . $units[$pow];
}

function formatBitsPerSecond(float $bps, int $precision = 2): string
{
    $units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
    $bps = max(0, $bps);
    $pow = $bps > 0 ? floor(log($bps, 1000)) : 0;
    $pow = min($pow, count($units) - 1);
    $value = $bps / (1000 ** $pow);
    return round($value, $precision) . ' ' . $units[$pow];
}

function formatRouterOsDuration(?string $duration): string
{
    if ($duration === null || $duration === '') {
        return ' ';
    }

    if (preg_match('/^(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $duration, $m)) {
        $parts = [];
        if (!empty($m[1])) $parts[] = $m[1] . 'd';
        if (!empty($m[2])) $parts[] = $m[2] . 'h';
        if (!empty($m[3])) $parts[] = $m[3] . 'm';
        if (!empty($m[4])) $parts[] = $m[4] . 's';
        return $parts ? implode(' ', $parts) : $duration;
    }

    return $duration;
}

function normalizeBoolString($value): bool
{
    return in_array(strtolower((string)$value), ['true', 'yes', 'on'], true);
}

function requestCredentials(): array
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = [];
    }

    $user = trim((string)($body['username'] ?? $_POST['username'] ?? ''));
    $pass = (string)($body['password'] ?? $_POST['password'] ?? '');

    if ($user === '') {
        $user = 'root';
    }

    if ($pass === '') {
        throw new RuntimeException('No router password supplied.');
    }

    return [$user, $pass, $body];
}

function sortInterfaces(array &$interfaces, array $preferredOrder): void
{
    $rank = array_flip($preferredOrder);

    usort($interfaces, function ($a, $b) use ($rank) {
        $nameA = $a['name'] ?? '';
        $nameB = $b['name'] ?? '';
        $rankA = $rank[$nameA] ?? PHP_INT_MAX;
        $rankB = $rank[$nameB] ?? PHP_INT_MAX;

        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        return strnatcasecmp($nameA, $nameB);
    });
}

function routerosRequest(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec,
    string $path,
    string $method = 'GET',
    ?array $payload = null
): array {
    $url = 'https://' . $routerHost . '/rest' . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_USERPWD        => $routerUser . ':' . $routerPass,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    if (!$verifyTls) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('cURL error: ' . $curlErr);
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        $detail = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES) : $response;
        throw new RuntimeException('RouterOS HTTP ' . $httpCode . ': ' . $detail);
    }

    if ($decoded === null && trim($response) !== 'null' && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON from RouterOS: ' . json_last_error_msg() . ' | Raw: ' . substr($response, 0, 500));
    }

    return is_array($decoded) ? $decoded : [];
}

function fetchInterfaceCounters(string $routerHost, string $routerUser, string $routerPass, bool $verifyTls, int $timeoutSec): array
{
    $rows = routerosRequest($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, '/interface/print', 'POST', [
        'stats' => '',
        '.proplist' => '.id,name,comment,type,disabled,running,rx-byte,tx-byte,rx-packet,tx-packet',
    ]);

    $indexed = [];
    foreach ($rows as $row) {
        $name = $row['name'] ?? null;
        if (!$name) {
            continue;
        }

        $indexed[$name] = [
            'id'        => $row['.id'] ?? '',
            'name'      => $name,
            'comment'   => $row['comment'] ?? '',
            'type'      => $row['type'] ?? '',
            'disabled'  => normalizeBoolString($row['disabled'] ?? 'false'),
            'running'   => normalizeBoolString($row['running'] ?? 'false'),
            'rx_byte'   => (float)($row['rx-byte'] ?? 0),
            'tx_byte'   => (float)($row['tx-byte'] ?? 0),
            'rx_packet' => (float)($row['rx-packet'] ?? 0),
            'tx_packet' => (float)($row['tx-packet'] ?? 0),
        ];
    }

    return $indexed;
}

function fetchSfpTemperatures(string $routerHost, string $routerUser, string $routerPass, bool $verifyTls, int $timeoutSec): array
{
    $ethernetRows = routerosRequest($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, '/interface/ethernet/print', 'POST', [
        '.proplist' => '.id,name',
    ]);

    $temps = [];
    foreach ($ethernetRows as $eth) {
        $id = $eth['.id'] ?? '';
        $name = $eth['name'] ?? '';
        if ($id === '' || $name === '') {
            continue;
        }

        try {
            $monitorRows = routerosRequest($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, '/interface/ethernet/monitor', 'POST', [
                '.id' => $id,
                'once' => '',
                '.proplist' => 'name,sfp-module-present,sfp-temperature',
            ]);

            $row = $monitorRows[0] ?? [];
            $sfpPresent = normalizeBoolString($row['sfp-module-present'] ?? 'false');
            $temp = $row['sfp-temperature'] ?? '';
            $temps[$name] = ($sfpPresent && $temp !== '') ? $temp . ' °C' : ' ';
        } catch (Throwable $e) {
            $temps[$name] = ' ';
        }
    }

    return $temps;
}

function fetchSystemHealth(string $routerHost, string $routerUser, string $routerPass, bool $verifyTls, int $timeoutSec): array
{
    return routerosRequest($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, '/system/health/print', 'POST', [
        '.proplist' => 'name,value,type',
    ]);
}

function fetchSystemResources(string $routerHost, string $routerUser, string $routerPass, bool $verifyTls, int $timeoutSec): array
{
    $rows = routerosRequest($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, '/system/resource/print', 'POST');
    return $rows[0] ?? [];
}

function fetchCpuResources(string $routerHost, string $routerUser, string $routerPass, bool $verifyTls, int $timeoutSec): array
{
    return routerosRequest($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, '/system/resource/cpu/print', 'POST', [
        '.proplist' => 'cpu,load,irq,disk',
    ]);
}

function fetchRouterboardInfo(string $routerHost, string $routerUser, string $routerPass, bool $verifyTls, int $timeoutSec): array
{
    $rows = routerosRequest($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, '/system/routerboard/print', 'POST');
    return $rows[0] ?? [];
}

function formatResourceBytesMiB($value): string
{
    if ($value === null || $value === '') {
        return ' ';
    }

    return round(((float)$value) / 1024 / 1024, 1) . ' MiB';
}

function buildResourceRows(array $resource, array $cpuRows, array $routerboard): array
{
    $graphColors = ['#22c55e', '#ef4444', '#38bdf8', '#f97316', '#a855f7', '#eab308', '#14b8a6', '#ec4899', '#84cc16'];

    $cpuLoad = $resource['cpu-load'] ?? ' ';
    $cpuLoadHtml = '<span style="color:' . $graphColors[0] . '">' . htmlspecialchars((string)$cpuLoad, ENT_QUOTES, 'UTF-8') . '%</span>';
    if ($cpuRows) {
        $coreParts = [];
        foreach ($cpuRows as $index => $cpu) {
            $color = $graphColors[($index + 1) % count($graphColors)];
            $label = (string)($index + 1);
            $load = htmlspecialchars((string)($cpu['load'] ?? '0'), ENT_QUOTES, 'UTF-8');
            $coreParts[] = '<span style="color:' . $color . '">' . $label . ':' . $load . '%</span>';
        }
        $cpuLoadHtml .= ' (' . implode(', ', $coreParts) . ')';
    }
    $cpuLoadText = strip_tags($cpuLoadHtml);

    $freeMem  = (float)($resource['free-memory'] ?? 0);
    $totalMem = (float)($resource['total-memory'] ?? 0);
    $usedMem = max(0, $totalMem - $freeMem);
    $freePct = $totalMem > 0 ? ($freeMem / $totalMem) * 100 : 0;
    $usedPct = $totalMem > 0 ? ($usedMem / $totalMem) * 100 : 0;

    $freeHdd  = (float)($resource['free-hdd-space'] ?? 0);
    $totalHdd = (float)($resource['total-hdd-space'] ?? 0);
    $usedHdd = max(0, $totalHdd - $freeHdd);
    $freeHddPct = $totalHdd > 0 ? ($freeHdd / $totalHdd) * 100 : 0;
    $usedHddPct = $totalHdd > 0 ? ($usedHdd / $totalHdd) * 100 : 0;

    $rows = [
        ['name' => 'Uptime', 'value' => formatRouterOsDuration($resource['uptime'] ?? ''), 'graphable' => false],
        ['name' => 'Memory (Free)', 'value' => formatResourceBytesMiB($freeMem) . ' (' . round($freePct, 1) . '% free)', 'metric' => $freePct, 'scaleMax' => 100, 'scaleLabel' => '100%', 'graphable' => true],
        ['name' => 'Memory (Used)', 'value' => formatResourceBytesMiB($usedMem) . ' (' . round($usedPct, 1) . '% used)', 'metric' => $usedMem, 'scaleMax' => $totalMem, 'scaleLabel' => formatResourceBytesMiB($totalMem), 'graphable' => true],
        ['name' => 'Memory (Total)', 'value' => formatResourceBytesMiB($totalMem), 'graphable' => false],
        ['name' => 'CPU', 'value' => $resource['cpu'] ?? ' ', 'graphable' => false],
        ['name' => 'CPU Count', 'value' => $resource['cpu-count'] ?? ' ', 'graphable' => false],
        ['name' => 'CPU Frequency', 'value' => isset($resource['cpu-frequency']) ? $resource['cpu-frequency'] . ' MHz' : ' ', 'graphable' => false],
        ['name' => 'CPU Load', 'value' => $cpuLoadText, 'valueHtml' => $cpuLoadHtml, 'metric' => (float)($resource['cpu-load'] ?? 0), 'metricSeries' => array_merge(['total' => (float)($resource['cpu-load'] ?? 0)], array_combine(array_map(fn($i) => 'cpu' . ($i + 1), array_keys($cpuRows)), array_map(fn($cpu) => (float)($cpu['load'] ?? 0), $cpuRows)) ?: []), 'seriesLabels' => array_merge(['Total'], array_map(fn($i) => 'CPU ' . ($i + 1), array_keys($cpuRows))), 'scaleMax' => 100, 'scaleLabel' => '100%', 'graphable' => true],
        ['name' => 'HDD Space (Free)', 'value' => formatResourceBytesMiB($freeHdd) . ' (' . round($freeHddPct, 1) . '% free)', 'metric' => $freeHddPct, 'scaleMax' => 100, 'scaleLabel' => '100%', 'graphable' => true],
        ['name' => 'HDD Space (Used)', 'value' => formatResourceBytesMiB($usedHdd) . ' (' . round($usedHddPct, 1) . '% used)', 'metric' => $usedHdd, 'scaleMax' => $totalHdd, 'scaleLabel' => formatResourceBytesMiB($totalHdd), 'graphable' => true],
        ['name' => 'HDD Space (Total)', 'value' => formatResourceBytesMiB($totalHdd), 'graphable' => false],
        ['name' => 'Sector Writes (Total)', 'value' => $resource['write-sect-total'] ?? ' ', 'metric' => (float)($resource['write-sect-total'] ?? 0), 'graphable' => true],
        ['name' => 'Sector Writes (Since Reboot)', 'value' => $resource['write-sect-since-reboot'] ?? ' ', 'metric' => (float)($resource['write-sect-since-reboot'] ?? 0), 'graphable' => true],
        ['name' => 'Bad Blocks', 'value' => isset($resource['bad-blocks']) ? $resource['bad-blocks'] . '%' : ' ', 'graphable' => false],
        ['name' => 'Architecture Name', 'value' => $resource['architecture-name'] ?? ' ', 'graphable' => false],
        ['name' => 'Board Name', 'value' => $resource['board-name'] ?? ' ', 'graphable' => false],
        ['name' => 'Version', 'value' => $resource['version'] ?? ' ', 'graphable' => false],
        ['name' => 'Build Time', 'value' => $resource['build-time'] ?? ' ', 'graphable' => false],
        ['name' => 'Factory Software', 'value' => $resource['factory-software'] ?? ' ', 'graphable' => false],
        ['name' => 'Routerboard', 'value' => $routerboard['routerboard'] ?? ' ', 'graphable' => false],
        ['name' => 'Model', 'value' => $routerboard['model'] ?? ' ', 'graphable' => false],
        ['name' => 'Revision', 'value' => $routerboard['revision'] ?? ' ', 'graphable' => false],
        ['name' => 'Serial Number', 'value' => $routerboard['serial-number'] ?? ' ', 'graphable' => false],
        ['name' => 'Firmware Type', 'value' => $routerboard['firmware-type'] ?? ' ', 'graphable' => false],
        ['name' => 'Factory Firmware', 'value' => $routerboard['factory-firmware'] ?? ' ', 'graphable' => false],
        ['name' => 'Current Firmware', 'value' => $routerboard['current-firmware'] ?? ' ', 'graphable' => false],
        ['name' => 'Upgrade Firmware', 'value' => $routerboard['upgrade-firmware'] ?? ' ', 'graphable' => false],
    ];

    return $rows;
}

function buildInterfaceRates(array $first, array $second, float $seconds, array $sfpTemps = [], bool $showDisabled = false): array
{
    $interfaces = [];

    foreach ($second as $name => $now) {
        if (!$showDisabled && !empty($now['disabled'])) {
            continue;
        }

        $prev = $first[$name] ?? null;
        $rxDelta = $prev ? max(0, $now['rx_byte'] - $prev['rx_byte']) : 0;
        $txDelta = $prev ? max(0, $now['tx_byte'] - $prev['tx_byte']) : 0;
        $tempText = $sfpTemps[$name] ?? ' ';
        $tempMetric = null;
        if (preg_match('/[-+]?\d+(?:\.\d+)?/', $tempText, $m)) {
            $tempMetric = (float)$m[0];
        }

        $interfaces[] = [
            'name'            => $name,
            'comment'         => $now['comment'] ?? '',
            'type'            => $now['type'] ?? '',
            'running'         => !empty($now['running']),
            'disabled'        => !empty($now['disabled']),
            'sfp_temperature' => $tempText,
            'sfp_temp_metric' => $tempMetric,
            'rx_bps'          => $seconds > 0 ? ($rxDelta * 8) / $seconds : 0,
            'tx_bps'          => $seconds > 0 ? ($txDelta * 8) / $seconds : 0,
            'rx_bytes'        => $now['rx_byte'],
            'tx_bytes'        => $now['tx_byte'],
            'rx_packets'      => $now['rx_packet'],
            'tx_packets'      => $now['tx_packet'],
        ];
    }

    return $interfaces;
}

function fetchDhcpLeases(string $routerHost, string $routerUser, string $routerPass, bool $verifyTls, int $timeoutSec, bool $showDisabled = false): array
{
    $rows = routerosRequest($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, '/ip/dhcp-server/lease/print', 'POST', [
        '.proplist' => '.id,address,mac-address,host-name,server,status,expires-after,last-seen,active-address,active-mac-address,comment,dynamic,disabled',
    ]);

    $bridgeHosts = [];
    try {
        $hostRows = routerosRequest($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, '/interface/bridge/host/print', 'POST', [
            '.proplist' => 'mac-address,on-interface,bridge',
        ]);

        foreach ($hostRows as $hostRow) {
            $mac = strtoupper(trim((string)($hostRow['mac-address'] ?? '')));
            if ($mac !== '') {
                $bridgeHosts[$mac] = ['on_interface' => $hostRow['on-interface'] ?? '', 'bridge' => $hostRow['bridge'] ?? ''];
            }
        }
    } catch (Throwable $e) {
        $bridgeHosts = [];
    }

    $leases = [];
    foreach ($rows as $row) {
        $disabled = normalizeBoolString($row['disabled'] ?? 'false');
        if (!$showDisabled && $disabled) {
            continue;
        }

        $dynamic = normalizeBoolString($row['dynamic'] ?? 'false');
        $macAddress = strtoupper((string)($row['mac-address'] ?? ($row['active-mac-address'] ?? '')));
        $bridgePort = $bridgeHosts[$macAddress]['on_interface'] ?? '';

        $leases[] = [
            'id' => $row['.id'] ?? '',
            'address' => $row['address'] ?? ($row['active-address'] ?? ''),
            'host_name' => $row['host-name'] ?? '',
            'mac_address' => $macAddress,
            'server' => $row['server'] ?? '',
            'status' => $disabled ? 'Disabled' : (($row['status'] ?? '') !== '' ? $row['status'] : 'Unknown'),
            'bridge_port' => $bridgePort,
            'expires_after' => $row['expires-after'] ?? '',
            'expires_after_text' => formatRouterOsDuration($row['expires-after'] ?? ''),
            'last_seen' => $row['last-seen'] ?? '',
            'last_seen_text' => formatRouterOsDuration($row['last-seen'] ?? ''),
            'comment' => $row['comment'] ?? '',
            'dynamic' => $dynamic,
            'dynamic_text' => $dynamic ? 'Dynamic' : 'Static',
            'disabled' => $disabled,
        ];
    }

    usort($leases, function ($a, $b) {
        return strnatcasecmp($a['address'], $b['address']);
    });

    return $leases;
}

function buildAjaxInterfaceRows(array $interfaces): array
{
    foreach ($interfaces as &$iface) {
        $iface['status_text'] = $iface['disabled'] ? 'Disabled' : ($iface['running'] ? 'Up' : 'Down');
        $iface['status_class'] = $iface['disabled'] ? 'disabled' : ($iface['running'] ? 'up' : 'down');
        $iface['rx_bps_text'] = formatBitsPerSecond((float)$iface['rx_bps']);
        $iface['tx_bps_text'] = formatBitsPerSecond((float)$iface['tx_bps']);
        $iface['rx_bytes_text'] = formatBytes((float)$iface['rx_bytes']);
        $iface['tx_bytes_text'] = formatBytes((float)$iface['tx_bytes']);
        $iface['rx_packets_text'] = number_format((float)$iface['rx_packets']);
        $iface['tx_packets_text'] = number_format((float)$iface['tx_packets']);
        $iface['sfp_temperature_text'] = $iface['sfp_temperature'] ?? ' ';
    }
    unset($iface);
    return $interfaces;
}

function buildAjaxLeaseRows(array $leases): array
{
    foreach ($leases as &$lease) {
        $lease['expires_after_text'] = formatRouterOsDuration($lease['expires_after'] ?? '');
        $lease['last_seen_text'] = formatRouterOsDuration($lease['last_seen'] ?? '');
        $lease['dynamic_text'] = !empty($lease['dynamic']) ? 'Dynamic' : 'Static';
    }
    unset($lease);
    return $leases;
}

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if ($isAjax) {
    try {
        [$routerUser, $routerPass, $body] = requestCredentials();
        $showDisabled = normalizeBoolString($body['showDisabled'] ?? 'false');

        $start = microtime(true);
        $first = fetchInterfaceCounters($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
        usleep($sampleDelayUs);
        $second = fetchInterfaceCounters($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
        $elapsed = max(microtime(true) - $start, 0.001);

        $sfpTemps = fetchSfpTemperatures($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
        $interfaces = buildInterfaceRates($first, $second, $elapsed, $sfpTemps, $showDisabled);
        sortInterfaces($interfaces, $preferredInterfaceOrder);
        $interfaces = buildAjaxInterfaceRows($interfaces);

        $resourceInfo = fetchSystemResources($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
        $cpuInfo = fetchCpuResources($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
        $routerboardInfo = fetchRouterboardInfo($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
        $resources = buildResourceRows($resourceInfo, $cpuInfo, $routerboardInfo);
        $leases = buildAjaxLeaseRows(fetchDhcpLeases($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $showDisabled));
        $health = fetchSystemHealth($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'refreshedAt' => date('Y-m-d H:i:s'),
            'sampleSeconds' => round($sampleDelayUs / 1000000, 2),
            'interfaces' => $interfaces,
            'leases' => $leases,
            'health' => $health,
            'resources' => $resources,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MikroTik Dashboard</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --panel-2: #1f2937;
            --text: #e5e7eb;
            --muted: #94a3b8;
            --line: #334155;
            --good: #22c55e;
            --warn: #f59e0b;
            --bad: #ef4444;
            --accent: #38bdf8;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: var(--bg); color: var(--text); }
        .wrap { width: min(1600px, calc(100% - 32px)); margin: 24px auto; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        h1, h2 { margin: 0; font-weight: 700; }
        h1 { font-size: 26px; }
        h2 { font-size: 18px; }
        .muted { color: var(--muted); }
        .grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        .card { background: linear-gradient(180deg, var(--panel), var(--panel-2)); border: 1px solid var(--line); border-radius: 14px; padding: 18px; overflow: hidden; }
        .card-title { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; cursor: pointer; user-select: none; }
        .toggle-icon { color: var(--muted); font-family: Consolas, Menlo, Monaco, monospace; min-width: 12px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1100px; table-layout: fixed; }
        th, td { padding: 10px 12px; border-bottom: 1px solid rgba(148, 163, 184, 0.15); text-align: left; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
        th { color: #cbd5e1; background: rgba(255,255,255,0.03); }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable::after { content: ' ⇅'; color: var(--muted); font-size: 11px; }
        th.sort-asc::after { content: ' ↑'; color: var(--accent); }
        th.sort-desc::after { content: ' ↓'; color: var(--accent); }
        tr:hover td { background: rgba(255,255,255,0.03); }
        .sparkline { display: block; width: 290px; height: 40px; background: rgba(15,23,42,0.55); border: 1px solid rgba(148,163,184,0.18); border-radius: 8px; cursor: pointer; }
        .scale-cell { font-family: Consolas, Menlo, Monaco, monospace; cursor: pointer; }
        .col-iface { width: 190px; }
        .col-type { width: 120px; }
        .col-status { width: 100px; }
        .col-rate { width: 120px; }
        .col-total { width: 130px; }
        .col-graph { width: 310px; }
        .col-scale { width: 120px; }
        .col-temp { width: 110px; }
        .col-ip { width: 130px; }
        .col-host { width: 180px; }
        .col-comment { width: 220px; }
        .col-mac { width: 170px; }
        .col-bridge { width: 120px; }
        .col-lease { width: 130px; }
        .col-seen { width: 120px; }
        .col-dtype { width: 90px; }
        .status { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .up { background: rgba(34,197,94,0.15); color: #86efac; }
        .down { background: rgba(239,68,68,0.15); color: #fca5a5; }
        .disabled { background: rgba(245,158,11,0.15); color: #fcd34d; }
        .mono { font-family: Consolas, Menlo, Monaco, monospace; }
        .error { border: 1px solid rgba(239,68,68,0.4); background: rgba(127,29,29,0.3); color: #fecaca; padding: 14px 16px; border-radius: 12px; margin-bottom: 16px; display: none; }
        .toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
        input[type="search"], input[type="text"], input[type="password"], input[type="number"], select { width: min(340px, 100%); padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); background: rgba(15,23,42,0.8); color: var(--text); outline: none; }
        input[type="checkbox"] { transform: translateY(1px); }
        .small { font-size: 12px; }
        .button, button { color: white; text-decoration: none; border: 1px solid var(--line); background: rgba(56,189,248,0.12); padding: 10px 12px; border-radius: 10px; cursor: pointer; }
        .button:hover, button:hover { background: rgba(56,189,248,0.2); }
        .collapsed .table-wrap, .collapsed .toolbar, .collapsed .table-note { display: none; }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(2,6,23,0.78); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 1000; }
        .modal { width: min(760px, 100%); max-height: calc(100vh - 40px); overflow: auto; background: #111827; border: 1px solid var(--line); border-radius: 16px; padding: 18px; box-shadow: 0 20px 60px rgba(0,0,0,0.45); }
        .modal h2 { margin-bottom: 14px; }
        .options-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .option-row { border: 1px solid rgba(148,163,184,0.18); border-radius: 12px; padding: 12px; background: rgba(15,23,42,0.35); }
        .option-row label { display: block; font-size: 13px; color: #cbd5e1; margin-bottom: 8px; }
        .checkline { display: flex; align-items: center; gap: 8px; margin: 8px 0; color: #cbd5e1; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        .login-box { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
        .metric-table { min-width: 900px; }
        .metric-graph-col { width: 310px; }
        .metric-scale-col { width: 120px; }
        @media (max-width: 800px) { .options-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1>MikroTik Router Dashboard</h1>
            <div class="muted small">Router: <?= h($routerHost) ?> • Refreshed: <span id="refreshedAt">Not loaded</span></div>
        </div>
        <div>
            <span class="muted small" id="pollStatus">Waiting for login</span>
            <button type="button" id="optionsButton">Options</button>
            <button type="button" id="refreshButton">Refresh</button>
        </div>
    </div>

    <div class="login-box card" id="loginBox">
        <input type="text" id="loginUsername" autocomplete="username" placeholder="Username, default root">
        <input type="password" id="loginPassword" autocomplete="current-password" placeholder="Router password">
        <button type="button" id="loginButton">Login</button>
        <span class="muted small">Password is only kept in this page session.</span>
    </div>

    <div class="error" id="errorBox"></div>

    <div class="grid">
        <section class="card collapsible" data-table="interfaces">
            <h2 class="card-title"><span class="toggle-icon">&nbsp;</span><span>Interfaces <span class="entry-count" id="interfacesCount">(0)</span></span></h2>
            <div class="toolbar table-search-toolbar" data-table-search="interfaces"><input type="search" data-search-table="interfaces" placeholder="Filter interfaces..."></div>
            <div class="table-wrap">
                <table id="interfacesTable">
                    <thead>
                    <tr>
                        <th class="col-iface sortable" data-key="display_name">Interface</th>
                        <th class="col-type sortable" data-key="type">Type</th>
                        <th class="col-status sortable" data-key="status_text">Status</th>
                        <th class="col-temp sortable" data-key="sfp_temp_metric">SFP Temp</th>
                        <th class="col-rate sortable" data-key="rx_bps">Download</th>
                        <th class="col-rate sortable" data-key="tx_bps">Upload</th>
                        <th class="col-total sortable" data-key="rx_bytes">Total RX</th>
                        <th class="col-total sortable" data-key="tx_bytes">Total TX</th>
                        <th class="col-graph"><span id="interfaceGraphHeader">Traffic</span></th>
                        <th class="col-scale">Scale</th>
                    </tr>
                    </thead>
                    <tbody id="interfacesBody"></tbody>
                </table>
            </div>
            <div class="muted small table-note" style="margin-top: 10px;">Click the graph or Scale cell to switch the Interfaces graph between traffic and temperatures.</div>
        </section>

        <section class="card collapsible" data-table="health">
            <h2 class="card-title"><span class="toggle-icon">&nbsp;</span><span>System Health <span class="entry-count" id="healthCount">(0)</span></span></h2>
            <div class="toolbar table-search-toolbar" data-table-search="health"><input type="search" data-search-table="health" placeholder="Filter system health..."></div>
            <div class="table-wrap">
                <table id="healthTable" class="metric-table">
                    <thead>
                    <tr>
                        <th class="sortable" data-key="name">Name</th>
                        <th class="sortable" data-key="value">Value</th>
                        <th class="sortable" data-key="type">Type</th>
                        <th class="metric-graph-col">Graph</th>
                        <th class="metric-scale-col">Scale</th>
                    </tr>
                    </thead>
                    <tbody id="healthBody"></tbody>
                </table>
            </div>
        </section>

        <section class="card collapsible" data-table="resources">
            <h2 class="card-title"><span class="toggle-icon">&nbsp;</span><span>Resources <span class="entry-count" id="resourcesCount">(0)</span></span></h2>
            <div class="toolbar table-search-toolbar" data-table-search="resources"><input type="search" data-search-table="resources" placeholder="Filter resources..."></div>
            <div class="table-wrap">
                <table id="resourcesTable" class="metric-table">
                    <thead>
                    <tr>
                        <th class="sortable" data-key="name">Name</th>
                        <th class="sortable" data-key="value">Value</th>
                        <th class="metric-graph-col">Graph</th>
                        <th class="metric-scale-col">Scale</th>
                    </tr>
                    </thead>
                    <tbody id="resourcesBody"></tbody>
                </table>
            </div>
        </section>

        <section class="card collapsible" data-table="leases">
            <h2 class="card-title"><span class="toggle-icon">&nbsp;</span><span>DHCP Leases <span class="entry-count" id="leasesCount">(0)</span></span></h2>
            <div class="toolbar table-search-toolbar" data-table-search="leases"><input type="search" data-search-table="leases" placeholder="Filter by IP, hostname, MAC, server, comment..."></div>
            <div class="table-wrap">
                <table id="leasesTable">
                    <thead>
                    <tr>
                        <th class="col-ip sortable" data-key="address">IP Address</th>
                        <th class="col-host sortable" data-key="host_name">Host Name</th>
                        <th class="col-comment sortable" data-key="comment">Comment</th>
                        <th class="col-dtype sortable" data-key="dynamic_text">Type</th>
                        <th class="col-mac sortable" data-key="mac_address">MAC Address</th>
                        <th class="col-bridge sortable" data-key="bridge_port">Bridge Port</th>
                        <th class="col-lease sortable" data-key="expires_after">Lease Time Left</th>
                        <th class="col-seen sortable" data-key="last_seen">Last Seen</th>
                    </tr>
                    </thead>
                    <tbody id="leasesBody"></tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="modal-backdrop" id="optionsModal">
    <div class="modal">
        <h2>Options</h2>
        <div class="options-grid">
            <div class="option-row"><label>Username</label><input type="text" id="optUsername" placeholder="root"></div>
            <div class="option-row"><label>Refresh time, seconds</label><input type="number" id="optRefreshSeconds" min="3" max="60" step="1"></div>
            <div class="option-row"><label>Traffic graph length, minutes</label><input type="number" id="optGraphMinutes" min="3" max="60" step="1"></div>
            <div class="option-row">
                <label>Interface display</label>
                <select id="optInterfaceDisplay"><option value="comment">Comment, fallback to Name</option><option value="name">Name</option></select>
            </div>
            <div class="option-row">
                <label>Visibility / History</label>
                <div class="checkline"><input type="checkbox" id="optHistoryEnabled"><span>Store timestamped history and show saved data first on reload</span></div>
                <div class="checkline"><input type="checkbox" id="optShowDisabled"><span>Display disabled interfaces / disabled leases</span></div>
                <div class="checkline"><input type="checkbox" id="optRelativeTemperatureGraphs"><span>Relative temperature graphs</span></div>
                <div class="checkline"><input type="checkbox" id="optRoundTemperatureGraphs"><span>Round temperature graph points when relative temperature graphs are off</span></div>
            </div>
            <div class="option-row">
                <label>Tables expanded by default</label>
                <div class="checkline"><input type="checkbox" data-opt-expanded="interfaces"><span>Interfaces</span></div>
                <div class="checkline"><input type="checkbox" data-opt-expanded="health"><span>System Health</span></div>
                <div class="checkline"><input type="checkbox" data-opt-expanded="resources"><span>Resources</span></div>
                <div class="checkline"><input type="checkbox" data-opt-expanded="leases"><span>DHCP Leases</span></div>
            </div>
            <div class="option-row">
                <label>Search boxes enabled</label>
                <div class="checkline"><input type="checkbox" data-opt-search="interfaces"><span>Interfaces</span></div>
                <div class="checkline"><input type="checkbox" data-opt-search="health"><span>System Health</span></div>
                <div class="checkline"><input type="checkbox" data-opt-search="resources"><span>Resources</span></div>
                <div class="checkline"><input type="checkbox" data-opt-search="leases"><span>DHCP Leases</span></div>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" id="optionsSaveButton">Save</button>
            <button type="button" id="optionsCloseButton">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    const STORAGE_KEY = 'mikrotikDashboardOptionsV2';
    const HISTORY_KEY = 'mikrotikDashboardHistoryV2';
    const state = {
        username: '',
        password: '',
        refreshTimer: null,
        isPolling: false,
        interfaceGraphMode: 'traffic',
        sort: {
            interfaces: { key: 'display_name', dir: 'asc' },
            health: { key: 'name', dir: 'asc' },
            resources: { key: 'name', dir: 'asc' },
            leases: { key: 'address', dir: 'asc' }
        },
        data: { interfaces: [], health: [], resources: [], leases: [] },
        graphHistory: {}
    };

    const defaults = {
        username: '',
        historyEnabled: false,
        interfaceDisplay: 'comment',
        showDisabled: false,
        refreshSeconds: 3,
        graphMinutes: 3,
        roundTemperatureGraphs: true,
        relativeTemperatureGraphs: true,
        expanded: { interfaces: true, health: true, resources: true, leases: true },
        searchable: { interfaces: true, health: true, resources: true, leases: true }
    };

    let options = loadOptions();
    const GRAPH_COLORS = ['#22c55e', '#ef4444', '#38bdf8', '#f97316', '#a855f7', '#eab308', '#14b8a6', '#ec4899', '#84cc16'];

    const els = {
        loginBox: document.getElementById('loginBox'),
        loginUsername: document.getElementById('loginUsername'),
        loginPassword: document.getElementById('loginPassword'),
        loginButton: document.getElementById('loginButton'),
        refreshButton: document.getElementById('refreshButton'),
        optionsButton: document.getElementById('optionsButton'),
        optionsModal: document.getElementById('optionsModal'),
        optionsSaveButton: document.getElementById('optionsSaveButton'),
        optionsCloseButton: document.getElementById('optionsCloseButton'),
        pollStatus: document.getElementById('pollStatus'),
        refreshedAt: document.getElementById('refreshedAt'),
        errorBox: document.getElementById('errorBox'),
        interfacesBody: document.getElementById('interfacesBody'),
        healthBody: document.getElementById('healthBody'),
        resourcesBody: document.getElementById('resourcesBody'),
        leasesBody: document.getElementById('leasesBody'),
        interfaceGraphHeader: document.getElementById('interfaceGraphHeader')
    };

    function loadOptions() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            return mergeOptions(defaults, saved);
        } catch (e) {
            return JSON.parse(JSON.stringify(defaults));
        }
    }

    function mergeOptions(base, saved) {
        const out = JSON.parse(JSON.stringify(base));
        Object.assign(out, saved || {});
        out.expanded = Object.assign({}, base.expanded, (saved || {}).expanded || {});
        out.searchable = Object.assign({}, base.searchable, (saved || {}).searchable || {});
        out.refreshSeconds = clampInt(out.refreshSeconds, 3, 60, 3);
        out.graphMinutes = clampInt(out.graphMinutes, 3, 60, 3);
        out.interfaceDisplay = out.interfaceDisplay === 'name' ? 'name' : 'comment';
        out.roundTemperatureGraphs = out.roundTemperatureGraphs !== false;
        out.relativeTemperatureGraphs = out.relativeTemperatureGraphs !== false;
        return out;
    }

    function saveOptions() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(options));
    }

    function clampInt(value, min, max, fallback) {
        const n = parseInt(value, 10);
        if (!Number.isFinite(n)) return fallback;
        return Math.max(min, Math.min(max, n));
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function formatBitsPerSecond(value, precision = 2) {
        const units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
        let bps = Math.max(0, Number(value || 0));
        let pow = bps > 0 ? Math.floor(Math.log(bps) / Math.log(1000)) : 0;
        pow = Math.min(pow, units.length - 1);
        const scaled = bps / Math.pow(1000, pow);
        return scaled.toFixed(precision).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1') + ' ' + units[pow];
    }

    function formatMetric(value) {
        if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return ' ';
        const n = Number(value);
        if (Math.abs(n) >= 1024 * 1024) return (n / 1024 / 1024).toFixed(1).replace(/\.0$/, '') + ' MiB';
        if (Math.abs(n) >= 1000) return n.toLocaleString();
        return n.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
    }

    function numericFromText(value) {
        const m = String(value ?? '').match(/[-+]?\d+(?:\.\d+)?/);
        return m ? Number(m[0]) : null;
    }

    function graphConfig() {
        return {
            refreshSeconds: options.refreshSeconds,
            displaySeconds: options.graphMinutes * 60,
            width: 290,
            height: 40,
            lineWidth: 1.8,
            maxHistoryPoints: Math.max(1, Math.floor((options.graphMinutes * 60) / options.refreshSeconds)),
            scaleHeadroom: 1.1
        };
    }

    function graphKey(table, id) {
        return table + '::' + id;
    }

    function pushHistory(table, id, values) {
        const cfg = graphConfig();
        const key = graphKey(table, id);
        if (!state.graphHistory[key]) state.graphHistory[key] = [];
        state.graphHistory[key].push(Object.assign({ t: Date.now() }, values));
        if (state.graphHistory[key].length > cfg.maxHistoryPoints) {
            state.graphHistory[key] = state.graphHistory[key].slice(-cfg.maxHistoryPoints);
        }
    }

    function drawSparkline(canvas, history, keys, mode, fixedMax = null, opts = {}) {
        if (!canvas || !canvas.getContext) return { min: 0, max: 0, isRange: false };
        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;
        const cfg = graphConfig();
        ctx.clearRect(0, 0, width, height);

        const leftPad = 2;
        const rightPad = 2;
        const topPad = 3;
        const bottomPad = 3;
        const graphWidth = width - leftPad - rightPad;
        const graphHeight = height - topPad - bottomPad;

        ctx.strokeStyle = 'rgba(148,163,184,0.18)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(leftPad, height - bottomPad);
        ctx.lineTo(width - rightPad, height - bottomPad);
        ctx.stroke();

        if (!history || history.length < 1) {
            const emptyMax = fixedMax && fixedMax > 0 ? fixedMax : 0;
            return { min: 0, max: emptyMax, isRange: false };
        }

        let minVal = Number.POSITIVE_INFINITY;
        let maxVal = 0;
        history.forEach(function (point) {
            keys.forEach(function (key) {
                const val = Number(point[key] || 0);
                if (Number.isFinite(val)) {
                    minVal = Math.min(minVal, val);
                    maxVal = Math.max(maxVal, val);
                }
            });
        });
        if (!Number.isFinite(minVal)) minVal = 0;

        let scaledMin = 0;
        let scaledMax = fixedMax && fixedMax > 0 ? fixedMax : (maxVal > 0 ? maxVal * cfg.scaleHeadroom : 1);
        let isRange = false;

        if (opts.temperature === true) {
            if (opts.relativeTemperature === true) {
                // Relative temperature graphs should show movement around the observed range.
                // Do not round values here; keep the plotted history exactly as sampled.
                scaledMin = minVal;
                scaledMax = maxVal;
                if (scaledMax === scaledMin) {
                    scaledMin -= 1;
                    scaledMax += 1;
                }
                isRange = true;
            } else {
                // Absolute temperature graphs use a normal 0 -> max temperature scale.
                scaledMin = 0;
                scaledMax = maxVal > 0 ? Math.ceil(maxVal) : 1;
                isRange = false;
            }
        }

        const scaleSpan = Math.max(1, scaledMax - scaledMin);
        const slotCount = cfg.maxHistoryPoints;
        const slotWidth = slotCount > 1 ? graphWidth / (slotCount - 1) : graphWidth;

        function getPointY(value) {
            return topPad + (graphHeight - (((value - scaledMin) / scaleSpan) * graphHeight));
        }

        keys.forEach(function (key, keyIndex) {
            const values = history.map(point => Number(point[key] || 0));
            const offset = slotCount - values.length;
            ctx.beginPath();
            ctx.lineWidth = cfg.lineWidth;
            ctx.strokeStyle = GRAPH_COLORS[keyIndex % GRAPH_COLORS.length];
            values.forEach(function (value, index) {
                const x = leftPad + ((offset + index) * slotWidth);
                const y = getPointY(Math.max(scaledMin, Math.min(value, scaledMax)));
                if (index === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
            });
            ctx.stroke();
        });

        return { min: scaledMin, max: scaledMax, isRange: isRange };
    }

    function compareValues(a, b) {
        const na = Number(a);
        const nb = Number(b);
        if (a !== '' && b !== '' && Number.isFinite(na) && Number.isFinite(nb)) return na - nb;
        return String(a ?? '').localeCompare(String(b ?? ''), undefined, { numeric: true, sensitivity: 'base' });
    }

    function sortedItems(table, items) {
        const sort = state.sort[table];
        const copy = items.slice();
        copy.sort(function (a, b) {
            const result = compareValues(a[sort.key] ?? '', b[sort.key] ?? '');
            return sort.dir === 'desc' ? -result : result;
        });
        return copy;
    }

    function applySearch(tableName) {
        const input = document.querySelector('[data-search-table="' + tableName + '"]');
        const table = document.getElementById(tableName === 'leases' ? 'leasesTable' : tableName === 'interfaces' ? 'interfacesTable' : tableName + 'Table');
        if (!input || !table) return;
        const q = input.value.trim().toLowerCase();
        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.style.display = q === '' || row.innerText.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    function updateCount(table, count) {
        const el = document.getElementById(table + 'Count');
        if (el) el.textContent = '(' + count + ')';
    }

    function displayInterfaceName(iface) {
        return options.interfaceDisplay === 'name' ? iface.name : (iface.comment || iface.name);
    }

    function renderInterfaces(items) {
        items = items.map(function (iface) {
            iface.display_name = displayInterfaceName(iface);
            return iface;
        });

        updateCount('interfaces', items.length);
        sortedItems('interfaces', items).forEach(function (iface) {
            const tempMetric = Number(iface.sfp_temp_metric);
            const values = { rx: Number(iface.rx_bps || 0), tx: Number(iface.tx_bps || 0) };
            if (Number.isFinite(tempMetric)) {
                values.temp = options.relativeTemperatureGraphs ? tempMetric : (options.roundTemperatureGraphs ? Math.round(tempMetric) : tempMetric);
            }
            pushHistory('interfaces', iface.name, values);
        });

        els.interfacesBody.innerHTML = sortedItems('interfaces', items).map(function (iface) {
            const graphName = state.interfaceGraphMode === 'temp' ? 'Temperature' : 'Traffic';
            const hasTemp = Number.isFinite(Number(iface.sfp_temp_metric));
            const graphCell = state.interfaceGraphMode === 'temp' && !hasTemp ? ' ' : `<canvas class="sparkline" width="290" height="40" data-graph-table="interfaces" data-graph-id="${escapeHtml(iface.name)}" title="Click to show ${graphName === 'Traffic' ? 'temperatures' : 'traffic'}"></canvas>`;
            const scaleCell = state.interfaceGraphMode === 'temp' && !hasTemp ? ' ' : `<span class="scale-cell" data-graph-table="interfaces" data-graph-id="${escapeHtml(iface.name)}"> </span>`;
            return `
                <tr>
                    <td class="mono" title="${escapeHtml(iface.name)}"><a href="interface.php?iface=${encodeURIComponent(iface.name)}" style="color: inherit; text-decoration: none;">${escapeHtml(iface.display_name)}</a></td>
                    <td title="${escapeHtml(iface.type)}">${escapeHtml(iface.type)}</td>
                    <td><span class="status ${escapeHtml(iface.status_class || '')}">${escapeHtml(iface.status_text || '')}</span></td>
                    <td class="mono">${escapeHtml(iface.sfp_temperature_text || iface.sfp_temperature || ' ')}</td>
                    <td>${escapeHtml(iface.rx_bps_text || '')}</td>
                    <td>${escapeHtml(iface.tx_bps_text || '')}</td>
                    <td>${escapeHtml(iface.rx_bytes_text || '')}</td>
                    <td>${escapeHtml(iface.tx_bytes_text || '')}</td>
                    <td>${graphCell}</td>
                    <td>${scaleCell}</td>
                </tr>`;
        }).join('');

        redrawGraphs();
        applySearch('interfaces');
    }

    function isTemperatureMetric(item) {
        const name = String(item.name || '').toLowerCase();
        const type = String(item.type || '').toLowerCase();
        return name.includes('temperature') || name.includes('temp') || type === 'c' || type.includes('celsius');
    }

    function renderMetricTable(table, body, items, columns) {
        updateCount(table, items.length);
        const sorted = sortedItems(table, items);
        sorted.forEach(function (item) {
            if (item.graphable === false) return;

            if (item.metricSeries && typeof item.metricSeries === 'object') {
                const seriesValues = {};
                Object.keys(item.metricSeries).forEach(function (key) {
                    const val = Number(item.metricSeries[key] || 0);
                    if (Number.isFinite(val)) seriesValues[key] = val;
                });
                if (Object.keys(seriesValues).length > 0) pushHistory(table, item.name, seriesValues);
                return;
            }

            let metric = item.metric !== undefined ? Number(item.metric) : numericFromText(item.value);
            if (metric !== null && Number.isFinite(metric)) {
                if (isTemperatureMetric(item) && !options.relativeTemperatureGraphs && options.roundTemperatureGraphs) metric = Math.round(metric);
                pushHistory(table, item.name, { value: metric });
            }
        });

        body.innerHTML = sorted.map(function (item) {
            const cells = columns.map(function (col) {
                const htmlKey = col.key + 'Html';
                const valueHtml = item[htmlKey];
                const valueText = item[col.key] ?? ' ';
                return `<td class="${col.mono ? 'mono' : ''}">${valueHtml !== undefined ? valueHtml : escapeHtml(valueText)}</td>`;
            }).join('');
            const canGraph = item.graphable !== false && (item.metricSeries || item.metric !== undefined || numericFromText(item.value) !== null);
            let graphCell = ' ';
            if (canGraph) {
                const keys = item.metricSeries && typeof item.metricSeries === 'object' ? Object.keys(item.metricSeries) : ['value'];
                const scaleMax = Number(item.scaleMax || 0);
                const scaleLabel = item.scaleLabel || '';
                const tempGraph = isTemperatureMetric(item) ? '1' : '0';
                graphCell = `<canvas class="sparkline" width="290" height="40" data-graph-table="${table}" data-graph-id="${escapeHtml(item.name)}" data-graph-keys="${escapeHtml(keys.join('|'))}" data-scale-max="${Number.isFinite(scaleMax) ? scaleMax : 0}" data-scale-label="${escapeHtml(scaleLabel)}" data-temp-graph="${tempGraph}"></canvas>`;
            }
            return `<tr>${cells}<td>${graphCell}</td><td class="scale-cell" data-graph-table="${table}" data-graph-id="${escapeHtml(item.name)}"> </td></tr>`;
        }).join('');

        redrawGraphs();
        applySearch(table);
    }

    function renderHealth(items) {
        items = (items || []).map(function (item) {
            const metric = numericFromText(item.value);
            return { name: item.name || '', value: item.value ?? ' ', type: item.type || '', metric: metric, graphable: metric !== null && !/uptime|last seen|lease time left/i.test(item.name || '') };
        });
        renderMetricTable('health', els.healthBody, items, [{ key: 'name', mono: true }, { key: 'value', mono: true }, { key: 'type', mono: false }]);
    }

    function renderResources(items) {
        items = (items || []).map(function (item) {
            item.graphable = item.graphable !== false && !/uptime|last seen|lease time left/i.test(item.name || '');
            return item;
        });
        renderMetricTable('resources', els.resourcesBody, items, [{ key: 'name', mono: true }, { key: 'value', mono: true }]);
    }

    function renderLeases(items) {
        updateCount('leases', items.length);
        els.leasesBody.innerHTML = sortedItems('leases', items).map(function (lease) {
            return `
                <tr>
                    <td class="mono">${escapeHtml(lease.address || '')}</td>
                    <td>${escapeHtml(lease.host_name || ' ')}</td>
                    <td>${escapeHtml(lease.comment || ' ')}</td>
                    <td>${escapeHtml(lease.dynamic_text || '')}</td>
                    <td class="mono">${escapeHtml(lease.mac_address || '')}</td>
                    <td>${escapeHtml(lease.bridge_port || ' ')}</td>
                    <td>${escapeHtml(lease.expires_after_text || '')}</td>
                    <td>${escapeHtml(lease.last_seen_text || '')}</td>
                </tr>`;
        }).join('');
        applySearch('leases');
    }

    function redrawGraphs() {
        if (els.interfaceGraphHeader) {
            els.interfaceGraphHeader.textContent = state.interfaceGraphMode === 'temp' ? 'Temperatures' : 'Traffic (last ' + options.graphMinutes + 'm)';
        }

        document.querySelectorAll('canvas.sparkline').forEach(function (canvas) {
            const table = canvas.getAttribute('data-graph-table');
            const id = canvas.getAttribute('data-graph-id');
            const hist = state.graphHistory[graphKey(table, id)] || [];
            let keys = (canvas.getAttribute('data-graph-keys') || 'value').split('|').filter(Boolean);
            let formatter = formatMetric;
            let fixedMax = Number(canvas.getAttribute('data-scale-max') || 0);
            const scaleLabel = canvas.getAttribute('data-scale-label') || '';

            let isTempGraph = false;
            if (table === 'interfaces') {
                fixedMax = 0;
                if (state.interfaceGraphMode === 'temp') {
                    keys = ['temp'];
                    formatter = value => formatMetric(value) + ' °C';
                    isTempGraph = true;
                } else {
                    keys = ['rx', 'tx'];
                    formatter = formatBitsPerSecond;
                }
            } else if (canvas.getAttribute('data-temp-graph') === '1') {
                formatter = value => formatMetric(value) + ' °C';
                isTempGraph = true;
            } else if (id === 'CPU Load' || id === 'Free Memory' || id === 'Free HDD Space') {
                formatter = value => formatMetric(value) + '%';
            }

            if (isTempGraph) {
                canvas.classList.add('temp-graph');
                if (canvas.height !== 40) canvas.height = 40;
                fixedMax = 0;
            } else {
                canvas.classList.remove('temp-graph');
                if (canvas.height !== 40) canvas.height = 40;
            }

            const scale = drawSparkline(canvas, hist, keys, state.interfaceGraphMode, fixedMax > 0 ? fixedMax : null, { temperature: isTempGraph, relativeTemperature: options.relativeTemperatureGraphs });
            const row = canvas.closest('tr');
            const scaleCell = row ? row.querySelector('.scale-cell') : null;
            if (scaleCell) {
                if (scaleLabel) {
                    scaleCell.textContent = scaleLabel;
                } else if (scale && scale.isRange) {
                    if (isTempGraph) {
                        scaleCell.textContent = formatMetric(scale.min) + '–' + formatMetric(scale.max) + ' °C';
                    } else {
                        scaleCell.textContent = formatter(scale.min) + '–' + formatter(scale.max);
                    }
                } else {
                    scaleCell.textContent = scale && scale.max > 0 ? formatter(scale.max) : ' ';
                }
            }
        });
    }

    function renderAll(data) {
        state.data.interfaces = data.interfaces || [];
        state.data.health = data.health || [];
        state.data.resources = data.resources || [];
        state.data.leases = data.leases || [];
        renderInterfaces(state.data.interfaces);
        renderHealth(state.data.health);
        renderResources(state.data.resources);
        renderLeases(state.data.leases);
    }

    function setError(message) {
        if (!message) {
            els.errorBox.style.display = 'none';
            els.errorBox.textContent = '';
            return;
        }
        els.errorBox.textContent = message;
        els.errorBox.style.display = 'block';
    }

    function saveHistorySnapshot(data) {
        if (!options.historyEnabled) return;
        const snapshot = { timestamp: new Date().toISOString(), data: data };
        localStorage.setItem(HISTORY_KEY, JSON.stringify(snapshot));
    }

    function loadHistorySnapshot() {
        if (!options.historyEnabled) return;
        try {
            const snapshot = JSON.parse(localStorage.getItem(HISTORY_KEY) || 'null');
            if (snapshot && snapshot.data) {
                renderAll(snapshot.data);
                if (els.refreshedAt) els.refreshedAt.textContent = 'saved ' + snapshot.timestamp.replace('T', ' ').replace(/\.\d+Z$/, '');
                if (els.pollStatus) els.pollStatus.textContent = 'Showing saved history until refresh completes';
            }
        } catch (e) {}
    }

    async function pollData() {
        if (state.isPolling || !state.password) return;
        state.isPolling = true;
        setError('');
        if (els.pollStatus) els.pollStatus.textContent = 'Updating...';

        try {
            const response = await fetch(window.location.pathname + '?ajax=1&t=' + Date.now(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
                body: JSON.stringify({ username: state.username || options.username || 'root', password: state.password, showDisabled: options.showDisabled })
            });

            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Failed to load updated data');

            renderAll(data);
            saveHistorySnapshot(data);
            if (els.refreshedAt) els.refreshedAt.textContent = data.refreshedAt || '';
            if (els.pollStatus) els.pollStatus.textContent = 'Auto-update every ' + options.refreshSeconds + 's • Graph ' + options.graphMinutes + 'm';
        } catch (error) {
            setError(error.message);
            if (els.pollStatus) els.pollStatus.textContent = 'Update failed';
            console.error(error);
        } finally {
            state.isPolling = false;
        }
    }

    function restartPolling() {
        if (state.refreshTimer) clearInterval(state.refreshTimer);
        state.refreshTimer = setInterval(pollData, options.refreshSeconds * 1000);
        if (state.password) pollData();
    }

    function applyOptionsToUi() {
        if (els.loginUsername && !els.loginUsername.value) els.loginUsername.value = options.username || '';
        document.querySelectorAll('.collapsible').forEach(function (card) {
            const table = card.getAttribute('data-table');
            const expanded = options.expanded[table] !== false;
            card.classList.toggle('collapsed', !expanded);
            const icon = card.querySelector('.toggle-icon');
            if (icon) icon.textContent = expanded ? '−' : '+';
        });

        document.querySelectorAll('.table-search-toolbar').forEach(function (bar) {
            const table = bar.getAttribute('data-table-search');
            bar.style.display = options.searchable[table] === false ? 'none' : '';
        });

        fillOptionsModal();
        renderAll(state.data);
        restartPolling();
    }

    function fillOptionsModal() {
        document.getElementById('optUsername').value = options.username || '';
        document.getElementById('optRefreshSeconds').value = options.refreshSeconds;
        document.getElementById('optGraphMinutes').value = options.graphMinutes;
        document.getElementById('optInterfaceDisplay').value = options.interfaceDisplay;
        document.getElementById('optHistoryEnabled').checked = !!options.historyEnabled;
        document.getElementById('optShowDisabled').checked = !!options.showDisabled;
        document.getElementById('optRelativeTemperatureGraphs').checked = !!options.relativeTemperatureGraphs;
        document.getElementById('optRoundTemperatureGraphs').checked = !!options.roundTemperatureGraphs;
        document.querySelectorAll('[data-opt-expanded]').forEach(input => input.checked = options.expanded[input.getAttribute('data-opt-expanded')] !== false);
        document.querySelectorAll('[data-opt-search]').forEach(input => input.checked = options.searchable[input.getAttribute('data-opt-search')] !== false);
    }

    function readOptionsModal() {
        options.username = document.getElementById('optUsername').value.trim();
        options.refreshSeconds = clampInt(document.getElementById('optRefreshSeconds').value, 3, 60, 3);
        options.graphMinutes = clampInt(document.getElementById('optGraphMinutes').value, 3, 60, 3);
        options.interfaceDisplay = document.getElementById('optInterfaceDisplay').value === 'name' ? 'name' : 'comment';
        options.historyEnabled = document.getElementById('optHistoryEnabled').checked;
        options.showDisabled = document.getElementById('optShowDisabled').checked;
        options.relativeTemperatureGraphs = document.getElementById('optRelativeTemperatureGraphs').checked;
        options.roundTemperatureGraphs = document.getElementById('optRoundTemperatureGraphs').checked;
        document.querySelectorAll('[data-opt-expanded]').forEach(input => options.expanded[input.getAttribute('data-opt-expanded')] = input.checked);
        document.querySelectorAll('[data-opt-search]').forEach(input => options.searchable[input.getAttribute('data-opt-search')] = input.checked);
        saveOptions();
    }

    function bindEvents() {
        els.loginButton.addEventListener('click', function () {
            state.username = (els.loginUsername.value.trim() || options.username || 'root');
            state.password = els.loginPassword.value;
            if (!state.password) {
                setError('Enter the router password for ' + state.username + '.');
                return;
            }
            if (!options.username && state.username && state.username !== 'root') {
                options.username = state.username;
                saveOptions();
            }
            els.loginBox.style.display = 'none';
            restartPolling();
        });

        els.loginPassword.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') els.loginButton.click();
        });

        els.refreshButton.addEventListener('click', function () {
            if (!state.password) {
                els.loginBox.style.display = '';
                setError('Login first.');
                return;
            }
            pollData();
        });

        els.optionsButton.addEventListener('click', function () {
            fillOptionsModal();
            els.optionsModal.style.display = 'flex';
        });

        els.optionsCloseButton.addEventListener('click', function () {
            els.optionsModal.style.display = 'none';
        });

        els.optionsSaveButton.addEventListener('click', function () {
            readOptionsModal();
            els.optionsModal.style.display = 'none';
            applyOptionsToUi();
        });

        els.optionsModal.addEventListener('click', function (e) {
            if (e.target === els.optionsModal) els.optionsModal.style.display = 'none';
        });

        document.querySelectorAll('.card-title').forEach(function (heading) {
            heading.addEventListener('click', function () {
                const card = heading.closest('.collapsible');
                const table = card.getAttribute('data-table');
                const collapsed = !card.classList.contains('collapsed');
                card.classList.toggle('collapsed', collapsed);
                options.expanded[table] = !collapsed;
                saveOptions();
                const icon = card.querySelector('.toggle-icon');
                if (icon) icon.textContent = collapsed ? '+' : '−';
            });
        });

        document.querySelectorAll('[data-search-table]').forEach(function (input) {
            input.addEventListener('input', function () {
                applySearch(input.getAttribute('data-search-table'));
            });
        });

        document.querySelectorAll('th.sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                const tableEl = th.closest('table');
                const table = tableEl.id.replace('Table', '');
                const key = th.getAttribute('data-key');
                if (state.sort[table].key === key) {
                    state.sort[table].dir = state.sort[table].dir === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sort[table] = { key: key, dir: 'asc' };
                }
                document.querySelectorAll('#' + tableEl.id + ' th.sortable').forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
                th.classList.add(state.sort[table].dir === 'asc' ? 'sort-asc' : 'sort-desc');
                renderAll(state.data);
            });
        });

        document.addEventListener('click', function (e) {
            const target = e.target;
            if (target.matches('[data-graph-table="interfaces"], [data-graph-table="interfaces"] *') || target.closest('[data-graph-table="interfaces"]')) {
                state.interfaceGraphMode = state.interfaceGraphMode === 'traffic' ? 'temp' : 'traffic';
                redrawGraphs();
            }
        });
    }

    bindEvents();
    applyOptionsToUi();
    loadHistorySnapshot();

    if (!options.username) {
        els.loginUsername.placeholder = 'Username, default root';
    }
})();
</script>
</body>
</html>
