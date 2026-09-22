<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BTC News Sentiment</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root{
    --bg:#070d14;
    --panel:#101a25;
    --panel2:#152331;
    --line:#253746;
    --text:#edf5fb;
    --muted:#8ea2b4;
    --green:#28dc83;
    --red:#ff5265;
    --yellow:#f5ca58;
    --cyan:#45c7e7;
}

*{box-sizing:border-box}

body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
}

.container{
    max-width:1450px;
    margin:auto;
    padding:22px;
}

.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:18px;
}

h1{
    margin:0;
    font-size:27px;
}

.sub{
    color:var(--muted);
    font-size:13px;
    margin-top:5px;
}

button{
    border:1px solid var(--line);
    background:var(--panel2);
    color:var(--text);
    padding:10px 16px;
    border-radius:8px;
    cursor:pointer;
}

button:hover{
    border-color:var(--cyan);
}

.status{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:14px;
}

.statusItem{
    padding:8px 11px;
    border-radius:7px;
    background:var(--panel);
    border:1px solid var(--line);
    font-size:12px;
}

.ok{color:var(--green)}
.fail{color:var(--red)}

.grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
}

.card{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:12px;
    padding:18px;
}

.score{
    text-align:center;
    padding:25px;
}

.label{
    color:var(--muted);
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.8px;
}

.value{
    font-size:29px;
    font-weight:700;
    margin-top:7px;
}

.scoreValue{
    font-size:62px;
    font-weight:800;
    margin-top:8px;
}

.bullish{color:var(--green)}
.bearish{color:var(--red)}
.neutral{color:var(--yellow)}

.progress{
    height:9px;
    background:#1b2a37;
    border-radius:20px;
    overflow:hidden;
    margin-top:15px;
}

.bar{
    height:100%;
    background:var(--cyan);
    width:50%;
}

.layout{
    display:grid;
    grid-template-columns:1.25fr 1fr;
    gap:14px;
    margin-top:14px;
}

.chartBox{
    height:350px;
}

.stats{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
    margin-top:15px;
}

.stat{
    background:var(--panel2);
    padding:13px;
    border-radius:8px;
}

.news{
    margin-top:14px;
}

.newsRow{
    padding:15px 0;
    border-bottom:1px solid var(--line);
}

.newsTitle{
    font-size:15px;
    font-weight:650;
    line-height:1.4;
}

.newsMeta{
    color:var(--muted);
    font-size:12px;
    margin-top:7px;
}

.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:20px;
    font-size:11px;
    margin-left:6px;
    background:var(--panel2);
}

a{
    color:inherit;
    text-decoration:none;
}

.errorBox{
    display:none;
    background:#35151c;
    border:1px solid #71313c;
    color:#ff9aa6;
    padding:12px;
    border-radius:8px;
    margin-bottom:14px;
    white-space:pre-wrap;
}

