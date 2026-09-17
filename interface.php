<?php
session_start();

// =========================
// Configuration
// =========================
$routerHost = '192.168.0.1:8443';
$verifyTls = false;
$timeoutSec = 10;
$sampleDelayUs = 1000000;

$routerUser = (string)($_SESSION['router_user'] ?? '');
$routerPass = (string)($_SESSION['router_pass'] ?? '');

if ($routerUser === '' || $routerPass === '') {
    header('Location: index.php');
    exit;
}

// =========================
// Helpers
// =========================
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeBoolString($value): bool
{
    return in_array(strtolower((string)$value), ['true', 'yes', 'on'], true);
}

function formatBytes(float $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max(0, $bytes);
    $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}

function formatBitsPerSecond(float $bps, int $precision = 2): string
{
    $units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
    $bps = max(0, $bps);
    $pow = $bps > 0 ? floor(log($bps, 1000)) : 0;
    $pow = min($pow, count($units) - 1);
    return round($bps / (1000 ** $pow), $precision) . ' ' . $units[$pow];
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
    $ch = curl_init('https://' . $routerHost . '/rest' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_USERPWD => $routerUser . ':' . $routerPass,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    if (!$verifyTls) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        throw new RuntimeException('cURL error: ' . $curlError);
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 400) {
        $detail = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES) : $response;
        throw new RuntimeException('RouterOS HTTP ' . $httpCode . ': ' . $detail);
    }

    if ($decoded === null && trim($response) !== 'null' && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON from RouterOS: ' . json_last_error_msg());
    }

    return is_array($decoded) ? $decoded : [];
}

function fetchInterfaceCounters($host, $user, $pass, $verify, $timeout, $name): array
{
    $rows = routerosRequest($host, $user, $pass, $verify, $timeout, '/interface/print', 'POST', [
        'stats-detail' => '',
        '.query' => ['name=' . $name],
        '.proplist' => '.id,name,comment,type,disabled,running,mtu,l2mtu,mac-address,last-link-up-time,last-link-down-time,link-downs,rx-byte,tx-byte,rx-packet,tx-packet,tx-queue-drop,fp-rx-byte,fp-tx-byte',
    ]);
    return $rows[0] ?? [];
}

function fetchBridgeHosts($host, $user, $pass, $verify, $timeout, $name): array
{
    $rows = routerosRequest($host, $user, $pass, $verify, $timeout, '/interface/bridge/host/print', 'POST', [
        '.proplist' => 'mac-address,on-interface,bridge,vid,age,hw-offload',
    ]);

    $result = [];
    foreach ($rows as $row) {
        if (($row['on-interface'] ?? '') === $name && !empty($row['mac-address'])) {
            $result[strtoupper($row['mac-address'])] = $row;
        }
    }
    return $result;
}

function fetchArp($host, $user, $pass, $verify, $timeout): array
{
    return routerosRequest($host, $user, $pass, $verify, $timeout, '/ip/arp/print', 'POST', [
        '.proplist' => 'address,mac-address,interface,status,dynamic,dhcp,complete',
    ]);
}

function fetchLeases($host, $user, $pass, $verify, $timeout): array
{
    return routerosRequest($host, $user, $pass, $verify, $timeout, '/ip/dhcp-server/lease/print', 'POST', [
        '.proplist' => 'address,active-address,mac-address,active-mac-address,host-name,comment,status,last-seen,expires-after',
    ]);
}

function fetchConnections($host, $user, $pass, $verify, $timeout): array
{
    return routerosRequest($host, $user, $pass, $verify, $timeout, '/ip/firewall/connection/print', 'POST', [
        '.proplist' => '.id,protocol,orig-src-address,orig-dst-address,orig-src-port,orig-dst-port,repl-src-address,repl-dst-address,repl-src-port,repl-dst-port,orig-bytes,repl-bytes,tcp-state,timeout,fasttrack,assured,seen-reply',
    ]);
}

