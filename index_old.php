<?php
// =========================
// Configuration
// =========================
$routerHost    = '192.168.0.1';      // Router IP / DNS name
$routerUser    = 'apiuser';          // RouterOS username
$routerPass    = 'YourPASSOWRD';        // RouterOS password
$verifyTls     = false;              // true if router certificate is trusted by this PHP server
$timeoutSec    = 10;
$sampleDelayUs = 1000000;         // 1 second sampling interval for interface rate calculation
$health        = [];
$resources     = [];

// Optional: if you want to sort interfaces in a custom order, add names here.
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
        return '—';
    }

    // Common RouterOS duration examples: 1d2h3m4s, 59m58s, 23h59m59s, 00:10:00 (varies by field/context)
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

function normalizeBoolString($value): bool
{
    return in_array(strtolower((string)$value), ['true', 'yes', 'on'], true);
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

function fetchInterfaceCounters(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec
): array {
    // Uses console-style print with stats through REST POST.
    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/interface/print',
        'POST',
		[
			'stats' => '',
			'.proplist' => '.id,name,comment,type,disabled,running,rx-byte,tx-byte,rx-packet,tx-packet',
		]
    );

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

function fetchSfpTemperatures(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec
): array {
    $ethernetRows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/interface/ethernet/print',
        'POST',
        [
            '.proplist' => '.id,name',
        ]
    );

    $temps = [];

    foreach ($ethernetRows as $eth) {
        $id = $eth['.id'] ?? '';
        $name = $eth['name'] ?? '';

        if ($id === '' || $name === '') {
            continue;
        }

        try {
            $monitorRows = routerosRequest(
                $routerHost,
                $routerUser,
                $routerPass,
                $verifyTls,
                $timeoutSec,
                '/interface/ethernet/monitor',
                'POST',
                [
                    '.id' => $id,
                    'once' => '',
                    '.proplist' => 'name,sfp-module-present,sfp-temperature',
                ]
            );

            $row = $monitorRows[0] ?? [];

            $sfpPresent = normalizeBoolString($row['sfp-module-present'] ?? 'false');
            $temp = $row['sfp-temperature'] ?? '';

            $temps[$name] = ($sfpPresent && $temp !== '') ? $temp . ' °C' : '—';
        } catch (Throwable $e) {
            $temps[$name] = '—';
        }
    }

    return $temps;
}

function fetchSystemHealth(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec
): array {
    return routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/system/health/print',
        'POST',
        [
            '.proplist' => 'name,value,type',
        ]
    );
}

function fetchSystemResources(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec
): array {
    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/system/resource/print',
        'POST'
    );

    return $rows[0] ?? [];
}

function fetchCpuResources(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec
): array {
    return routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/system/resource/cpu/print',
        'POST',
        [
            '.proplist' => 'cpu,load,irq,disk',
        ]
    );
}

function fetchRouterboardInfo(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec
): array {
    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/system/routerboard/print',
        'POST'
    );

    return $rows[0] ?? [];
}

function formatResourceBytesMiB($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return round(((float)$value) / 1024 / 1024, 1) . ' MiB';
}

