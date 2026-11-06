<?php
// AnnanGol analytics dashboard: /stats/
define('AG_ANALYTICS', 1);
require __DIR__ . '/../api/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
session_name('ag_stats');
session_set_cookie_params(['lifetime' => 0, 'path' => '/stats/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$err = '';
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
    usleep(400000); // slow down guessing
    if (password_verify((string)$_POST['pw'], ag_config()['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['ok'] = 1;
        // Exclude this browser from the visitor counts.
        setcookie('ag_notrack', '1', ['expires' => time() + 86400 * 400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
        header('Location: ./'); exit;
    }
    $err = '비밀번호가 맞지 않습니다.';
}
$authed = !empty($_SESSION['ok']);

if ($authed && (isset($_GET['api']) || isset($_GET['csv']))) {
    $days = max(1, min(366, (int)($_GET['days'] ?? 30)));
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $db = ag_db();
    $q = function (string $sql, array $p = []) use ($db) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };

    $byPageDay = $q('SELECT day, path, COUNT(DISTINCT vid) v, COUNT(*) pv FROM hits WHERE day>=? GROUP BY day, path', [$from]);

    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="annangol-visitors-' . $from . '-' . date('Y-m-d') . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['date', 'page', 'visitors', 'pageviews']);
        usort($byPageDay, fn($a, $b) => [$a['day'], $a['path']] <=> [$b['day'], $b['path']]);
        foreach ($byPageDay as $r) fputcsv($o, [$r['day'], $r['path'], $r['v'], $r['pv']]);
        exit;
    }

    $out = [
        'from' => $from, 'to' => date('Y-m-d'), 'today' => date('Y-m-d'), 'yesterday' => date('Y-m-d', strtotime('-1 day')),
        'byPageDay' => $byPageDay,
        'daily' => $q('SELECT day, COUNT(DISTINCT vid) v, COUNT(*) pv FROM hits WHERE day>=? GROUP BY day', [$from]),
        'refs' => $q("SELECT COALESCE(ref,'(직접 방문/북마크)') k, COUNT(DISTINCT day||vid) v FROM hits WHERE day>=? GROUP BY k ORDER BY v DESC LIMIT 15", [$from]),
        'utm' => $q("SELECT src||' / '||COALESCE(med,'-')||COALESCE(' / '||camp,'') k, COUNT(DISTINCT day||vid) v FROM hits WHERE day>=? AND src IS NOT NULL GROUP BY k ORDER BY v DESC LIMIT 15", [$from]),
        'devices' => $q('SELECT device k, COUNT(DISTINCT day||vid) v FROM hits WHERE day>=? GROUP BY k ORDER BY v DESC', [$from]),
        'langs' => $q("SELECT COALESCE(lang,'?') k, COUNT(DISTINCT day||vid) v FROM hits WHERE day>=? GROUP BY k ORDER BY v DESC LIMIT 12", [$from]),
        'events' => $q('SELECT day, name, COUNT(*) n, COUNT(DISTINCT vid) v FROM events WHERE day>=? GROUP BY day, name', [$from]),
        'eventsByPage' => $q('SELECT path, name, COUNT(*) n FROM events WHERE day>=? GROUP BY path, name', [$from]),
    ];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>아난골 방문자 분석</title>
<style>
:root{color-scheme:light;
  --surface-0:#f3f2ef;--surface-1:#fcfcfb;--rule:#e2e1dc;--grid:#ecebe7;
  --text-primary:#0b0b0b;--text-secondary:#52514e;--text-muted:#7a7974;
  --series-1:#2a78d6;--series-2:#eb6834;
  --seq-0:#eef4fc;--seq-1:#cfe0f7;--seq-2:#9ec1ee;--seq-3:#5f9ae2;--seq-4:#2a78d6;--seq-5:#1a4f93}
@media (prefers-color-scheme:dark){:root{color-scheme:dark;
  --surface-0:#111110;--surface-1:#1a1a19;--rule:#2e2e2c;--grid:#262624;
  --text-primary:#f0efec;--text-secondary:#c3c2b7;--text-muted:#8f8e88;
  --series-1:#3987e5;--series-2:#d95926;
  --seq-0:#1d2633;--seq-1:#1f3a5e;--seq-2:#245089;--seq-3:#2f68b4;--seq-4:#3987e5;--seq-5:#7fb2f0}}
*{box-sizing:border-box}
body{margin:0;background:var(--surface-0);color:var(--text-primary);font:14px/1.45 system-ui,-apple-system,"Apple SD Gothic Neo","Malgun Gothic","Noto Sans KR",sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:20px 16px 48px}
header{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
h1{font-size:20px;margin:0;flex:1}
h2{font-size:15px;margin:0 0 10px}
a{color:var(--series-1)}
.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
select,button,input{font:inherit;color:inherit;background:var(--surface-1);border:1px solid var(--rule);border-radius:8px;padding:7px 10px}
button{cursor:pointer}
.seg{display:inline-flex;border:1px solid var(--rule);border-radius:8px;overflow:hidden}
.seg button{border:0;border-radius:0;border-inline-end:1px solid var(--rule)}
.seg button:last-child{border-inline-end:0}
.seg button[aria-pressed=true]{background:var(--series-1);color:#fff}
.card{background:var(--surface-1);border:1px solid var(--rule);border-radius:12px;padding:16px;margin-bottom:14px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.tile{background:var(--surface-1);border:1px solid var(--rule);border-radius:12px;padding:12px 14px}
.tile .l{color:var(--text-secondary);font-size:12px}
.tile .n{font-size:26px;font-weight:700;font-variant-numeric:tabular-nums;margin-top:2px}
.tile .s{color:var(--text-muted);font-size:12px}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px}
.grid2 .card{margin:0}
table{border-collapse:collapse;width:100%;font-variant-numeric:tabular-nums}
th,td{padding:6px 8px;text-align:end;border-bottom:1px solid var(--grid);white-space:nowrap}
th:first-child,td:first-child{text-align:start}
th{color:var(--text-secondary);font-weight:600;font-size:12px}
.scroll{overflow-x:auto}
.heat td.c{min-width:30px;text-align:center;font-size:12px;padding:5px 3px;border:2px solid var(--surface-1)}
.heat th.d{font-size:11px;text-align:center;padding:4px 2px}
.legend{display:flex;gap:16px;color:var(--text-secondary);font-size:12px;margin:-4px 0 6px}
.legend i{display:inline-block;width:14px;height:3px;border-radius:2px;vertical-align:middle;margin-inline-end:6px}
#chart{position:relative}
#tip{position:absolute;pointer-events:none;background:var(--surface-1);border:1px solid var(--rule);border-radius:8px;padding:6px 9px;font-size:12px;box-shadow:0 6px 18px rgba(0,0,0,.15);display:none;white-space:nowrap}
.bar{height:8px;background:var(--series-1);border-radius:4px;display:inline-block;vertical-align:middle;margin-inline-start:8px}
.muted{color:var(--text-muted)}
.login{max-width:340px;margin:12vh auto}
.login input{width:100%;margin:10px 0}
.err{color:#c03030}
</style>
</head>
<body>
<?php if (!$authed): ?>
<div class="wrap login card">
  <h1>아난골 방문자 분석</h1>
  <form method="post">
    <input type="password" name="pw" placeholder="비밀번호" autofocus autocomplete="current-password">
    <button type="submit" style="width:100%">로그인</button>
    <?php if ($err): ?><p class="err"><?= htmlspecialchars($err) ?></p><?php endif; ?>
  </form>
</div>
<?php else: ?>
<div class="wrap">
  <header>
    <h1>아난골 방문자 분석 <span class="muted" style="font-size:13px;font-weight:400">annangol.com</span></h1>
    <a href="?logout=1">로그아웃</a>
  </header>
  <div class="filters">
    <div class="seg" id="range">
      <button data-d="7">7일</button><button data-d="30" aria-pressed="true">30일</button><button data-d="90">90일</button><button data-d="365">1년</button>
    </div>
    <select id="page"><option value="">전체 페이지</option></select>
    <a id="csv" href="?csv=1&days=30">CSV 내려받기</a>
    <span class="muted" id="range-label"></span>
  </div>

  <div class="tiles" id="tiles"></div>

  <div class="card">
    <h2 id="chart-title">일별 방문 추이</h2>
    <div class="legend"><span><i style="background:var(--series-1)"></i>방문자(일별 순방문)</span><span><i style="background:var(--series-2)"></i>페이지뷰</span></div>
    <div id="chart"><svg id="svg" width="100%" height="260" role="img" aria-label="일별 방문 추이"></svg><div id="tip"></div></div>
  </div>

  <div class="card">
    <h2>페이지별 일별 방문자</h2>
    <p class="muted" style="margin:-4px 0 10px">색이 진할수록 방문자가 많습니다. 칸에 마우스를 올리면 수치가 보입니다.</p>
    <div class="scroll"><table class="heat" id="heat"></table></div>
  </div>

  <div class="card">
    <h2>페이지별 합계</h2>
    <div class="scroll"><table id="pages"></table></div>
  </div>

  <div class="grid2">
    <div class="card"><h2>유입 경로</h2><table id="refs"></table></div>
    <div class="card"><h2>광고·캠페인 (UTM / Google Ads)</h2><table id="utm"></table></div>
    <div class="card"><h2>행동 (클릭)</h2><table id="events"></table></div>
    <div class="card"><h2>기기 / 브라우저 언어</h2><table id="devices"></table><br><table id="langs"></table></div>
  </div>
  <p class="muted">방문자 수는 하루 단위로 셉니다(IP를 저장하지 않고 매일 바뀌는 익명 식별값 사용). 기간 합계는 일별 순방문의 합입니다. 로그인한 이 브라우저는 집계에서 제외됩니다. 시간대: 미 동부.</p>
</div>
<script>
const PAGE_NAMES={'/':'홈 (영어 기본)','/en/':'English','/ko/':'한국어','/es/':'Español','/vi/':'Tiếng Việt','/zh/':'中文','/mn/':'Монгол','/tl/':'Filipino','/ar/':'العربية','/fr/':'Français','/am/':'አማርኛ','/fa/':'فارسی','/bn/':'বাংলা','/ht/':'Kreyòl','/pt/':'Português','/ru/':'Русский'};
const EV_NAMES={order_click:'온라인 주문 클릭',directions_click:'길찾기(지도) 클릭',phone_copy:'전화번호 복사',phone_call:'전화 걸기',lang_switch:'언어 변경',outbound_click:'기타 외부 링크'};
const DEV={mobile:'모바일',desktop:'데스크톱',tablet:'태블릿'};
const $=s=>document.querySelector(s), fmt=n=>(n||0).toLocaleString('ko-KR');
const esc=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const pname=p=>PAGE_NAMES[p]||p;
let days=30, page='', D=null;

function dayList(from,to){const a=[];let d=new Date(from+'T12:00:00');const e=new Date(to+'T12:00:00');while(d<=e){a.push(d.toISOString().slice(0,10));d.setDate(d.getDate()+1)}return a}
const short=d=>{const [,m,dd]=d.split('-');return (+m)+'/'+(+dd)};
const wk=d=>'일월화수목금토'[new Date(d+'T12:00:00').getDay()];

async function load(){
  $('#csv').href='?csv=1&days='+days;
  const r=await fetch('?api=1&days='+days,{credentials:'same-origin'});
  if(!r.ok){location.reload();return}
  D=await r.json();
  const sel=$('#page'), cur=sel.value, paths=[...new Set(D.byPageDay.map(r=>r.path))].sort((a,b)=>(a==='/'?-1:b==='/'?1:a.localeCompare(b)));
  sel.innerHTML='<option value="">전체 페이지</option>'+paths.map(p=>`<option value="${esc(p)}">${esc(pname(p))} (${esc(p)})</option>`).join('');
  sel.value=paths.includes(cur)?cur:''; page=sel.value;
  render();
}

function series(){
  const days=dayList(D.from,D.to), m={};
  const src=page?D.byPageDay.filter(r=>r.path===page):D.daily;
  src.forEach(r=>{m[r.day]=r});
  return days.map(d=>({day:d,v:+(m[d]?.v||0),pv:+(m[d]?.pv||0)}));
}

function render(){
  $('#range-label').textContent=`${D.from} ~ ${D.to}`;
  const s=series(), sum=k=>s.reduce((a,b)=>a+b[k],0);
  const at=d=>s.find(x=>x.day===d)||{v:0,pv:0};
  const evTot=n=>D.events.filter(e=>e.name===n).reduce((a,b)=>a+ +b.n,0);
  const pageNote=page?` · ${pname(page)}`:'';
  $('#tiles').innerHTML=[
    ['오늘 방문자',at(D.today).v,`페이지뷰 ${fmt(at(D.today).pv)}${pageNote}`],
    ['어제 방문자',at(D.yesterday).v,`페이지뷰 ${fmt(at(D.yesterday).pv)}`],
    [`${days}일 방문`,sum('v'),`일평균 ${(sum('v')/s.length).toFixed(1)}`],
    [`${days}일 페이지뷰`,sum('pv'),''],
    ['온라인 주문 클릭',evTot('order_click'),`${days}일 · 전체 페이지`],
    ['길찾기 클릭',evTot('directions_click'),`전화번호 복사 ${fmt(evTot('phone_copy'))}`],
  ].map(([l,n,x])=>`<div class="tile"><div class="l">${l}</div><div class="n">${fmt(n)}</div><div class="s">${esc(x)}</div></div>`).join('');
  $('#chart-title').textContent='일별 방문 추이'+(page?` — ${pname(page)}`:' — 전체');
  drawChart(s); drawHeat(); drawPages(); drawSmall();
}

function drawChart(s){
  const svg=$('#svg'), W=svg.clientWidth||900, H=260, L=40, R=12, T=12, B=28;
  const max=Math.max(4,...s.map(x=>x.pv)), nice=Math.ceil(max/4)*4;
  const x=i=>L+(s.length<2?0:i*(W-L-R)/(s.length-1)), y=v=>T+(H-T-B)*(1-v/nice);
  let g='';
  for(let i=0;i<=4;i++){const v=nice*i/4;g+=`<line x1="${L}" x2="${W-R}" y1="${y(v)}" y2="${y(v)}" stroke="var(--grid)"/><text x="${L-6}" y="${y(v)+4}" text-anchor="end" font-size="11" fill="var(--text-muted)">${fmt(v)}</text>`}
  const step=Math.ceil(s.length/10);
  s.forEach((d,i)=>{if(i%step===0||i===s.length-1)g+=`<text x="${x(i)}" y="${H-8}" text-anchor="middle" font-size="11" fill="var(--text-muted)">${short(d.day)}</text>`});
  const line=(k,c)=>`<polyline fill="none" stroke="${c}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="${s.map((d,i)=>x(i)+','+y(d[k])).join(' ')}"/>`;
  g+=line('pv','var(--series-2)')+line('v','var(--series-1)');
  if(s.length<=45)s.forEach((d,i)=>{g+=`<circle cx="${x(i)}" cy="${y(d.v)}" r="3" fill="var(--series-1)" stroke="var(--surface-1)" stroke-width="2"/>`});
  g+=`<line id="xh" y1="${T}" y2="${H-B}" stroke="var(--text-muted)" stroke-dasharray="3 3" visibility="hidden"/><rect x="${L}" y="0" width="${W-L-R}" height="${H}" fill="transparent" id="hit"/>`;
  svg.setAttribute('viewBox',`0 0 ${W} ${H}`); svg.innerHTML=g;
  const tip=$('#tip'), xh=svg.querySelector('#xh');
  svg.querySelector('#hit').onmousemove=e=>{
    const bx=svg.getBoundingClientRect(), px=(e.clientX-bx.left)*W/bx.width;
    const i=Math.max(0,Math.min(s.length-1,Math.round((px-L)/((W-L-R)/Math.max(1,s.length-1))))), d=s[i];
    xh.setAttribute('x1',x(i));xh.setAttribute('x2',x(i));xh.setAttribute('visibility','visible');
    tip.style.display='block';tip.innerHTML=`<b>${d.day} (${wk(d.day)})</b><br>방문자 ${fmt(d.v)} · 페이지뷰 ${fmt(d.pv)}`;
    const left=x(i)*bx.width/W; tip.style.left=Math.min(left+12,bx.width-170)+'px'; tip.style.top='8px';
  };
  svg.querySelector('#hit').onmouseleave=()=>{tip.style.display='none';xh.setAttribute('visibility','hidden')};
}

function drawHeat(){
  const ds=dayList(D.from,D.to).slice(-31), m={}, tot={};
  D.byPageDay.forEach(r=>{(m[r.path]=m[r.path]||{})[r.day]=+r.v;tot[r.path]=(tot[r.path]||0)+ +r.v});
  const paths=Object.keys(m).sort((a,b)=>tot[b]-tot[a]);
  const max=Math.max(1,...D.byPageDay.filter(r=>ds.includes(r.day)).map(r=>+r.v));
  const shade=v=>v===0?'var(--seq-0)':`var(--seq-${Math.min(5,1+Math.floor(4*v/max))})`;
  const ink=v=>v>0&&Math.min(5,1+Math.floor(4*v/max))>=4?'#fff':'var(--text-primary)';
  let h=`<tr><th>페이지</th>${ds.map(d=>`<th class="d">${short(d)}<br>${wk(d)}</th>`).join('')}</tr>`;
  if(!paths.length)h+=`<tr><td class="muted" colspan="${ds.length+1}">아직 데이터가 없습니다. 방문이 들어오면 여기에 쌓입니다.</td></tr>`;
  paths.forEach(p=>{h+=`<tr><td>${esc(pname(p))} <span class="muted">${esc(p)}</span></td>`+ds.map(d=>{const v=m[p][d]||0;return `<td class="c" style="background:${shade(v)};color:${ink(v)}" title="${esc(pname(p))} · ${d} (${wk(d)}) · 방문자 ${v}">${v||''}</td>`}).join('')+'</tr>'});
  $('#heat').innerHTML=h;
  if(dayList(D.from,D.to).length>31)$('#heat').insertAdjacentHTML('beforeend',`<tr><td class="muted" colspan="${ds.length+1}">최근 31일만 표시합니다. 전체 기간은 CSV로 받으세요.</td></tr>`);
}

function drawPages(){
  const agg={};
  D.byPageDay.forEach(r=>{const a=agg[r.path]=agg[r.path]||{v:0,pv:0,days:0};a.v+= +r.v;a.pv+= +r.pv;a.days++});
  const ev={};D.eventsByPage.forEach(e=>{(ev[e.path]=ev[e.path]||{})[e.name]=+e.n});
  const rows=Object.entries(agg).sort((a,b)=>b[1].v-a[1].v), max=Math.max(1,...rows.map(r=>r[1].v)), total=rows.reduce((a,r)=>a+r[1].v,0);
  $('#pages').innerHTML='<tr><th>페이지</th><th>방문</th><th>비중</th><th>페이지뷰</th><th>일평균 방문</th><th>주문 클릭</th><th>길찾기</th><th></th></tr>'+
    (rows.length?rows.map(([p,a])=>`<tr><td>${esc(pname(p))} <span class="muted">${esc(p)}</span></td><td>${fmt(a.v)}</td><td>${(100*a.v/total).toFixed(1)}%</td><td>${fmt(a.pv)}</td><td>${(a.v/dayList(D.from,D.to).length).toFixed(1)}</td><td>${fmt(ev[p]?.order_click)}</td><td>${fmt(ev[p]?.directions_click)}</td><td style="width:30%"><span class="bar" style="width:${Math.max(2,100*a.v/max)}%"></span></td></tr>`).join(''):'<tr><td class="muted" colspan="8">아직 데이터가 없습니다.</td></tr>');
}

function kv(id,rows,label,map){
  const max=Math.max(1,...rows.map(r=>+r.v));
  $(id).innerHTML=`<tr><th>${label}</th><th>방문</th><th style="width:40%"></th></tr>`+(rows.length?rows.map(r=>`<tr><td>${esc(map?map(r.k):r.k)}</td><td>${fmt(+r.v)}</td><td><span class="bar" style="width:${Math.max(2,100*r.v/max)}%"></span></td></tr>`).join(''):'<tr><td class="muted" colspan="3">데이터 없음</td></tr>');
}
function drawSmall(){
  kv('#refs',D.refs,'출처'); kv('#utm',D.utm,'source / medium / campaign'); kv('#devices',D.devices,'기기',k=>DEV[k]||k); kv('#langs',D.langs,'브라우저 언어');
  const e={};D.events.forEach(r=>{const a=e[r.name]=e[r.name]||{n:0,v:0};a.n+= +r.n;a.v+= +r.v});
  const rows=Object.entries(e).sort((a,b)=>b[1].n-a[1].n);
  $('#events').innerHTML='<tr><th>행동</th><th>클릭 수</th><th>클릭한 방문자</th></tr>'+(rows.length?rows.map(([k,a])=>`<tr><td>${esc(EV_NAMES[k]||k)}</td><td>${fmt(a.n)}</td><td>${fmt(a.v)}</td></tr>`).join(''):'<tr><td class="muted" colspan="3">데이터 없음</td></tr>');
}

document.querySelectorAll('#range button').forEach(b=>b.onclick=()=>{document.querySelectorAll('#range button').forEach(x=>x.setAttribute('aria-pressed',x===b));days=+b.dataset.d;load()});
$('#page').onchange=e=>{page=e.target.value;render()};
addEventListener('resize',()=>D&&drawChart(series()));
load();
</script>
<?php endif; ?>
</body>
</html>
