<?php
$routerHost = '192.168.0.1:8443';
$routerUser = 'apiuser';
$routerPass = 'MyPASSWORD';
$verifyTls = false;
$timeoutSec = 10;
$sampleDelayUs = 1000000;

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function b($v): bool { return in_array(strtolower((string)$v), ['true','yes','on'], true); }
function fmtB(float $n): string { $u=['B','KB','MB','GB','TB']; $i=0; while($n>=1024&&$i<count($u)-1){$n/=1024;$i++;} return round($n,2).' '.$u[$i]; }
function fmtR(float $n): string { $u=['bps','Kbps','Mbps','Gbps','Tbps']; $i=0; while($n>=1000&&$i<count($u)-1){$n/=1000;$i++;} return round($n,2).' '.$u[$i]; }

function ros(string $host,string $user,string $pass,bool $verify,int $timeout,string $path,string $method='GET',?array $payload=null): array {
    $ch=curl_init('https://'.$host.'/rest'.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_USERPWD=>$user.':'.$pass,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    if(!$verify){curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,false);}
    if($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_SLASHES));
    $response=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    if($response===false) throw new RuntimeException('cURL error: '.$err);
    $data=json_decode($response,true);
    if($code>=400) throw new RuntimeException('RouterOS HTTP '.$code.': '.(is_array($data)?json_encode($data,JSON_UNESCAPED_SLASHES):$response));
    if($data===null && trim($response)!=='null' && json_last_error()!==JSON_ERROR_NONE) throw new RuntimeException('Invalid JSON from RouterOS: '.json_last_error_msg());
    return is_array($data)?$data:[];
}

function ifaceRow($host,$user,$pass,$verify,$timeout,$name): array {
    $r=ros($host,$user,$pass,$verify,$timeout,'/interface/print','POST',['stats-detail'=>'','.query'=>['name='.$name],'.proplist'=>'.id,name,comment,type,disabled,running,mtu,l2mtu,mac-address,last-link-up-time,last-link-down-time,link-downs,rx-byte,tx-byte,rx-packet,tx-packet,tx-queue-drop,fp-rx-byte,fp-tx-byte']);
    return $r[0]??[];
}
function connRows($host,$user,$pass,$verify,$timeout): array {
    return ros($host,$user,$pass,$verify,$timeout,'/ip/firewall/connection/print','POST',['.proplist'=>'.id,protocol,orig-src-address,orig-dst-address,orig-src-port,orig-dst-port,repl-src-address,repl-dst-address,repl-src-port,repl-dst-port,orig-bytes,repl-bytes,tcp-state,timeout,fasttrack,assured,seen-reply']);
}
function connKey(array $r): string { return implode('|',[$r['protocol']??'',$r['orig-src-address']??'',$r['orig-src-port']??'',$r['orig-dst-address']??'',$r['orig-dst-port']??'',$r['repl-src-address']??'',$r['repl-src-port']??'',$r['repl-dst-address']??'',$r['repl-dst-port']??'']); }
function private4(string $ip): bool { return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false && filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false; }
function endpoint(array $r,string $prefix): string { $a=$r[$prefix.'-address']??''; $p=$r[$prefix.'-port']??''; return $a.($p!==''?':'.$p:''); }

