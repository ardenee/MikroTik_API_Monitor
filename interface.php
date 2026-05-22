<?php
// =========================
// Configuration
// =========================
$routerHost = '192.168.0.1';
$routerUser = 'apiuser';
$routerPass = 'MyPASSWORD';        // RouterOS password
$verifyTls  = false;
$timeoutSec = 10;
$sampleDelayUs = 1000000;

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
    int $timeoutSec,
    string $interfaceName
): array {
    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/interface/print',
        'POST',
        [
            'stats-detail' => '',
            '.query' => ['name=' . $interfaceName],
            '.proplist' => '.id,name,type,disabled,running,mtu,l2mtu,mac-address,last-link-up-time,last-link-down-time,link-downs,rx-byte,tx-byte,rx-packet,tx-packet,tx-queue-drop,fp-rx-byte,fp-tx-byte,fp-rx-packet,fp-tx-packet',
        ]
    );

    return $rows[0] ?? [];
}

function buildInterfaceRateRow(array $first, array $second, float $seconds): array
{
    $rxDelta = max(0, (float)($second['rx-byte'] ?? 0) - (float)($first['rx-byte'] ?? 0));
    $txDelta = max(0, (float)($second['tx-byte'] ?? 0) - (float)($first['tx-byte'] ?? 0));
    $rxBps = $seconds > 0 ? ($rxDelta * 8) / $seconds : 0;
    $txBps = $seconds > 0 ? ($txDelta * 8) / $seconds : 0;

    $running = normalizeBoolString($second['running'] ?? 'false');
    $disabled = normalizeBoolString($second['disabled'] ?? 'false');

    return [
        'name' => $second['name'] ?? '',
        'type' => $second['type'] ?? '',
        'running' => $running,
        'disabled' => $disabled,
        'status_text' => $disabled ? 'Disabled' : ($running ? 'Up' : 'Down'),
        'status_class' => $disabled ? 'disabled' : ($running ? 'up' : 'down'),
        'rx_bps' => $rxBps,
        'tx_bps' => $txBps,
        'rx_bps_text' => formatBitsPerSecond($rxBps),
        'tx_bps_text' => formatBitsPerSecond($txBps),
        'rx_bytes' => (float)($second['rx-byte'] ?? 0),
        'tx_bytes' => (float)($second['tx-byte'] ?? 0),
        'rx_bytes_text' => formatBytes((float)($second['rx-byte'] ?? 0)),
        'tx_bytes_text' => formatBytes((float)($second['tx-byte'] ?? 0)),
        'rx_packets' => (float)($second['rx-packet'] ?? 0),
        'tx_packets' => (float)($second['tx-packet'] ?? 0),
        'tx_queue_drop' => (float)($second['tx-queue-drop'] ?? 0),
        'tx_queue_drop_text' => number_format((float)($second['tx-queue-drop'] ?? 0)),
        'fp_rx_bytes_text' => formatBytes((float)($second['fp-rx-byte'] ?? 0)),
        'fp_tx_bytes_text' => formatBytes((float)($second['fp-tx-byte'] ?? 0)),
        'mtu' => $second['mtu'] ?? '—',
        'l2mtu' => $second['l2mtu'] ?? '—',
        'mac_address' => $second['mac-address'] ?? '—',
        'last_link_up_time' => $second['last-link-up-time'] ?? '—',
        'last_link_down_time' => $second['last-link-down-time'] ?? '—',
        'link_downs' => $second['link-downs'] ?? '0',
    ];
}

function fetchInterfaceAddresses(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec,
    string $interfaceName
): array {
    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/ip/address/print',
        'POST',
        [
            '.query' => ['interface=' . $interfaceName],
            '.proplist' => 'address,network,interface,disabled,dynamic,actual-interface',
        ]
    );

    return array_map(function ($row) {
        return [
            'address' => $row['address'] ?? '',
            'network' => $row['network'] ?? '',
            'interface' => $row['interface'] ?? '',
            'actual_interface' => $row['actual-interface'] ?? '',
            'dynamic' => normalizeBoolString($row['dynamic'] ?? 'false'),
        ];
    }, $rows);
}