function buildResourceRows(array $resource, array $cpuRows, array $routerboard): array
{
    $coreParts = [];

    foreach ($cpuRows as $index => $cpu) {
        $coreNo = $index + 1;
        $load = $cpu['load'] ?? '0';
        $coreParts[] = $coreNo . ':' . $load . '%';
    }

    $cpuLoad = $resource['cpu-load'] ?? '—';
    $cpuLoadText = $cpuLoad . '%';

    if ($coreParts) {
        $cpuLoadText .= ' (' . implode(', ', $coreParts) . ')';
    }
	
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

    return [
        ['name' => 'Uptime', 'value' => formatRouterOsDuration($resource['uptime'] ?? '')],

		['name' => 'Free Memory', 'value' =>formatResourceBytesMiB($freeMem) . ' (' . round($freePct, 1) . '% free)'],
		['name' => 'Total Memory', 'value' =>formatResourceBytesMiB($totalMem) . ' (' . round($usedPct, 1) . '% used)'],

        ['name' => 'CPU', 'value' => $resource['cpu'] ?? '—'],
        ['name' => 'CPU Count', 'value' => $resource['cpu-count'] ?? '—'],
        ['name' => 'CPU Frequency', 'value' => isset($resource['cpu-frequency']) ? $resource['cpu-frequency'] . ' MHz' : '—'],
        ['name' => 'CPU Load', 'value' => $cpuLoadText],
		
		['name' => 'Free HDD Space', 'value' => formatResourceBytesMiB($freeHdd) . ' (' . round($freeHddPct, 1) . '% free)'],
		['name' => 'Total HDD Size', 'value' => formatResourceBytesMiB($totalHdd) . ' (' . round($usedHddPct, 1) . '% used)'],	

        ['name' => 'Sector Writes Since Reboot', 'value' => $resource['write-sect-since-reboot'] ?? '—'],
        ['name' => 'Total Sector Writes', 'value' => $resource['write-sect-total'] ?? '—'],
        ['name' => 'Bad Blocks', 'value' => isset($resource['bad-blocks']) ? $resource['bad-blocks'] . '%' : '—'],

        ['name' => 'Architecture Name', 'value' => $resource['architecture-name'] ?? '—'],
        ['name' => 'Board Name', 'value' => $resource['board-name'] ?? '—'],
        ['name' => 'Version', 'value' => $resource['version'] ?? '—'],
        ['name' => 'Build Time', 'value' => $resource['build-time'] ?? '—'],
        ['name' => 'Factory Software', 'value' => $resource['factory-software'] ?? '—'],

        ['name' => 'Routerboard', 'value' => $routerboard['routerboard'] ?? '—'],
        ['name' => 'Model', 'value' => $routerboard['model'] ?? '—'],
        ['name' => 'Revision', 'value' => $routerboard['revision'] ?? '—'],
        ['name' => 'Serial Number', 'value' => $routerboard['serial-number'] ?? '—'],
        ['name' => 'Firmware Type', 'value' => $routerboard['firmware-type'] ?? '—'],
        ['name' => 'Factory Firmware', 'value' => $routerboard['factory-firmware'] ?? '—'],
        ['name' => 'Current Firmware', 'value' => $routerboard['current-firmware'] ?? '—'],
        ['name' => 'Upgrade Firmware', 'value' => $routerboard['upgrade-firmware'] ?? '—'],
    ];
}