function bridgeMacs($host,$user,$pass,$verify,$timeout,$iface): array {
    $rows=ros($host,$user,$pass,$verify,$timeout,'/interface/bridge/host/print','POST',['.proplist'=>'mac-address,on-interface,bridge,vid,age,hw-offload']); $out=[];
    foreach($rows as $r) if(($r['on-interface']??'')===$iface && !empty($r['mac-address'])) $out[strtoupper($r['mac-address'])]=$r;
    return $out;
}
function localIpsForInterface($host,$user,$pass,$verify,$timeout,$iface,array $macs): array {
    $ips=[];
    $arp=ros($host,$user,$pass,$verify,$timeout,'/ip/arp/print','POST',['.proplist'=>'address,mac-address,interface,status,dynamic,dhcp,complete']);
    foreach($arp as $r){$mac=strtoupper($r['mac-address']??''); if(($r['interface']??'')===$iface || ($mac!==''&&isset($macs[$mac]))) if(!empty($r['address'])) $ips[$r['address']]=true;}
    $leases=ros($host,$user,$pass,$verify,$timeout,'/ip/dhcp-server/lease/print','POST',['.proplist'=>'address,active-address,mac-address,active-mac-address,host-name,comment,status']);
    foreach($leases as $r){$mac=strtoupper($r['active-mac-address']??$r['mac-address']??''); if($mac!==''&&isset($macs[$mac])){$ip=$r['active-address']??$r['address']??'';if($ip!=='')$ips[$ip]=true;}}
    return $ips;
}
function isWanLike(string $name,array $iface,array $localIps): bool {
    $n=strtolower($name.' '.($iface['comment']??''));
    return str_contains($n,'wan')||str_contains($n,'internet')||str_contains($n,'fiber')||str_contains($n,'fibre')||(!$localIps && in_array($iface['type']??'',['vlan','pppoe-out'],true));
}
function buildConnections(array $a,array $z,float $sec,array $localIps,bool $wan): array {
    $first=[]; foreach($a as $r)$first[connKey($r)]=$r; $out=[];
    foreach($z as $r){$k=connKey($r);$old=$first[$k]??null;if(!$old)continue;
        $os=$r['orig-src-address']??'';$od=$r['orig-dst-address']??'';$rs=$r['repl-src-address']??'';$rd=$r['repl-dst-address']??'';
        $related=$wan ? (private4($os)||private4($od)||private4($rs)||private4($rd)) : (isset($localIps[$os])||isset($localIps[$od])||isset($localIps[$rs])||isset($localIps[$rd]));
        if(!$related)continue;
        $orig=max(0,(float)($r['orig-bytes']??0)-(float)($old['orig-bytes']??0))*8/$sec;
        $repl=max(0,(float)($r['repl-bytes']??0)-(float)($old['repl-bytes']??0))*8/$sec;
        $local=$os;$remote=$od;$up=$orig;$down=$repl;
        if(!private4($os)&&private4($od)){$local=$od;$remote=$os;$up=$repl;$down=$orig;}
        $out[]=['protocol'=>$r['protocol']??'','local'=>$local,'remote'=>$remote,'from'=>endpoint($r,'orig-src'),'to'=>endpoint($r,'orig-dst'),'download_bps'=>$down,'upload_bps'=>$up,'download_text'=>fmtR($down),'upload_text'=>fmtR($up),'tcp_state'=>$r['tcp-state']??'','timeout'=>$r['timeout']??''];
    }
    usort($out,fn($x,$y)=>max($y['download_bps'],$y['upload_bps'])<=>max($x['download_bps'],$x['upload_bps'])); return array_slice($out,0,200);
}
function payload($host,$user,$pass,$verify,$timeout,$delay,$name): array {
    $i1=ifaceRow($host,$user,$pass,$verify,$timeout,$name); if(!$i1)throw new RuntimeException('Interface not found: '.$name);
    $macs=bridgeMacs($host,$user,$pass,$verify,$timeout,$name); $ips=localIpsForInterface($host,$user,$pass,$verify,$timeout,$name,$macs); $wan=isWanLike($name,$i1,$ips);
    $c1=connRows($host,$user,$pass,$verify,$timeout); $t=microtime(true); usleep($delay); $i2=ifaceRow($host,$user,$pass,$verify,$timeout,$name); $c2=connRows($host,$user,$pass,$verify,$timeout); $sec=max(microtime(true)-$t,.001);
    $rx=max(0,(float)($i2['rx-byte']??0)-(float)($i1['rx-byte']??0))*8/$sec; $tx=max(0,(float)($i2['tx-byte']??0)-(float)($i1['tx-byte']??0))*8/$sec;
    return ['ok'=>true,'refreshedAt'=>date('Y-m-d H:i:s'),'sampleSeconds'=>round($sec,2),'mode'=>$wan?'WAN / Internet':'LAN / interface hosts','interface'=>['name'=>$name,'comment'=>$i2['comment']??'','type'=>$i2['type']??'','running'=>b($i2['running']??false),'disabled'=>b($i2['disabled']??false),'mac'=>$i2['mac-address']??'—','mtu'=>$i2['mtu']??'—','l2mtu'=>$i2['l2mtu']??'—','rx_bps'=>$rx,'tx_bps'=>$tx,'rx_text'=>fmtR($rx),'tx_text'=>fmtR($tx),'rx_total'=>fmtB((float)($i2['rx-byte']??0)),'tx_total'=>fmtB((float)($i2['tx-byte']??0))],'connections'=>buildConnections($c1,$c2,$sec,$ips,$wan),'localIps'=>array_keys($ips)];
}