function connectionKey(array $row): string
{
    return implode('|', [
        $row['protocol'] ?? '',
        $row['orig-src-address'] ?? '',
        $row['orig-src-port'] ?? '',
        $row['orig-dst-address'] ?? '',
        $row['orig-dst-port'] ?? '',
        $row['repl-src-address'] ?? '',
        $row['repl-src-port'] ?? '',
        $row['repl-dst-address'] ?? '',
        $row['repl-dst-port'] ?? '',
    ]);
}

function isPrivateIpv4(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function buildKnownHosts(array $bridgeHosts, array $arpRows, array $leases, string $interfaceName): array
{
    $hosts = [];

    foreach ($arpRows as $row) {
        $ip = trim((string)($row['address'] ?? ''));
        $mac = strtoupper(trim((string)($row['mac-address'] ?? '')));
        $direct = ($row['interface'] ?? '') === $interfaceName;
        $behindPort = $mac !== '' && isset($bridgeHosts[$mac]);

        if ($ip === '' || (!$direct && !$behindPort)) {
            continue;
        }

        $hosts[$ip] = [
            'ip' => $ip,
            'mac' => $mac,
            'hostname' => '',
            'comment' => '',
            'arp_status' => $row['status'] ?? '',
            'download_bps' => 0,
            'upload_bps' => 0,
            'connections' => 0,
            'remote_count' => 0,
            'remotes' => [],
        ];
    }

    foreach ($leases as $row) {
        $mac = strtoupper(trim((string)($row['active-mac-address'] ?? $row['mac-address'] ?? '')));
        $ip = trim((string)($row['active-address'] ?? $row['address'] ?? ''));
        if ($ip === '' || $mac === '' || !isset($bridgeHosts[$mac])) {
            continue;
        }

        if (!isset($hosts[$ip])) {
            $hosts[$ip] = [
                'ip' => $ip,
                'mac' => $mac,
                'hostname' => '',
                'comment' => '',
                'arp_status' => '',
                'download_bps' => 0,
                'upload_bps' => 0,
                'connections' => 0,
                'remote_count' => 0,
                'remotes' => [],
            ];
        }

        $hosts[$ip]['hostname'] = (string)($row['host-name'] ?? '');
        $hosts[$ip]['comment'] = (string)($row['comment'] ?? '');
    }

    return $hosts;
}

function buildLiveTraffic(array $firstRows, array $secondRows, float $seconds, array $knownHosts, bool $wanLike): array
{
    $first = [];
    foreach ($firstRows as $row) {
        $first[connectionKey($row)] = $row;
    }

    $hosts = $knownHosts;
    $connections = [];

    foreach ($secondRows as $row) {
        $key = connectionKey($row);
        if (!isset($first[$key])) {
            continue;
        }

        $old = $first[$key];
        $origBps = max(0, (float)($row['orig-bytes'] ?? 0) - (float)($old['orig-bytes'] ?? 0)) * 8 / $seconds;
        $replBps = max(0, (float)($row['repl-bytes'] ?? 0) - (float)($old['repl-bytes'] ?? 0)) * 8 / $seconds;

        $origSrc = (string)($row['orig-src-address'] ?? '');
        $origDst = (string)($row['orig-dst-address'] ?? '');
        $replSrc = (string)($row['repl-src-address'] ?? '');
        $replDst = (string)($row['repl-dst-address'] ?? '');

        $localIp = '';
        $remoteIp = '';
        $upload = 0;
        $download = 0;

        foreach ([$origSrc, $origDst, $replSrc, $replDst] as $candidate) {
            if (isset($hosts[$candidate])) {
                $localIp = $candidate;
                break;
            }
        }

        if ($localIp === '' && $wanLike) {
            if (isPrivateIpv4($origSrc)) {
                $localIp = $origSrc;
            } elseif (isPrivateIpv4($origDst)) {
                $localIp = $origDst;
            }

            if ($localIp !== '' && !isset($hosts[$localIp])) {
                $hosts[$localIp] = [
                    'ip' => $localIp,
                    'mac' => '',
                    'hostname' => '',
                    'comment' => '',
                    'arp_status' => '',
                    'download_bps' => 0,
                    'upload_bps' => 0,
                    'connections' => 0,
                    'remote_count' => 0,
                    'remotes' => [],
                ];
            }
        }

        if ($localIp === '') {
            continue;
        }

        if ($localIp === $origSrc) {
            $remoteIp = $origDst;
            $upload = $origBps;
            $download = $replBps;
        } elseif ($localIp === $origDst) {
            $remoteIp = $origSrc;
            $upload = $replBps;
            $download = $origBps;
        } elseif ($localIp === $replDst) {
            $remoteIp = $replSrc;
            $upload = $origBps;
            $download = $replBps;
        } else {
            $remoteIp = $replDst;
            $upload = $replBps;
            $download = $origBps;
        }

        $hosts[$localIp]['upload_bps'] += $upload;
        $hosts[$localIp]['download_bps'] += $download;
        $hosts[$localIp]['connections']++;
        if ($remoteIp !== '') {
            $hosts[$localIp]['remotes'][$remoteIp] = true;
        }

        $connections[] = [
            'protocol' => $row['protocol'] ?? '',
            'local_ip' => $localIp,
            'remote_ip' => $remoteIp,
            'from' => $origSrc . (!empty($row['orig-src-port']) ? ':' . $row['orig-src-port'] : ''),
            'to' => $origDst . (!empty($row['orig-dst-port']) ? ':' . $row['orig-dst-port'] : ''),
            'download_bps' => $download,
            'upload_bps' => $upload,
            'download_text' => formatBitsPerSecond($download),
            'upload_text' => formatBitsPerSecond($upload),
            'tcp_state' => $row['tcp-state'] ?? '',
            'timeout' => $row['timeout'] ?? '',
        ];
    }

    foreach ($hosts as &$host) {
        $host['remote_count'] = count($host['remotes']);
        $host['remotes'] = array_slice(array_keys($host['remotes']), 0, 8);
        $host['download_text'] = formatBitsPerSecond($host['download_bps']);
        $host['upload_text'] = formatBitsPerSecond($host['upload_bps']);
        $host['total_bps'] = $host['download_bps'] + $host['upload_bps'];
    }
    unset($host);

    usort($hosts, fn($a, $b) => $b['total_bps'] <=> $a['total_bps']);
    usort($connections, fn($a, $b) => ($b['download_bps'] + $b['upload_bps']) <=> ($a['download_bps'] + $a['upload_bps']));

    return [
        'hosts' => array_values($hosts),
        'connections' => array_slice($connections, 0, 250),
    ];
}

function buildPayload($host, $user, $pass, $verify, $timeout, $delay, $name): array
{
    $interface1 = fetchInterfaceCounters($host, $user, $pass, $verify, $timeout, $name);
    if (!$interface1) {
        throw new RuntimeException('Interface not found: ' . $name);
    }

    $bridgeHosts = fetchBridgeHosts($host, $user, $pass, $verify, $timeout, $name);
    $arpRows = fetchArp($host, $user, $pass, $verify, $timeout);
    $leases = fetchLeases($host, $user, $pass, $verify, $timeout);
    $knownHosts = buildKnownHosts($bridgeHosts, $arpRows, $leases, $name);

    $nameHint = strtolower($name . ' ' . ($interface1['comment'] ?? ''));
    $wanLike = str_contains($nameHint, 'wan') || str_contains($nameHint, 'internet') || str_contains($nameHint, 'fiber') || str_contains($nameHint, 'fibre');

    $connections1 = fetchConnections($host, $user, $pass, $verify, $timeout);
    $started = microtime(true);
    usleep($delay);
    $interface2 = fetchInterfaceCounters($host, $user, $pass, $verify, $timeout, $name);
    $connections2 = fetchConnections($host, $user, $pass, $verify, $timeout);
    $seconds = max(microtime(true) - $started, 0.001);

    $rxBps = max(0, (float)($interface2['rx-byte'] ?? 0) - (float)($interface1['rx-byte'] ?? 0)) * 8 / $seconds;
    $txBps = max(0, (float)($interface2['tx-byte'] ?? 0) - (float)($interface1['tx-byte'] ?? 0)) * 8 / $seconds;

    $live = buildLiveTraffic($connections1, $connections2, $seconds, $knownHosts, $wanLike);

    return [
        'ok' => true,
        'refreshedAt' => date('Y-m-d H:i:s'),
        'sampleSeconds' => round($seconds, 2),
        'mode' => $wanLike ? 'WAN / Internet' : 'LAN / interface hosts',
        'interface' => [
            'name' => $name,
            'comment' => $interface2['comment'] ?? '',
            'type' => $interface2['type'] ?? '',
            'running' => normalizeBoolString($interface2['running'] ?? false),
            'disabled' => normalizeBoolString($interface2['disabled'] ?? false),
            'mac' => $interface2['mac-address'] ?? '—',
            'mtu' => $interface2['mtu'] ?? '—',
            'l2mtu' => $interface2['l2mtu'] ?? '—',
            'rx_bps' => $rxBps,
            'tx_bps' => $txBps,
            'rx_text' => formatBitsPerSecond($rxBps),
            'tx_text' => formatBitsPerSecond($txBps),
            'rx_total' => formatBytes((float)($interface2['rx-byte'] ?? 0)),
            'tx_total' => formatBytes((float)($interface2['tx-byte'] ?? 0)),
            'rx_packets' => (float)($interface2['rx-packet'] ?? 0),
            'tx_packets' => (float)($interface2['tx-packet'] ?? 0),
            'queue_drops' => (float)($interface2['tx-queue-drop'] ?? 0),
            'link_downs' => $interface2['link-downs'] ?? '0',
            'last_up' => $interface2['last-link-up-time'] ?? '—',
            'last_down' => $interface2['last-link-down-time'] ?? '—',
        ],
        'hosts' => $live['hosts'],
        'connections' => $live['connections'],
    ];
}

$interfaceName = trim((string)($_GET['iface'] ?? ''));
$isAjax = ($_GET['ajax'] ?? '') === '1';
$data = null;
$error = null;

try {
    if ($interfaceName === '') {
        throw new RuntimeException('Missing interface name.');
    }
    $data = buildPayload($routerHost, $routerUser, $routerPass, $verifyTls, $timeoutSec, $sampleDelayUs, $interfaceName);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8', true, $error ? 500 : 200);
    echo json_encode($error ? ['ok' => false, 'error' => $error] : $data, JSON_UNESCAPED_SLASHES);
    exit;
}
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
            --panel2: #1f2937;
            --text: #e5e7eb;
            --muted: #94a3b8;
            --line: #334155;
            --green: #22c55e;
            --red: #ef4444;
            --accent: #38bdf8;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px Arial, sans-serif; background: var(--bg); color: var(--text); }
        .wrap { width: min(1700px, calc(100% - 32px)); margin: 24px auto; }
        .topbar { display: flex; justify-content: space-between; gap: 16px; align-items: center; flex-wrap: wrap; }
        .card { background: linear-gradient(180deg, var(--panel), var(--panel2)); border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-top: 20px; }
        h1, h2 { margin: 0; }
        h2 { font-size: 18px; margin-bottom: 14px; }
        .muted { color: var(--muted); }
        .small { font-size: 12px; }
        .mono { font-family: Consolas, monospace; }
        .stats { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 12px; }
        .stat { border: 1px solid #33415580; border-radius: 10px; padding: 12px; }
        .label { color: var(--muted); font-size: 12px; }
        .value { font-size: 18px; font-weight: 700; margin-top: 5px; }
        .graph { width: 100%; height: 180px; background: #0f172a88; border: 1px solid #33415580; border-radius: 8px; margin-top: 15px; }
        .legend { display: flex; gap: 20px; margin-top: 8px; font-size: 12px; }
        .rx { color: #86efac; }
        .tx { color: #fca5a5; }
        .table-wrap { overflow: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1050px; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #33415580; text-align: left; white-space: nowrap; }
        th { color: #cbd5e1; background: #ffffff08; position: sticky; top: 0; }
        tbody tr:hover { background: #ffffff08; }
        .bar { height: 5px; background: #0f172a; border-radius: 3px; margin-top: 5px; overflow: hidden; }
        .bar > span { display: block; height: 100%; background: var(--accent); }
        .error { background: #7f1d1d55; padding: 15px; border-radius: 10px; }
        a { color: var(--accent); }
        @media(max-width: 1100px) { .stats { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1>Interface Detail: <?= h($interfaceName) ?></h1>
            <div class="muted small">Router <?= h($routerHost) ?> · <span id="when"><?= h($data['refreshedAt'] ?? '') ?></span> · <span id="mode"><?= h($data['mode'] ?? '') ?></span></div>
        </div>
        <div><span id="pollStatus" class="muted small">Live update every 3 seconds · 60 second graph</span> &nbsp; <a href="index.php">Back to dashboard</a></div>
    </div>

    <?php if ($error): ?>
        <div class="card error"><?= h($error) ?></div>
    <?php else: ?>
        <section class="card">
            <h2>Live Interface Traffic</h2>
            <div class="stats">
                <div class="stat"><div class="label">Status</div><div class="value" id="status">—</div></div>
                <div class="stat"><div class="label">Download / RX</div><div class="value rx" id="rx">—</div></div>
                <div class="stat"><div class="label">Upload / TX</div><div class="value tx" id="tx">—</div></div>
                <div class="stat"><div class="label">Known IPs</div><div class="value" id="hostCount">0</div></div>
                <div class="stat"><div class="label">Active Connections</div><div class="value" id="connectionCount">0</div></div>
                <div class="stat"><div class="label">Total RX</div><div class="value" id="rxTotal">—</div></div>
                <div class="stat"><div class="label">Total TX</div><div class="value" id="txTotal">—</div></div>
            </div>
            <canvas id="trafficGraph" class="graph" width="1600" height="180"></canvas>
            <div class="legend"><span class="rx">● Download / RX</span><span class="tx">● Upload / TX</span><span class="muted">Rolling 60 seconds</span></div>
        </section>

        <section class="card">
            <h2>Live Traffic by IP</h2>
            <div class="muted small" style="margin-bottom:12px">Known hosts are correlated from bridge, ARP and DHCP data. Live rates come from RouterOS connection byte-counter deltas and are sorted by current traffic.</div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>IP Address</th><th>Host / Comment</th><th>MAC</th><th>Download</th><th>Upload</th><th>Connections</th><th>Remote IPs</th><th>Current Remotes</th></tr></thead>
                    <tbody id="hostsBody"></tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Interface Information</h2>
            <div class="stats">
                <div class="stat"><div class="label">Type</div><div class="value" id="type">—</div></div>
                <div class="stat"><div class="label">MAC</div><div class="value mono" id="mac">—</div></div>
                <div class="stat"><div class="label">MTU / L2MTU</div><div class="value" id="mtu">—</div></div>
                <div class="stat"><div class="label">RX Packets</div><div class="value" id="rxPackets">—</div></div>
                <div class="stat"><div class="label">TX Packets</div><div class="value" id="txPackets">—</div></div>
                <div class="stat"><div class="label">Queue Drops</div><div class="value" id="queueDrops">—</div></div>
                <div class="stat"><div class="label">Link Downs</div><div class="value" id="linkDowns">—</div></div>
            </div>
        </section>

        <section class="card">
            <h2>Live Connections</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Protocol</th><th>Local IP</th><th>Remote IP</th><th>Original From</th><th>Original To</th><th>Download</th><th>Upload</th><th>State</th><th>Timeout</th></tr></thead>
                    <tbody id="connectionsBody"></tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<script>
const initial = <?= json_encode($data, JSON_UNESCAPED_SLASHES) ?>;
const history = [];
const maxPoints = 20;
let polling = false;

function esc(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function fmtNumber(value) {
    return Number(value || 0).toLocaleString();
}

function drawGraph() {
    const canvas = document.getElementById('trafficGraph');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    let max = 1;
    history.forEach(p => max = Math.max(max, p.rx, p.tx));
    max *= 1.1;

    ctx.strokeStyle = 'rgba(148,163,184,.18)';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = 4 + (h - 8) * i / 4;
        ctx.beginPath(); ctx.moveTo(4, y); ctx.lineTo(w - 4, y); ctx.stroke();
    }

    [['rx', '#22c55e'], ['tx', '#ef4444']].forEach(([key, color]) => {
        if (history.length < 2) return;
        ctx.beginPath();
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        history.forEach((point, i) => {
            const slot = maxPoints - history.length + i;
            const x = 4 + slot * (w - 8) / (maxPoints - 1);
            const y = 4 + (h - 8) * (1 - point[key] / max);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.stroke();
    });
}

function render(data) {
    if (!data || !data.interface) return;
    const iface = data.interface;
    document.getElementById('when').textContent = data.refreshedAt || '';
    document.getElementById('mode').textContent = data.mode || '';
    document.getElementById('status').textContent = iface.disabled ? 'Disabled' : (iface.running ? 'Up' : 'Down');
    document.getElementById('rx').textContent = iface.rx_text || '—';
    document.getElementById('tx').textContent = iface.tx_text || '—';
    document.getElementById('rxTotal').textContent = iface.rx_total || '—';
    document.getElementById('txTotal').textContent = iface.tx_total || '—';
    document.getElementById('type').textContent = iface.type || '—';
    document.getElementById('mac').textContent = iface.mac || '—';
    document.getElementById('mtu').textContent = `${iface.mtu || '—'} / ${iface.l2mtu || '—'}`;
    document.getElementById('rxPackets').textContent = fmtNumber(iface.rx_packets);
    document.getElementById('txPackets').textContent = fmtNumber(iface.tx_packets);
    document.getElementById('queueDrops').textContent = fmtNumber(iface.queue_drops);
    document.getElementById('linkDowns').textContent = iface.link_downs || '0';

    const hosts = data.hosts || [];
    const connections = data.connections || [];
    document.getElementById('hostCount').textContent = hosts.length;
    document.getElementById('connectionCount').textContent = connections.length;

    const maxHostRate = Math.max(1, ...hosts.map(h => Number(h.total_bps || 0)));
    document.getElementById('hostsBody').innerHTML = hosts.map(host => {
        const label = host.hostname || host.comment || '—';
        const width = Math.min(100, Number(host.total_bps || 0) * 100 / maxHostRate);
        return `<tr>
            <td class="mono">${esc(host.ip)}</td>
            <td>${esc(label)}</td>
            <td class="mono">${esc(host.mac || '—')}</td>
            <td class="rx">${esc(host.download_text)}<div class="bar"><span style="width:${width}%"></span></div></td>
            <td class="tx">${esc(host.upload_text)}</td>
            <td>${esc(host.connections)}</td>
            <td>${esc(host.remote_count)}</td>
            <td class="mono" title="${esc((host.remotes || []).join(', '))}">${esc((host.remotes || []).join(', ') || '—')}</td>
        </tr>`;
    }).join('');

    document.getElementById('connectionsBody').innerHTML = connections.map(row => `<tr>
        <td>${esc(row.protocol)}</td>
        <td class="mono">${esc(row.local_ip)}</td>
        <td class="mono">${esc(row.remote_ip)}</td>
        <td class="mono">${esc(row.from)}</td>
        <td class="mono">${esc(row.to)}</td>
        <td class="rx">${esc(row.download_text)}</td>
        <td class="tx">${esc(row.upload_text)}</td>
        <td>${esc(row.tcp_state || '—')}</td>
        <td>${esc(row.timeout || '—')}</td>
    </tr>`).join('');

    history.push({rx: Number(iface.rx_bps || 0), tx: Number(iface.tx_bps || 0)});
    if (history.length > maxPoints) history.shift();
    drawGraph();
}

async function poll() {
    if (polling) return;
    polling = true;
    const status = document.getElementById('pollStatus');
    if (status) status.textContent = 'Updating…';

    try {
        const url = new URL(window.location.href);
        url.searchParams.set('ajax', '1');
        url.searchParams.set('t', Date.now());
        const response = await fetch(url, {cache: 'no-store', headers: {'X-Requested-With': 'XMLHttpRequest'}});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Update failed');
        render(data);
        if (status) status.textContent = 'Live update every 3 seconds · 60 second graph';
    } catch (error) {
        if (status) status.textContent = 'Update failed: ' + error.message;
        console.error(error);
    } finally {
        polling = false;
    }
}

if (initial) render(initial);
setInterval(poll, 3000);
</script>
</body>
</html>