function buildInterfaceRates(array $first, array $second, float $seconds, array $sfpTemps = []): array
{
    $interfaces = [];

    foreach ($second as $name => $now) {
		if (!empty($now['disabled'])) {
				continue;
			}
		
        $prev = $first[$name] ?? null;
        $rxDelta = $prev ? max(0, $now['rx_byte'] - $prev['rx_byte']) : 0;
        $txDelta = $prev ? max(0, $now['tx_byte'] - $prev['tx_byte']) : 0;

		$interfaces[] = [
			'name'            => $name,
			'comment'         => $now['comment'] ?? '',
			'type'            => $now['type'],
			'running'         => $now['running'],
			'disabled'        => $now['disabled'],
			'sfp_temperature' => $sfpTemps[$name] ?? '—',
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

function fetchDhcpLeases(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec
): array {
    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/ip/dhcp-server/lease/print',
        'POST',
        [
            '.proplist' => '.id,address,mac-address,host-name,server,status,expires-after,last-seen,active-address,active-mac-address,comment,dynamic,disabled',
        ]
    );

    $bridgeHosts = [];

    try {
        $hostRows = routerosRequest(
            $routerHost,
            $routerUser,
            $routerPass,
            $verifyTls,
            $timeoutSec,
            '/interface/bridge/host/print',
            'POST',
            [
                '.proplist' => 'mac-address,on-interface,bridge',
            ]
        );

        foreach ($hostRows as $hostRow) {
            $mac = strtoupper(trim((string)($hostRow['mac-address'] ?? '')));
            if ($mac === '') {
                continue;
            }

            $bridgeHosts[$mac] = [
                'on_interface' => $hostRow['on-interface'] ?? '',
                'bridge' => $hostRow['bridge'] ?? '',
            ];
        }
    } catch (Throwable $e) {
        $bridgeHosts = [];
    }

    $leases = [];

    foreach ($rows as $row) {
        $disabled = normalizeBoolString($row['disabled'] ?? 'false');
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
		$iface['sfp_temperature_text'] = $iface['sfp_temperature'] ?? '—';
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

$error = null;
$interfaces = [];
$leases = [];
$refreshedAt = '';
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

try {
    $start   = microtime(true);
    $first   = fetchInterfaceCounters($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
    usleep($sampleDelayUs);
    $second  = fetchInterfaceCounters($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
    $elapsed = max(microtime(true) - $start, 0.001);

	$sfpTemps        = fetchSfpTemperatures($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
	$interfaces      = buildInterfaceRates($first, $second, $elapsed, $sfpTemps);
	
	$resourceInfo    = fetchSystemResources($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
	$cpuInfo         = fetchCpuResources($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
	$routerboardInfo = fetchRouterboardInfo($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
	$resources       = buildResourceRows($resourceInfo, $cpuInfo, $routerboardInfo);	
	
    sortInterfaces($interfaces, $preferredInterfaceOrder);

    if ($isAjax) {
        $interfaces = buildAjaxInterfaceRows($interfaces);
    }

    $leases = fetchDhcpLeases($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
	$health = fetchSystemHealth($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec);
	$refreshedAt = date('Y-m-d H:i:s');

    if ($isAjax) {
        $leases = buildAjaxLeaseRows($leases);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'refreshedAt' => $refreshedAt,
            'sampleSeconds' => round($sampleDelayUs / 1000000, 2),
            'interfaces' => $interfaces,
            'leases' => $leases,
			'health' => $health,
			'resources' => $resources,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
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
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap {
            width: min(1500px, calc(100% - 32px));
            margin: 24px auto;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        h1, h2 {
            margin: 0;
            font-weight: 700;
        }
        h1 { font-size: 26px; }
        h2 { font-size: 18px; margin-bottom: 14px; }
        .muted { color: var(--muted); }
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        .card {
            background: linear-gradient(180deg, var(--panel), var(--panel-2));
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 18px;
            overflow: hidden;
        }
        .table-wrap { overflow-x: auto; }
		table {
			width: 100%;
			border-collapse: collapse;
			min-width: 1200px;
			table-layout: fixed;
		}

		th, td {
			padding: 10px 12px;
			border-bottom: 1px solid rgba(148, 163, 184, 0.15);
			text-align: left;
			font-size: 14px;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			vertical-align: middle;
		}

		th {
			color: #cbd5e1;
			background: rgba(255,255,255,0.03);
		}

		tr:hover td {
			background: rgba(255,255,255,0.03);
		}

		.sparkline {
			display: block;
			width: 290px;
			height: 40px;
			background: rgba(15,23,42,0.55);
			border: 1px solid rgba(148,163,184,0.18);
			border-radius: 8px;
		}

		.scale-cell {
			font-family: Consolas, Menlo, Monaco, monospace;
		}

		.col-iface      { width: 180px; }
		.col-type       { width: 120px; }
		.col-status     { width: 100px; }
		.col-rate       { width: 120px; }
		.col-total      { width: 130px; }
		.col-graph      { width: 310px; }
		.col-scale      { width: 120px; }
		.col-temp       { width: 110px; }

		.col-ip         { width: 130px; }
		.col-host       { width: 180px; }
		.col-comment    { width: 220px; }
		.col-mac        { width: 170px; }
		.col-bridge     { width: 120px; }
		.col-lease      { width: 130px; }
		.col-seen       { width: 120px; }
		.col-dtype      { width: 90px; }

        .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .up { background: rgba(34,197,94,0.15); color: #86efac; }
        .down { background: rgba(239,68,68,0.15); color: #fca5a5; }
        .disabled { background: rgba(245,158,11,0.15); color: #fcd34d; }
        .mono { font-family: Consolas, Menlo, Monaco, monospace; }
        .error {
            border: 1px solid rgba(239,68,68,0.4);
            background: rgba(127,29,29,0.3);
            color: #fecaca;
            padding: 14px 16px;
            border-radius: 12px;
        }
        .toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 14px;
        }
        input[type="search"] {
            width: min(340px, 100%);
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: rgba(15,23,42,0.8);
            color: var(--text);
            outline: none;
        }
        .small { font-size: 12px; }
        a.button {
            color: white;
            text-decoration: none;
            border: 1px solid var(--line);
            background: rgba(56,189,248,0.12);
            padding: 10px 12px;
            border-radius: 10px;
        }
		.collapsible h2 {
			cursor: pointer;
			user-select: none;
		}

		.collapsible h2::after {
			content: " −";
			color: var(--muted);
		}

		.collapsible.collapsed h2::after {
			content: " +";
		}

		.collapsible.collapsed .table-wrap,
		.collapsible.collapsed .toolbar,
		.collapsible.collapsed .small {
			display: none;
		}
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1>MikroTik Router Dashboard</h1>
            <div class="muted small">Router: <?= h($routerHost) ?> • Refreshed: <span id="refreshedAt"><?= h($refreshedAt) ?></span></div>
        </div>
        <div>
            <span class="muted small" id="pollStatus">Auto-update every 3s</span>
			<a class="button" href="?t=<?= time() ?>">Refresh</a>            
        </div>
    </div>

    <?php if ($error !== null): ?>
        <div class="error">
            <strong>Failed to load router data.</strong><br>
            <?= h($error) ?>
        </div>
    <?php else: ?>
        <div class="grid">
            <section class="card collapsible">
                <h2>Interfaces</h2>
                <div class="table-wrap">
                    <table>
						<thead>
						<tr>
							<th class="col-iface">Interface</th>
							<th class="col-type">Type</th>
							<th class="col-status">Status</th>
							<th class="col-temp">SFP Temp</th>
							<th class="col-rate">Download</th>
							<th class="col-rate">Upload</th>
							<th class="col-total">Total RX</th>
							<th class="col-total">Total TX</th>
							<th class="col-graph"><span id="pollStatus2">Traffic (last 60s)</span></th>
							<th class="col-scale">Scale</th>
						</tr>
						</thead>
                        <tbody id="interfacesBody">
                        <?php foreach ($interfaces as $iface): ?>
                            <?php
                                $statusClass = $iface['disabled'] ? 'disabled' : ($iface['running'] ? 'up' : 'down');
                                $statusText  = $iface['disabled'] ? 'Disabled' : ($iface['running'] ? 'Up' : 'Down');
                            ?>
						<tr>
						<td class="mono" title="<?= h($iface['name']) ?>"><a href="interface.php?iface=<?= rawurlencode($iface['name']) ?>" style="color: inherit; text-decoration: none;"><?= h(($iface['comment'] ?? '') !== '' ? $iface['comment'] : $iface['name']) ?></a></td>
							<td><?= h($iface['type']) ?></td>
							<td><span class="status <?= h($statusClass) ?>"><?= h($statusText) ?></span></td>
							<td class="mono"><?= h($iface['sfp_temperature'] ?? '—') ?></td>
							<td><?= h(formatBitsPerSecond($iface['rx_bps'])) ?></td>
							<td><?= h(formatBitsPerSecond($iface['tx_bps'])) ?></td>
							<td><?= h(formatBytes($iface['rx_bytes'])) ?></td>
							<td><?= h(formatBytes($iface['tx_bytes'])) ?></td>
							<td><canvas class="sparkline" width="290" height="40" data-name="<?= h($iface['name']) ?>"></canvas></td>
							<td class="scale-cell">—</td>
						</tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="muted small" style="margin-top: 10px;">
                    Current throughput is estimated from byte-counter deltas over ~<span id="sampleSeconds"><?= h((string)round($sampleDelayUs / 1000000, 2)) ?></span> seconds.
                </div>
            </section>
			
			<section class="card collapsible">
				<h2>System Health</h2>
				<div class="table-wrap">
					<table>
						<thead>
						<tr>
							<th>Name</th>
							<th>Value</th>
							<th>Type</th>
						</tr>
						</thead>
						<tbody id="healthBody">
						<?php foreach ($health as $item): ?>
							<tr>
								<td class="mono"><?= h($item['name'] ?? '') ?></td>
								<td class="mono"><?= h($item['value'] ?? '—') ?></td>
								<td><?= h($item['type'] ?? '') ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
			
			<section class="card collapsible">
				<h2>Resources</h2>
				<div class="table-wrap">
					<table>
						<thead>
						<tr>
							<th>Name</th>
							<th>Value</th>
						</tr>
						</thead>
						<tbody id="resourcesBody">
						<?php foreach ($resources as $item): ?>
							<tr>
								<td class="mono"><?= h($item['name'] ?? '') ?></td>
								<td class="mono"><?= h($item['value'] ?? '—') ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>

            <section class="card collapsible">
                <h2>DHCP Leases</h2>
                <div class="toolbar">
                    <input type="search" id="leaseSearch" placeholder="Filter by IP, hostname, MAC, server, comment...">
                </div>
                <div class="table-wrap">
                    <table id="leasesTable">
						<thead>
						<tr>
							<th class="col-ip">IP Address</th>
							<th class="col-host">Host Name</th>	
							<th class="col-comment">Comment</th>
							<th class="col-dtype">Type</th>
							<th class="col-mac">MAC Address</th>
							<th class="col-bridge">Bridge Port</th>
							<th class="col-lease">Lease Time Left</th>
							<th class="col-seen">Last Seen</th>
						</tr>
						</thead>
                        <tbody id="leasesBody">
                        <?php foreach ($leases as $lease): ?>
                            <?php
                                $leaseStatus = $lease['disabled'] ? 'Disabled' : ($lease['status'] !== '' ? $lease['status'] : 'Unknown');
                                $type = $lease['dynamic'] ? 'Dynamic' : 'Static';
                            ?>
                            <tr>
                                <td class="mono"><?= h($lease['address']) ?></td>
                                <td><?= h($lease['host_name'] !== '' ? $lease['host_name'] : '—') ?></td>
                                <td><?= h($lease['comment'] !== '' ? $lease['comment'] : '—') ?></td>
								<td><?= h($type) ?></td> 
								<td class="mono"><?= h($lease['mac_address']) ?></td>
								<td><?= h($lease['bridge_port'] !== '' ? $lease['bridge_port'] : '—') ?></td>
                                <td><?= h(formatRouterOsDuration($lease['expires_after'])) ?></td>
                                <td><?= h(formatRouterOsDuration($lease['last_seen'])) ?></td>                                                               
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>

<script>
window.__initialInterfaces = <?= json_encode($interfaces, JSON_UNESCAPED_SLASHES) ?>;
window.__initialLeases     = <?= json_encode($leases, JSON_UNESCAPED_SLASHES) ?>;
window.__initialHealth     = <?= json_encode($health, JSON_UNESCAPED_SLASHES) ?>;
window.__initialResources  = <?= json_encode($resources, JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
(function () {
    const input          = document.getElementById('leaseSearch');
    const table          = document.getElementById('leasesTable');
    const interfacesBody = document.getElementById('interfacesBody');
    const leasesBody     = document.getElementById('leasesBody');
    const refreshedAt    = document.getElementById('refreshedAt');
    const sampleSeconds  = document.getElementById('sampleSeconds');
	const pollStatus     = document.getElementById('pollStatus');
	const pollStatus2    = document.getElementById('pollStatus2');
	const healthBody     = document.getElementById('healthBody');
	const resourcesBody  = document.getElementById('resourcesBody');
	
	const GRAPH_CONFIG = {
		refreshSeconds: 3,
		displaySeconds: 300,
		width: 300,
		height: 40,
		lineWidth: 1.8,
		downloadColor: '#22c55e',
		uploadColor: '#ef4444',
		baselineColor: 'rgba(148,163,184,0.18)',
		scaleHeadroom: 1.10
	};
	const pollIntervalMs = GRAPH_CONFIG.refreshSeconds * 1000;
	const maxHistoryPoints = Math.max(1, Math.floor(GRAPH_CONFIG.displaySeconds / GRAPH_CONFIG.refreshSeconds));
	const trafficHistory = {};
		let isPolling = false;
		
		if (pollStatus) {
		pollStatus.textContent = `Auto-update every ${GRAPH_CONFIG.refreshSeconds}s • Graph ${GRAPH_CONFIG.displaySeconds}s`;
	}
	
		if (pollStatus2) {
		pollStatus2.textContent = `Traffic (last ${GRAPH_CONFIG.displaySeconds}s)`;
	}
	
	function renderHealth(items) {
		if (!healthBody) return;

		healthBody.innerHTML = items.map(function (item) {
			return `
				<tr>
					<td class="mono">${escapeHtml(item.name || '')}</td>
					<td class="mono">${escapeHtml(item.value || '—')}</td>
					<td>${escapeHtml(item.type || '')}</td>
				</tr>`;
		}).join('');
	}
	
	function renderResources(items) {
		if (!resourcesBody) return;

		resourcesBody.innerHTML = items.map(function (item) {
			return `
				<tr>
					<td class="mono">${escapeHtml(item.name || '')}</td>
					<td class="mono">${escapeHtml(item.value || '—')}</td>
				</tr>`;
		}).join('');
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

		function applyLeaseFilter() {
			if (!input || !table) return;
			const q = input.value.trim().toLowerCase();
			const rows = Array.from(table.querySelectorAll('tbody tr'));
			rows.forEach(function (row) {
				const text = row.innerText.toLowerCase();
				row.style.display = q === '' || text.includes(q) ? '' : 'none';
			});
		}

		function pushTrafficHistory(items) {
			items.forEach(function (iface) {
				const name = String(iface.name || '');
				if (!name) return;

				if (!trafficHistory[name]) {
					trafficHistory[name] = [];
				}

				trafficHistory[name].push({
					rx: Number(iface.rx_bps || 0),
					tx: Number(iface.tx_bps || 0)
				});

				if (trafficHistory[name].length > maxHistoryPoints) {
					trafficHistory[name] = trafficHistory[name].slice(-maxHistoryPoints);
				}
			});
		}

		function drawSparkline(canvas, history) {
		if (!canvas || !canvas.getContext) return 0;

		const ctx = canvas.getContext('2d');
		const width = canvas.width;
		const height = canvas.height;

		ctx.clearRect(0, 0, width, height);

		const leftPad = 2;
		const rightPad = 2;
		const topPad = 3;
		const bottomPad = 3;
		const graphWidth = width - leftPad - rightPad;
		const graphHeight = height - topPad - bottomPad;

		ctx.strokeStyle = GRAPH_CONFIG.baselineColor;
		ctx.lineWidth = 1;
		ctx.beginPath();
		ctx.moveTo(leftPad, height - bottomPad);
		ctx.lineTo(width - rightPad, height - bottomPad);
		ctx.stroke();

		if (!history || history.length < 1) {
			return 0;
		}

		let maxVal = 0;
		history.forEach(function (point) {
			maxVal = Math.max(maxVal, Number(point.rx || 0), Number(point.tx || 0));
		});

		const scaledMax = maxVal > 0 ? maxVal * GRAPH_CONFIG.scaleHeadroom : 1;
		const slotCount = maxHistoryPoints;
		const slotWidth = slotCount > 1 ? graphWidth / (slotCount - 1) : graphWidth;

		function getPointY(value) {
			return topPad + (graphHeight - ((value / scaledMax) * graphHeight));
		}

		function drawLine(key, color) {
			const values = history.map(point => Number(point[key] || 0));
			const offset = slotCount - values.length;

			ctx.beginPath();
			ctx.lineWidth = GRAPH_CONFIG.lineWidth;
			ctx.strokeStyle = color;

			values.forEach(function (value, index) {
				const slotIndex = offset + index;
				const x = leftPad + (slotIndex * slotWidth);
				const y = getPointY(value);

				if (index === 0) {
					ctx.moveTo(x, y);
				} else {
					ctx.lineTo(x, y);
				}
			});

			ctx.stroke();
		}

		if (history.length >= 2) {
			drawLine('rx', GRAPH_CONFIG.downloadColor);
			drawLine('tx', GRAPH_CONFIG.uploadColor);
		}

		return scaledMax;
	}

	function redrawSparklines() {
		const canvases = document.querySelectorAll('canvas.sparkline');

		canvases.forEach(function (canvas) {
			const name = canvas.getAttribute('data-name') || '';
			const history = trafficHistory[name] || [];
			const scale = drawSparkline(canvas, history);
			const row = canvas.closest('tr');
			
			if (!row) return;

			const scaleCell = row.querySelector('.scale-cell');
			
			if (scaleCell) {
				scaleCell.textContent = scale > 0 ? formatBitsPerSecond(scale) : '—';
			}
		});
	}

	function renderInterfaces(items) {
		items = items.filter(function (iface) {
			return !iface.disabled && iface.status_text !== 'Disabled';
		});

		pushTrafficHistory(items);

		interfacesBody.innerHTML = items.map(function (iface) {
			return `
				<tr>			
					<td class="mono" title="${escapeHtml(iface.name)}"><a href="interface.php?iface=${encodeURIComponent(iface.name)}" style="color: inherit; text-decoration: none;">${escapeHtml(iface.comment || iface.name)}</a></td>				
					<td title="${escapeHtml(iface.type)}">${escapeHtml(iface.type)}</td>
					<td><span class="status ${escapeHtml(iface.status_class || '')}">${escapeHtml(iface.status_text || '')}</span></td>
					<td class="mono">${escapeHtml(iface.sfp_temperature_text || iface.sfp_temperature || '—')}</td>					
					<td>${escapeHtml(iface.rx_bps_text)}</td>
					<td>${escapeHtml(iface.tx_bps_text)}</td>
					<td>${escapeHtml(iface.rx_bytes_text)}</td>
					<td>${escapeHtml(iface.tx_bytes_text)}</td>
					<td><canvas class="sparkline" width="${GRAPH_CONFIG.width}" height="${GRAPH_CONFIG.height}" data-name="${escapeHtml(iface.name)}"></canvas></td>
					<td class="scale-cell">—</td>
				</tr>`;
		}).join('');

		redrawSparklines();
	}

    function renderLeases(items) {
        leasesBody.innerHTML = items.map(function (lease) {
            return `
                <tr>
                    <td class="mono">${escapeHtml(lease.address)}</td>
                    <td>${escapeHtml(lease.host_name || '—')}</td>                       
					<td>${escapeHtml(lease.comment || '—')}</td>
					<td>${escapeHtml(lease.dynamic_text || '')}</td> 
                    <td class="mono">${escapeHtml(lease.mac_address)}</td>
                    <td>${escapeHtml(lease.bridge_port || '—')}</td>
                    <td>${escapeHtml(lease.expires_after_text || '')}</td>
                    <td>${escapeHtml(lease.last_seen_text || '')}</td>                
                </tr>`;
        }).join('');

        applyLeaseFilter();
    }

    async function pollData() {
        if (isPolling) return;
        isPolling = true;
        if (pollStatus) pollStatus.textContent = 'Updating...';

        try {
            const response = await fetch(window.location.pathname + '?ajax=1&t=' + Date.now(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Failed to load updated data');
            }

            renderInterfaces(data.interfaces || []);
            renderLeases(data.leases || []);
			renderHealth(data.health || []);
			renderResources(data.resources || []);

            if (refreshedAt) refreshedAt.textContent = data.refreshedAt || '';
            if (sampleSeconds) sampleSeconds.textContent = data.sampleSeconds || '';
            if (pollStatus) pollStatus.textContent = `Auto-update every ${GRAPH_CONFIG.refreshSeconds}s • Graph ${GRAPH_CONFIG.displaySeconds}s`;
        } catch (error) {
            if (pollStatus) pollStatus.textContent = 'Update failed: ' + error.message;
            console.error(error);
        } finally {
            isPolling = false;
        }
    }

    if (input) {
        input.addEventListener('input', applyLeaseFilter);
    }

    renderInterfaces(window.__initialInterfaces || []);
    renderLeases(window.__initialLeases || []);
	renderHealth(window.__initialHealth || []);
	renderResources(window.__initialResources || []);
    setInterval(pollData, pollIntervalMs);
	
	document.querySelectorAll('.collapsible h2').forEach(function (heading) {
		heading.addEventListener('click', function () {
			const card = heading.closest('.collapsible');
			if (card) {
				card.classList.toggle('collapsed');
			}
		});
	});	
})();
</script>
</body>
</html>