function fetchBridgeHostsForInterface(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec,
    string $interfaceName
): array {
    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/interface/bridge/host/print',
        'POST',
        [
            '.proplist' => 'mac-address,on-interface,bridge,vid,age,local,external,hw-offload',
        ]
    );

    $hosts = [];
    foreach ($rows as $row) {
        if (($row['on-interface'] ?? '') !== $interfaceName) {
            continue;
        }
        $hosts[] = [
            'mac_address' => strtoupper((string)($row['mac-address'] ?? '')),
            'bridge' => $row['bridge'] ?? '',
            'on_interface' => $row['on-interface'] ?? '',
            'vid' => $row['vid'] ?? '',
            'age' => $row['age'] ?? '',
            'local' => normalizeBoolString($row['local'] ?? 'false'),
            'external' => normalizeBoolString($row['external'] ?? 'false'),
            'hw_offload' => normalizeBoolString($row['hw-offload'] ?? 'false'),
        ];
    }

    usort($hosts, function ($a, $b) {
        return strnatcasecmp($a['mac_address'], $b['mac_address']);
    });

    return $hosts;
}

function fetchArpForInterface(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec,
    string $interfaceName
): array {
    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/ip/arp/print',
        'POST',
        [
            '.proplist' => 'address,mac-address,interface,complete,published,dynamic,dhcp,invalid,disabled,status',
        ]
    );

    $arp = [];
    foreach ($rows as $row) {
        if (($row['interface'] ?? '') !== $interfaceName) {
            continue;
        }
        $arp[] = [
            'address' => $row['address'] ?? '',
            'mac_address' => strtoupper((string)($row['mac-address'] ?? '')),
            'interface' => $row['interface'] ?? '',
            'status' => $row['status'] ?? '',
            'dynamic' => normalizeBoolString($row['dynamic'] ?? 'false'),
            'dhcp' => normalizeBoolString($row['dhcp'] ?? 'false'),
            'complete' => normalizeBoolString($row['complete'] ?? 'false'),
        ];
    }

    usort($arp, function ($a, $b) {
        return strnatcasecmp($a['address'], $b['address']);
    });

    return $arp;
}

function fetchDhcpLeasesForInterface(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec,
    string $interfaceName
): array {
    $hostRows = [];
    try {
        $hostRows = fetchBridgeHostsForInterface($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $interfaceName);
    } catch (Throwable $e) {
        $hostRows = [];
    }

    $macs = [];
    foreach ($hostRows as $hostRow) {
        if (!empty($hostRow['mac_address'])) {
            $macs[$hostRow['mac_address']] = true;
        }
    }

    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/ip/dhcp-server/lease/print',
        'POST',
        [
            '.proplist' => '.id,address,mac-address,host-name,expires-after,last-seen,comment,dynamic,disabled,active-mac-address,active-address',
        ]
    );

    $leases = [];
    foreach ($rows as $row) {
        $mac = strtoupper((string)($row['mac-address'] ?? ($row['active-mac-address'] ?? '')));
        if ($mac === '' || !isset($macs[$mac])) {
            continue;
        }
        $dynamic = normalizeBoolString($row['dynamic'] ?? 'false');
        $leases[] = [
            'address' => $row['address'] ?? ($row['active-address'] ?? ''),
            'mac_address' => $mac,
            'host_name' => $row['host-name'] ?? '',
            'expires_after_text' => formatRouterOsDuration($row['expires-after'] ?? ''),
            'last_seen_text' => formatRouterOsDuration($row['last-seen'] ?? ''),
            'comment' => $row['comment'] ?? '',
            'dynamic_text' => $dynamic ? 'Dynamic' : 'Static',
        ];
    }

    usort($leases, function ($a, $b) {
        return strnatcasecmp($a['address'], $b['address']);
    });

    return $leases;
}