$name=trim((string)($_GET['iface']??'')); $ajax=($_GET['ajax']??'')==='1'; $data=null;$error=null;
try{if($name==='')throw new RuntimeException('Missing interface name.');$data=payload($routerHost,$routerUser,$routerPass,$verifyTls,$timeoutSec,$sampleDelayUs,$name);}catch(Throwable $e){$error=$e->getMessage();}
if($ajax){header('Content-Type: application/json; charset=utf-8',true,$error?500:200);echo json_encode($error?['ok'=>false,'error'=>$error]:$data,JSON_UNESCAPED_SLASHES);exit;}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MikroTik Interface</title>
<style>:root{--bg:#0f172a;--p:#111827;--p2:#1f2937;--t:#e5e7eb;--m:#94a3b8;--l:#334155;--g:#22c55e;--r:#ef4444;--a:#38bdf8}*{box-sizing:border-box}body{margin:0;font:14px Arial;background:var(--bg);color:var(--t)}.wrap{width:min(1600px,calc(100% - 32px));margin:24px auto}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}.card{background:linear-gradient(180deg,var(--p),var(--p2));border:1px solid var(--l);border-radius:14px;padding:18px;margin-top:20px}.muted{color:var(--m)}.stats{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}.stat{border:1px solid #33415580;border-radius:10px;padding:12px}.label{color:var(--m);font-size:12px}.value{font-size:18px;font-weight:700;margin-top:5px}.mono{font-family:Consolas,monospace}.graph{width:100%;height:90px;background:#0f172a88;border:1px solid #33415580;border-radius:8px;margin-top:15px}table{width:100%;border-collapse:collapse;table-layout:fixed;min-width:1000px}th,td{padding:9px 10px;border-bottom:1px solid #33415580;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.table{overflow:auto}.error{background:#7f1d1d55;padding:15px;border-radius:10px}.up{color:#86efac}.down{color:#fca5a5}@media(max-width:1000px){.stats{grid-template-columns:1fr 1fr}}</style></head><body><div class="wrap"><div class="top"><div><h1>Interface Detail: <?=h($name)?></h1><div class="muted">Router <?=h($routerHost)?> · <span id="when"><?=h($data['refreshedAt']??'')?></span></div></div><a href="index.php" style="color:var(--a)">Back to dashboard</a></div>
<?php if($error):?><div class="card error"><?=h($error)?></div><?php else:?><div class="card"><h2>Live Interface Traffic</h2><div class="stats"><div class="stat"><div class="label">Status</div><div class="value" id="status"></div></div><div class="stat"><div class="label">Mode</div><div class="value" id="mode"></div></div><div class="stat"><div class="label">Download</div><div class="value" id="rx"></div></div><div class="stat"><div class="label">Upload</div><div class="value" id="tx"></div></div><div class="stat"><div class="label">Total RX</div><div class="value" id="rxt"></div></div><div class="stat"><div class="label">Total TX</div><div class="value" id="txt"></div></div></div><canvas id="graph" class="graph" width="1200" height="90"></canvas></div>
<div class="card"><h2>Interface Details</h2><div id="details" class="mono"></div></div>
<div class="card"><h2>Connections <span id="count" class="muted"></span></h2><div class="muted" id="note" style="margin-bottom:12px"></div><div class="table"><table><thead><tr><th>Protocol</th><th>Local</th><th>Remote</th><th>Original From</th><th>Original To</th><th>Download</th><th>Upload</th><th>TCP State</th><th>Timeout</th></tr></thead><tbody id="conns"></tbody></table></div></div><?php endif;?></div>
<script>const initial=<?=json_encode($data,JSON_UNESCAPED_SLASHES)?>,hist=[],maxPts=20;function e(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}function rate(v){let u=['bps','Kbps','Mbps','Gbps'];v=Math.max(0,+v||0);let i=0;while(v>=1000&&i<u.length-1){v/=1000;i++}return v.toFixed(2).replace(/\.00$/,'')+' '+u[i]}function draw(){let c=document.getElementById('graph');if(!c)return;let x=c.getContext('2d'),w=c.width,h=c.height;x.clearRect(0,0,w,h);let m=1;hist.forEach(p=>m=Math.max(m,p.rx,p.tx));m*=1.1;[['rx','#22c55e'],['tx','#ef4444']].forEach(([k,col])=>{x.beginPath();x.strokeStyle=col;x.lineWidth=2;hist.forEach((p,i)=>{let xx=4+(maxPts-hist.length+i)*(w-8)/(maxPts-1),yy=4+(h-8)*(1-p[k]/m);i?x.lineTo(xx,yy):x.moveTo(xx,yy)});x.stroke()})}function render(d){if(!d)return;let i=d.interface;document.getElementById('when').textContent=d.refreshedAt;document.getElementById('status').textContent=i.disabled?'Disabled':(i.running?'Up':'Down');document.getElementById('mode').textContent=d.mode;document.getElementById('rx').textContent=i.rx_text;document.getElementById('tx').textContent=i.tx_text;document.getElementById('rxt').textContent=i.rx_total;document.getElementById('txt').textContent=i.tx_total;document.getElementById('details').textContent=`Type: ${i.type} · MAC: ${i.mac} · MTU/L2MTU: ${i.mtu}/${i.l2mtu} · Local IPs: ${(d.localIps||[]).join(', ')||'—'}`;let a=d.connections||[];document.getElementById('count').textContent='('+a.length+')';document.getElementById('note').textContent=d.mode.startsWith('WAN')?'WAN view shows tracked Internet/NAT connections; RouterOS conntrack does not expose an authoritative physical-interface field for each connection.':'LAN view is correlated to IPs learned behind this interface.';document.getElementById('conns').innerHTML=a.map(r=>`<tr><td>${e(r.protocol)}</td><td class="mono">${e(r.local)}</td><td class="mono">${e(r.remote)}</td><td class="mono">${e(r.from)}</td><td class="mono">${e(r.to)}</td><td>${e(r.download_text)}</td><td>${e(r.upload_text)}</td><td>${e(r.tcp_state||'—')}</td><td>${e(r.timeout||'—')}</td></tr>`).join('');hist.push({rx:+i.rx_bps||0,tx:+i.tx_bps||0});if(hist.length>maxPts)hist.shift();draw()}async function poll(){try{let u=new URL(location.href);u.searchParams.set('ajax','1');u.searchParams.set('t',Date.now());let r=await fetch(u,{cache:'no-store'}),d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'Update failed');render(d)}catch(e){console.error(e)}}render(initial);setInterval(poll,3000)</script></body></html>