@media(max-width:900px){
    .grid,.layout{
        grid-template-columns:1fr;
    }

    .stats{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<div class="container">

<div class="header">
    <div>
        <h1>₿ BTC News Sentiment</h1>
        <div class="sub">
            Bitcoin news intelligence dashboard
        </div>
    </div>

    <div>
        <span id="updated" class="sub">Starting...</span>
        <button onclick="refreshAll()">↻ Refresh</button>
    </div>
</div>

<div id="errorBox" class="errorBox"></div>

<div class="status">
    <div class="statusItem" id="phpStatus">PHP: checking...</div>
    <div class="statusItem" id="dbStatus">Database: checking...</div>
    <div class="statusItem" id="curlStatus">cURL: checking...</div>
    <div class="statusItem" id="xmlStatus">SimpleXML: checking...</div>
    <div class="statusItem" id="newsStatus">News: checking...</div>
    <div class="statusItem" id="btcStatus">BTC API: checking...</div>
</div>

<div class="grid">

<div class="card score">
    <div class="label">24H Sentiment</div>
    <div id="score" class="scoreValue neutral">0</div>
    <div id="label" class="value neutral">Neutral</div>
    <div class="progress">
        <div id="bar" class="bar"></div>
    </div>
</div>

<div class="card">
    <div class="label">BTC / USDT</div>
    <div id="btc" class="value">--</div>
    <div class="sub">Binance public ticker</div>
</div>

<div class="card">
    <div class="label">Sentiment Change</div>
    <div id="change" class="value">--</div>
    <div class="sub">vs previous recorded snapshot</div>
</div>

<div class="card">
    <div class="label">News Analyzed</div>
    <div id="count" class="value">--</div>
    <div class="sub">last 24 hours</div>
</div>

</div>

<div class="layout">

<div class="card">
    <div class="label">Sentiment History</div>
    <div class="chartBox">
        <canvas id="chart"></canvas>
    </div>
</div>

<div class="card">

    <div class="label">Sentiment Timeframes</div>

    <div class="stats">

        <div class="stat">
            <div class="label">1H</div>
            <div id="s1" class="value">--</div>
        </div>

        <div class="stat">
            <div class="label">6H</div>
            <div id="s6" class="value">--</div>
        </div>

        <div class="stat">
            <div class="label">24H</div>
            <div id="s24" class="value">--</div>
        </div>

    </div>

    <div style="margin-top:25px">
        <div class="label">News Breakdown</div>
        <div id="breakdown" class="value" style="font-size:19px">
            --
        </div>
    </div>

    <div style="margin-top:22px" class="sub">
        Rule-based sentiment indicator. It should be combined with
        price, volume, derivatives and market-structure data for trading research.
    </div>

</div>

</div>

<div class="card news">

    <div class="label">Latest Bitcoin News</div>

    <div class="sub">
        Articles collected during the last 72 hours
    </div>

    <div id="newsList">
        Loading...
    </div>

</div>

</div>

<script>

let chart = null;

function esc(value){
    return String(value ?? '').replace(
        /[&<>"']/g,
        function(m){
            return {
                '&':'&amp;',
                '<':'&lt;',
                '>':'&gt;',
                '"':'&quot;',
                "'":'&#039;'
            }[m];
        }
    );
}

function num(v){
    return Number(v).toLocaleString(
        undefined,
        {maximumFractionDigits:2}
    );
}

function cls(label){
    return String(label || 'Neutral').toLowerCase();
}

function showError(message){
    const box=document.getElementById('errorBox');

    if(!message){
        box.style.display='none';
        box.textContent='';
        return;
    }

    box.style.display='block';
    box.textContent=message;
}

function setStatus(id, text, good){
    const el=document.getElementById(id);
    el.textContent=text;
    el.className='statusItem '+(good?'ok':'fail');
}

async function refreshStatus(){

    try{

        const r=await fetch('api/status.php?x='+Date.now());
        const j=await r.json();

        setStatus(
            'phpStatus',
            'PHP: '+j.php_version,
            true
        );

        setStatus(
            'dbStatus',
            'Database: '+(j.database.ok?'SQLite ✓':'SQLite ✗'),
            j.database.ok
        );

        setStatus(
            'curlStatus',
            'cURL: '+(j.extensions.curl?'✓':'✗'),
            j.extensions.curl
        );

        setStatus(
            'xmlStatus',
            'SimpleXML: '+(j.extensions.simplexml?'✓':'✗'),
            j.extensions.simplexml
        );

    }catch(e){

        setStatus('phpStatus','PHP: API error',false);
        setStatus('dbStatus','Database: unknown',false);
    }
}

async function refreshNews(){

    try{

        const r=await fetch(
            'api/news.php?limit=40&hours=72&x='+Date.now()
        );

        const j=await r.json();

        if(!j.ok){
            throw new Error(j.error || 'News API error');
        }

        const el=document.getElementById('newsList');

        if(!j.items.length){

            el.innerHTML=
                '<div class="sub" style="padding:20px 0">'+
                'No news collected yet. Click Refresh.'+
                '</div>';

            setStatus('newsStatus','News: 0 articles',false);
            return;
        }

        setStatus(
            'newsStatus',
            'News: '+j.items.length+' articles',
            true
        );

        el.innerHTML=j.items.map(function(x){

            return `
            <div class="newsRow">

                <a href="${esc(x.url)}"
                   target="_blank"
                   rel="noopener">

                    <div class="newsTitle">

                        ${esc(x.title)}

                        <span class="badge ${cls(x.sentiment_label)}">
                            ${esc(x.sentiment_label)}
                            ${num(x.sentiment_score)}
                        </span>

                    </div>

                    <div class="newsMeta">
                        ${esc(x.source || '')}
                        ·
                        ${esc(x.published_at)}
                        ·
                        Importance ${num(x.importance)}
                    </div>

                    ${
                        x.keywords
                        ?
                        `<div class="newsMeta">
                            Matched: ${esc(x.keywords)}
                        </div>`
                        :
                        ''
                    }

                </a>

            </div>
            `;

        }).join('');

    }catch(e){

        setStatus('newsStatus','News: API error',false);

        showError(
            'News API error: '+e.message+
            '\\nCheck api/news.php directly.'
        );
    }
}

async function refreshSentiment(){

    try{

        const r=await fetch(
            'api/sentiment.php?x='+Date.now()
        );

        const j=await r.json();

        if(!j.ok){
            throw new Error(j.error || 'Sentiment API error');
        }

        const score=Number(j.score);

        const c=
            score >= 25
            ? 'bullish'
            : score <= -25
            ? 'bearish'
            : 'neutral';

        document.getElementById('score').textContent=
            (score>0?'+':'')+num(score);

        document.getElementById('score').className=
            'scoreValue '+c;

        document.getElementById('label').textContent=
            j.label;

        document.getElementById('label').className=
            'value '+c;

        document.getElementById('btc').textContent=
            j.btc_price
            ? '$'+Number(j.btc_price).toLocaleString()
            : '--';

        setStatus(
            'btcStatus',
            j.btc_price
            ? 'BTC API: ✓'
            : 'BTC API: unavailable',
            !!j.btc_price
        );

        document.getElementById('change').textContent=
            (Number(j.change)>=0?'+':'')+
            num(j.change);

        document.getElementById('change').className=
            'value '+
            (Number(j.change)>=0?'bullish':'bearish');

        document.getElementById('count').textContent=
            j.twenty_four_hour.count;

        document.getElementById('s1').textContent=
            num(j.one_hour.score);

        document.getElementById('s6').textContent=
            num(j.six_hour.score);

        document.getElementById('s24').textContent=
            num(j.twenty_four_hour.score);

        document.getElementById('breakdown').innerHTML=
            `<span class="bullish">
                ${j.twenty_four_hour.bullish} Bullish
            </span>
            ·
            <span class="neutral">
                ${j.twenty_four_hour.neutral} Neutral
            </span>
            ·
            <span class="bearish">
                ${j.twenty_four_hour.bearish} Bearish
            </span>`;

        document.getElementById('bar').style.width=
            Math.max(
                2,
                Math.min(100,(score+100)/2)
            )+'%';

        document.getElementById('updated').textContent=
            'Updated '+new Date().toLocaleTimeString();

    }catch(e){

        showError(
            'Sentiment API error: '+e.message+
            '\\nCheck api/sentiment.php directly.'
        );
    }
}

async function refreshChart(){

    try{

        const r=await fetch(
            'api/history.php?hours=24&x='+Date.now()
        );

        const j=await r.json();

        if(!j.ok){
            throw new Error(j.error || 'History API error');
        }

        const labels=j.items.map(
            x=>new Date(
                x.recorded_at.replace(' ','T')
            ).toLocaleTimeString(
                [],
                {hour:'2-digit',minute:'2-digit'}
            )
        );

        const values=j.items.map(
            x=>Number(x.score)
        );

        if(chart){
            chart.destroy();
        }

        chart=new Chart(
            document.getElementById('chart'),
            {
                type:'line',

                data:{
                    labels:labels,

                    datasets:[
                        {
                            label:'BTC Sentiment',
                            data:values,
                            tension:.25,
                            pointRadius:2
                        }
                    ]
                },

                options:{
                    responsive:true,
                    maintainAspectRatio:false,

                    scales:{
                        y:{
                            min:-100,
                            max:100,
                            grid:{
                                color:'#253746'
                            }
                        },

                        x:{
                            grid:{
                                color:'#253746'
                            }
                        }
                    },

                    plugins:{
                        legend:{
                            display:false
                        }
                    }
                }
            }
        );

    }catch(e){

        console.log('History:',e.message);
    }
}

async function refreshFeeds(){

    try{

        const r=await fetch(
            'api/refresh.php?x='+Date.now()
        );

        const j=await r.json();

        if(!j.ok){
            throw new Error(j.error || 'Refresh failed');
        }

        if(j.errors && j.errors.length){

            console.log(
                'Feed warnings:',
                j.errors
            );
        }

    }catch(e){

        showError(
            'News collection error: '+e.message
        );
    }
}

async function refreshAll(){

    showError('');

    await refreshStatus();

    await refreshFeeds();

    await Promise.all([
        refreshSentiment(),
        refreshNews(),
        refreshChart()
    ]);
}

refreshAll();

setInterval(
    refreshAll,
    5*60*1000
);

</script>

</body>
</html>