function fetchRelatedConnections(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec,
    array $arpEntries
): array {
    $hostIps = [];
    foreach ($arpEntries as $entry) {
        $ip = trim((string)($entry['address'] ?? ''));
        if ($ip !== '') {
            $hostIps[$ip] = true;
        }
    }

    if (!$hostIps) {
        return [];
    }

    $rows = routerosRequest(
        $routerHost,
        $routerUser,
        $routerPass,
        $verifyTls,
        $timeoutSec,
        '/ip/firewall/connection/print',
        'POST',
        [
            '.proplist' => 'protocol,orig-src-address,orig-dst-address,orig-src-port,orig-dst-port,repl-src-address,repl-dst-address,repl-src-port,repl-dst-port,tcp-state,timeout,orig-rate,repl-rate,connection-mark,fasttrack,assured,seen-reply',
        ]
    );

    $connections = [];
    foreach ($rows as $row) {
        $origSrc = trim((string)($row['orig-src-address'] ?? ''));
        $origDst = trim((string)($row['orig-dst-address'] ?? ''));
        $replSrc = trim((string)($row['repl-src-address'] ?? ''));
        $replDst = trim((string)($row['repl-dst-address'] ?? ''));

        $related = isset($hostIps[$origSrc]) || isset($hostIps[$origDst]) || isset($hostIps[$replSrc]) || isset($hostIps[$replDst]);
        if (!$related) {
            continue;
        }

        $origRate = (float)($row['orig-rate'] ?? 0);
        $replRate = (float)($row['repl-rate'] ?? 0);

        $connections[] = [
            'protocol' => $row['protocol'] ?? '',
            'orig_src_address' => $origSrc,
            'orig_src_port' => $row['orig-src-port'] ?? '',
            'orig_dst_address' => $origDst,
            'orig_dst_port' => $row['orig-dst-port'] ?? '',
            'repl_src_address' => $replSrc,
            'repl_src_port' => $row['repl-src-port'] ?? '',
            'repl_dst_address' => $replDst,
            'repl_dst_port' => $row['repl-dst-port'] ?? '',
            'tcp_state' => $row['tcp-state'] ?? '',
            'timeout' => $row['timeout'] ?? '',
            'orig_rate' => $origRate,
            'repl_rate' => $replRate,
            'orig_rate_text' => formatBitsPerSecond($origRate),
            'repl_rate_text' => formatBitsPerSecond($replRate),
            'fasttrack' => normalizeBoolString($row['fasttrack'] ?? 'false'),
            'assured' => normalizeBoolString($row['assured'] ?? 'false'),
            'seen_reply' => normalizeBoolString($row['seen-reply'] ?? 'false'),
        ];
    }

    usort($connections, function ($a, $b) {
        $maxA = max((float)$a['orig_rate'], (float)$a['repl_rate']);
        $maxB = max((float)$b['orig_rate'], (float)$b['repl_rate']);
        return $maxB <=> $maxA;
    });

    return array_slice($connections, 0, 100);
}

function buildPayload(
    string $routerHost,
    string $routerUser,
    string $routerPass,
    bool $verifyTls,
    int $timeoutSec,
    int $sampleDelayUs,
    string $interfaceName
): array {
    $start = microtime(true);
    $first = fetchInterfaceCounters($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $interfaceName);
    if (!$first) {
        throw new RuntimeException('Interface not found: ' . $interfaceName);
    }
    usleep($sampleDelayUs);
    $second = fetchInterfaceCounters($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $interfaceName);
    $elapsed = max(microtime(true) - $start, 0.001);

    $interface = buildInterfaceRateRow($first, $second, $elapsed);
    $addresses = fetchInterfaceAddresses($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $interfaceName);
    $bridgeHosts = fetchBridgeHostsForInterface($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $interfaceName);
    $arpEntries = fetchArpForInterface($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $interfaceName);
    $dhcpLeases = fetchDhcpLeasesForInterface($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $interfaceName);
    $connections = fetchRelatedConnections($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $arpEntries);

    return [
        'ok' => true,
        'refreshedAt' => date('Y-m-d H:i:s'),
        'sampleSeconds' => round($sampleDelayUs / 1000000, 2),
        'interface' => $interface,
        'addresses' => $addresses,
        'bridgeHosts' => $bridgeHosts,
        'arpEntries' => $arpEntries,
        'dhcpLeases' => $dhcpLeases,
        'connections' => $connections,
    ];
}

$interfaceName = trim((string)($_GET['iface'] ?? ''));
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$error = null;
$data = null;

if ($interfaceName === '') {
    $error = 'Missing interface name. Open this page with ?iface=INTERFACE_NAME';
} else {
    try {
        $data = buildPayload($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $sampleDelayUs, $interfaceName);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data, JSON_UNESCAPED_SLASHES);
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
}

$interface = $data['interface'] ?? [];
$addresses = $data['addresses'] ?? [];
$bridgeHosts = $data['bridgeHosts'] ?? [];
$arpEntries = $data['arpEntries'] ?? [];
$dhcpLeases = $data['dhcpLeases'] ?? [];
$connections = $data['connections'] ?? [];
$refreshedAt = $data['refreshedAt'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MikroTik Interface Detail</title>
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
            width: min(1600px, calc(100% - 32px));
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
        h1, h2, h3 { margin: 0; }
        h1 { font-size: 26px; }
        h2 { font-size: 18px; margin-bottom: 14px; }
        h3 { font-size: 15px; margin-bottom: 10px; }
        .muted { color: var(--muted); }
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }
        .card {
            background: linear-gradient(180deg, var(--panel), var(--panel-2));
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 18px;
            overflow: hidden;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat {
            border: 1px solid rgba(148,163,184,0.16);
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            padding: 12px;
        }
        .stat .label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 6px;
        }
        .stat .value {
            font-size: 18px;
            font-weight: 700;
        }
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
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
        .small { font-size: 12px; }
        a.button {
            color: white;
            text-decoration: none;
            border: 1px solid var(--line);
            background: rgba(56,189,248,0.12);
            padding: 10px 12px;
            border-radius: 10px;
            display: inline-block;
        }
        .sparkline {
            display: block;
            width: 100%;
            max-width: 100%;
            height: 90px;
            background: rgba(15,23,42,0.55);
            border: 1px solid rgba(148,163,184,0.18);
            border-radius: 8px;
        }
        .meta-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .meta-item {
            border: 1px solid rgba(148,163,184,0.16);
            background: rgba(255,255,255,0.03);
            border-radius: 10px;
            padding: 10px;
        }
        .meta-item .label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 4px;
        }
        .meta-item .value {
            font-weight: 700;
        }
        @media (max-width: 980px) {
            .stats-grid, .grid-2, .meta-list {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1>Interface Detail: <?= h($interfaceName) ?></h1>
            <div class="muted small">Router: <?= h($routerHost) ?> • Refreshed: <span id="refreshedAt"><?= h($refreshedAt) ?></span></div>
        </div>
        <div>
            <span class="muted small" id="pollStatus">Auto-update every 3s • Graph 60s</span>
            <a class="button" href="?iface=<?= rawurlencode($interfaceName) ?>&t=<?= time() ?>">Refresh</a>
        </div>
    </div>

    <?php if ($error !== null): ?>
        <div class="error">
            <strong>Failed to load interface data.</strong><br>
            <?= h($error) ?>
        </div>
    <?php else: ?>
        <div class="grid">
            <section class="card">
                <h2>Live Interface Traffic</h2>
                <div class="stats-grid">
                    <div class="stat">
                        <div class="label">Status</div>
                        <div class="value"><span class="status <?= h($interface['status_class'] ?? '') ?>" id="ifaceStatus"><?= h($interface['status_text'] ?? '') ?></span></div>
                    </div>
                    <div class="stat">
                        <div class="label">Download</div>
                        <div class="value" id="rxRate"><?= h($interface['rx_bps_text'] ?? '—') ?></div>
                    </div>
                    <div class="stat">
                        <div class="label">Upload</div>
                        <div class="value" id="txRate"><?= h($interface['tx_bps_text'] ?? '—') ?></div>
                    </div>
                    <div class="stat">
                        <div class="label">Graph Scale</div>
                        <div class="value mono" id="graphScale">—</div>
                    </div>
                </div>
                <canvas class="sparkline" id="trafficGraph" width="1200" height="90"></canvas>
                <div class="muted small" style="margin-top: 10px;">
                    Current throughput is estimated from byte-counter deltas over ~<span id="sampleSeconds"><?= h((string)($data['sampleSeconds'] ?? '1')) ?></span> seconds.
                </div>
            </section>

            <section class="card">
                <h2>Interface Details</h2>
                <div class="meta-list" id="metaList">
                    <div class="meta-item"><div class="label">Type</div><div class="value" id="metaType"><?= h($interface['type'] ?? '—') ?></div></div>
                    <div class="meta-item"><div class="label">MAC Address</div><div class="value mono" id="metaMac"><?= h($interface['mac_address'] ?? '—') ?></div></div>
                    <div class="meta-item"><div class="label">MTU / L2MTU</div><div class="value" id="metaMtu"><?= h(($interface['mtu'] ?? '—') . ' / ' . ($interface['l2mtu'] ?? '—')) ?></div></div>
                    <div class="meta-item"><div class="label">Total RX</div><div class="value" id="metaRxBytes"><?= h($interface['rx_bytes_text'] ?? '—') ?></div></div>
                    <div class="meta-item"><div class="label">Total TX</div><div class="value" id="metaTxBytes"><?= h($interface['tx_bytes_text'] ?? '—') ?></div></div>
                    <div class="meta-item"><div class="label">TX Queue Drop</div><div class="value" id="metaQueueDrop"><?= h($interface['tx_queue_drop_text'] ?? '—') ?></div></div>
                    <div class="meta-item"><div class="label">FastPath RX</div><div class="value" id="metaFpRx"><?= h($interface['fp_rx_bytes_text'] ?? '—') ?></div></div>
                    <div class="meta-item"><div class="label">FastPath TX</div><div class="value" id="metaFpTx"><?= h($interface['fp_tx_bytes_text'] ?? '—') ?></div></div>
                    <div class="meta-item"><div class="label">Link Downs</div><div class="value" id="metaLinkDowns"><?= h($interface['link_downs'] ?? '—') ?></div></div>
                    <div class="meta-item"><div class="label">Last Link Up</div><div class="value" id="metaLastUp"><?= h($interface['last_link_up_time'] ?? '—') ?></div></div>
                    <div class="meta-item"><div class="label">Last Link Down</div><div class="value" id="metaLastDown"><?= h($interface['last_link_down_time'] ?? '—') ?></div></div>
                </div>
            </section>

            <div class="grid-2">
                <section class="card">
                    <h2>IP Addresses on Interface</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Address</th>
                                <th>Network</th>
                                <th>Actual Interface</th>
                                <th>Type</th>
                            </tr>
                            </thead>
                            <tbody id="addressesBody">
                            <?php foreach ($addresses as $row): ?>
                                <tr>
                                    <td class="mono"><?= h($row['address']) ?></td>
                                    <td class="mono"><?= h($row['network']) ?></td>
                                    <td><?= h($row['actual_interface'] !== '' ? $row['actual_interface'] : $row['interface']) ?></td>
                                    <td><?= h(!empty($row['dynamic']) ? 'Dynamic' : 'Static') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card">
                    <h2>Bridge Hosts Learned on Interface</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>MAC</th>
                                <th>Bridge</th>
                                <th>VLAN</th>
                                <th>Age</th>
                                <th>HW Offload</th>
                            </tr>
                            </thead>
                            <tbody id="bridgeHostsBody">
                            <?php foreach ($bridgeHosts as $row): ?>
                                <tr>
                                    <td class="mono"><?= h($row['mac_address']) ?></td>
                                    <td><?= h($row['bridge']) ?></td>
                                    <td><?= h($row['vid'] !== '' ? $row['vid'] : '—') ?></td>
                                    <td><?= h($row['age'] !== '' ? $row['age'] : '—') ?></td>
                                    <td><?= h(!empty($row['hw_offload']) ? 'Yes' : 'No') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="grid-2">
                <section class="card">
                    <h2>ARP Entries on Interface</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>IP</th>
                                <th>MAC</th>
                                <th>Status</th>
                                <th>Source</th>
                            </tr>
                            </thead>
                            <tbody id="arpBody">
                            <?php foreach ($arpEntries as $row): ?>
                                <tr>
                                    <td class="mono"><?= h($row['address']) ?></td>
                                    <td class="mono"><?= h($row['mac_address']) ?></td>
                                    <td><?= h($row['status'] !== '' ? $row['status'] : ($row['complete'] ? 'complete' : '—')) ?></td>
                                    <td><?= h(!empty($row['dhcp']) ? 'DHCP' : (!empty($row['dynamic']) ? 'Dynamic' : 'Static')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card">
                    <h2>DHCP Leases Mapped to Interface Hosts</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>IP</th>
                                <th>Host</th>
                                <th>MAC</th>
                                <th>Lease Left</th>
                                <th>Last Seen</th>
                            </tr>
                            </thead>
                            <tbody id="leasesBody">
                            <?php foreach ($dhcpLeases as $row): ?>
                                <tr>
                                    <td class="mono"><?= h($row['address']) ?></td>
                                    <td><?= h($row['host_name'] !== '' ? $row['host_name'] : '—') ?></td>
                                    <td class="mono"><?= h($row['mac_address']) ?></td>
                                    <td><?= h($row['expires_after_text']) ?></td>
                                    <td><?= h($row['last_seen_text']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="card">
                <h2>Related IPv4 Connections</h2>
                <div class="muted small" style="margin-bottom: 12px;">
                    This list is inferred by matching ARP-learned host IPs on this interface against the IPv4 connection-tracking table.
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Protocol</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Reply Path</th>
                            <th>Orig Rate</th>
                            <th>Reply Rate</th>
                            <th>TCP State</th>
                            <th>Timeout</th>
                        </tr>
                        </thead>
                        <tbody id="connectionsBody">
                        <?php foreach ($connections as $row): ?>
                            <tr>
                                <td><?= h($row['protocol']) ?></td>
                                <td class="mono"><?= h($row['orig_src_address'] . ($row['orig_src_port'] !== '' ? ':' . $row['orig_src_port'] : '')) ?></td>
                                <td class="mono"><?= h($row['orig_dst_address'] . ($row['orig_dst_port'] !== '' ? ':' . $row['orig_dst_port'] : '')) ?></td>
                                <td class="mono"><?= h($row['repl_src_address'] . ($row['repl_src_port'] !== '' ? ':' . $row['repl_src_port'] : '') . ' → ' . $row['repl_dst_address'] . ($row['repl_dst_port'] !== '' ? ':' . $row['repl_dst_port'] : '')) ?></td>
                                <td><?= h($row['orig_rate_text']) ?></td>
                                <td><?= h($row['repl_rate_text']) ?></td>
                                <td><?= h($row['tcp_state'] !== '' ? $row['tcp_state'] : '—') ?></td>
                                <td><?= h($row['timeout'] !== '' ? $row['timeout'] : '—') ?></td>
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
window.__initialPayload = <?= json_encode($data, JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
(function () {
    const refreshedAt = document.getElementById('refreshedAt');
    const sampleSeconds = document.getElementById('sampleSeconds');
    const pollStatus = document.getElementById('pollStatus');
    const trafficGraph = document.getElementById('trafficGraph');
    const graphScale = document.getElementById('graphScale');
    const ifaceStatus = document.getElementById('ifaceStatus');
    const rxRate = document.getElementById('rxRate');
    const txRate = document.getElementById('txRate');

    const metaType = document.getElementById('metaType');
    const metaMac = document.getElementById('metaMac');
    const metaMtu = document.getElementById('metaMtu');
    const metaRxBytes = document.getElementById('metaRxBytes');
    const metaTxBytes = document.getElementById('metaTxBytes');
    const metaQueueDrop = document.getElementById('metaQueueDrop');
    const metaFpRx = document.getElementById('metaFpRx');
    const metaFpTx = document.getElementById('metaFpTx');
    const metaLinkDowns = document.getElementById('metaLinkDowns');
    const metaLastUp = document.getElementById('metaLastUp');
    const metaLastDown = document.getElementById('metaLastDown');

    const addressesBody = document.getElementById('addressesBody');
    const bridgeHostsBody = document.getElementById('bridgeHostsBody');
    const arpBody = document.getElementById('arpBody');
    const leasesBody = document.getElementById('leasesBody');
    const connectionsBody = document.getElementById('connectionsBody');

    const GRAPH_CONFIG = {
        refreshSeconds: 3,
        displaySeconds: 60,
        width: 1200,
        height: 90,
        lineWidth: 2,
        downloadColor: '#22c55e',
        uploadColor: '#ef4444',
        baselineColor: 'rgba(148,163,184,0.18)',
        scaleHeadroom: 1.10
    };

    const pollIntervalMs = GRAPH_CONFIG.refreshSeconds * 1000;
    const maxHistoryPoints = Math.max(1, Math.floor(GRAPH_CONFIG.displaySeconds / GRAPH_CONFIG.refreshSeconds));
    const trafficHistory = [];
    let isPolling = false;

    if (pollStatus) {
        pollStatus.textContent = `Auto-update every ${GRAPH_CONFIG.refreshSeconds}s • Graph ${GRAPH_CONFIG.displaySeconds}s`;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatBitsPerSecond(value, precision = 2) {
        const units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
        let bps = Math.max(0, Number(value || 0));
        let pow = bps > 0 ? Math.floor(Math.log(bps) / Math.log(1000)) : 0;
        pow = Math.min(pow, units.length - 1);
        const scaled = bps / Math.pow(1000, pow);
        return scaled.toFixed(precision).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1') + ' ' + units[pow];
    }

    function pushTrafficPoint(iface) {
        if (!iface) return;
        trafficHistory.push({
            rx: Number(iface.rx_bps || 0),
            tx: Number(iface.tx_bps || 0)
        });
        if (trafficHistory.length > maxHistoryPoints) {
            trafficHistory.splice(0, trafficHistory.length - maxHistoryPoints);
        }
    }

    function drawGraph(canvas, history) {
        if (!canvas || !canvas.getContext) return 0;

        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;

        ctx.clearRect(0, 0, width, height);

        const leftPad = 4;
        const rightPad = 4;
        const topPad = 4;
        const bottomPad = 4;
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
            if (history.length < 2) return;

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

        drawLine('rx', GRAPH_CONFIG.downloadColor);
        drawLine('tx', GRAPH_CONFIG.uploadColor);

        return scaledMax;
    }

    function renderInterfaceMeta(iface) {
        if (!iface) return;

        if (ifaceStatus) {
            ifaceStatus.textContent = iface.status_text || '';
            ifaceStatus.className = 'status ' + (iface.status_class || '');
        }
        if (rxRate) rxRate.textContent = iface.rx_bps_text || '—';
        if (txRate) txRate.textContent = iface.tx_bps_text || '—';
        if (metaType) metaType.textContent = iface.type || '—';
        if (metaMac) metaMac.textContent = iface.mac_address || '—';
        if (metaMtu) metaMtu.textContent = (iface.mtu || '—') + ' / ' + (iface.l2mtu || '—');
        if (metaRxBytes) metaRxBytes.textContent = iface.rx_bytes_text || '—';
        if (metaTxBytes) metaTxBytes.textContent = iface.tx_bytes_text || '—';
        if (metaQueueDrop) metaQueueDrop.textContent = iface.tx_queue_drop_text || '—';
        if (metaFpRx) metaFpRx.textContent = iface.fp_rx_bytes_text || '—';
        if (metaFpTx) metaFpTx.textContent = iface.fp_tx_bytes_text || '—';
        if (metaLinkDowns) metaLinkDowns.textContent = iface.link_downs || '—';
        if (metaLastUp) metaLastUp.textContent = iface.last_link_up_time || '—';
        if (metaLastDown) metaLastDown.textContent = iface.last_link_down_time || '—';

        pushTrafficPoint(iface);
        const scale = drawGraph(trafficGraph, trafficHistory);
        if (graphScale) graphScale.textContent = scale > 0 ? formatBitsPerSecond(scale) : '—';
    }

    function renderAddresses(items) {
        addressesBody.innerHTML = (items || []).map(function (row) {
            return `
                <tr>
                    <td class="mono">${escapeHtml(row.address)}</td>
                    <td class="mono">${escapeHtml(row.network)}</td>
                    <td>${escapeHtml(row.actual_interface || row.interface || '—')}</td>
                    <td>${escapeHtml(row.dynamic ? 'Dynamic' : 'Static')}</td>
                </tr>`;
        }).join('');
    }

    function renderBridgeHosts(items) {
        bridgeHostsBody.innerHTML = (items || []).map(function (row) {
            return `
                <tr>
                    <td class="mono">${escapeHtml(row.mac_address)}</td>
                    <td>${escapeHtml(row.bridge)}</td>
                    <td>${escapeHtml(row.vid || '—')}</td>
                    <td>${escapeHtml(row.age || '—')}</td>
                    <td>${escapeHtml(row.hw_offload ? 'Yes' : 'No')}</td>
                </tr>`;
        }).join('');
    }

    function renderArp(items) {
        arpBody.innerHTML = (items || []).map(function (row) {
            const source = row.dhcp ? 'DHCP' : (row.dynamic ? 'Dynamic' : 'Static');
            return `
                <tr>
                    <td class="mono">${escapeHtml(row.address)}</td>
                    <td class="mono">${escapeHtml(row.mac_address)}</td>
                    <td>${escapeHtml(row.status || (row.complete ? 'complete' : '—'))}</td>
                    <td>${escapeHtml(source)}</td>
                </tr>`;
        }).join('');
    }

    function renderLeases(items) {
        leasesBody.innerHTML = (items || []).map(function (row) {
            return `
                <tr>
                    <td class="mono">${escapeHtml(row.address)}</td>
                    <td>${escapeHtml(row.host_name || '—')}</td>
                    <td class="mono">${escapeHtml(row.mac_address)}</td>
                    <td>${escapeHtml(row.expires_after_text || '—')}</td>
                    <td>${escapeHtml(row.last_seen_text || '—')}</td>
                </tr>`;
        }).join('');
    }

    function renderConnections(items) {
        connectionsBody.innerHTML = (items || []).map(function (row) {
            const from = `${row.orig_src_address || ''}${row.orig_src_port ? ':' + row.orig_src_port : ''}`;
            const to = `${row.orig_dst_address || ''}${row.orig_dst_port ? ':' + row.orig_dst_port : ''}`;
            const reply = `${row.repl_src_address || ''}${row.repl_src_port ? ':' + row.repl_src_port : ''} → ${row.repl_dst_address || ''}${row.repl_dst_port ? ':' + row.repl_dst_port : ''}`;
            return `
                <tr>
                    <td>${escapeHtml(row.protocol)}</td>
                    <td class="mono">${escapeHtml(from)}</td>
                    <td class="mono">${escapeHtml(to)}</td>
                    <td class="mono">${escapeHtml(reply)}</td>
                    <td>${escapeHtml(row.orig_rate_text || '—')}</td>
                    <td>${escapeHtml(row.repl_rate_text || '—')}</td>
                    <td>${escapeHtml(row.tcp_state || '—')}</td>
                    <td>${escapeHtml(row.timeout || '—')}</td>
                </tr>`;
        }).join('');
    }

    async function pollData() {
        if (isPolling) return;
        isPolling = true;
        if (pollStatus) pollStatus.textContent = 'Updating...';

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('ajax', '1');
            url.searchParams.set('t', Date.now());

            const response = await fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Failed to load updated data');
            }

            renderInterfaceMeta(data.interface || {});
            renderAddresses(data.addresses || []);
            renderBridgeHosts(data.bridgeHosts || []);
            renderArp(data.arpEntries || []);
            renderLeases(data.dhcpLeases || []);
            renderConnections(data.connections || []);

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

    const initial = window.__initialPayload || {};
    renderInterfaceMeta(initial.interface || {});
    renderAddresses(initial.addresses || []);
    renderBridgeHosts(initial.bridgeHosts || []);
    renderArp(initial.arpEntries || []);
    renderLeases(initial.dhcpLeases || []);
    renderConnections(initial.connections || []);

    setInterval(pollData, pollIntervalMs);
})();
</script>
</body>
</html>
