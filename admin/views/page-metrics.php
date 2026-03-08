<?php defined('ABSPATH')||exit; ?>
<div class="wrap nl-page">
<h1>📊 Metrics</h1>
<div id="nl-metrics-grid" class="nl-card-grid"><p class="nl-muted">Loading…</p></div>
<script>
(function(){
  fetch(NeuroLink.root+'/metrics-summary',{headers:{'X-WP-Nonce':NeuroLink.nonce}})
    .then(r=>r.json()).then(d=>{
      const rows=d.summary||d||[];
      if(!rows.length){document.getElementById('nl-metrics-grid').innerHTML='<p class="nl-muted">No metrics yet — run a chat or task first.</p>';return;}
      let html='';
      rows.forEach(m=>{
        // FIX: use success_count (updated column name from Metrics class)
        const total   = parseInt(m.total||0);
        const success = parseInt(m.success_count||m.successes||0);
        const rate    = total>0?Math.round((success/total)*100):0;
        const color   = rate>=90?'#34c759':rate>=70?'#ff9500':'#ff3b30';
        html+=`<div class="nl-card" style="min-width:200px">
          <div style="font-size:16px;font-weight:700;margin-bottom:8px">${m.provider||'—'}</div>
          <div style="font-size:32px;font-weight:700;color:${color};margin-bottom:2px">${rate}%</div>
          <div style="font-size:11px;color:#86868b;margin-bottom:12px">Success Rate</div>
          <div class="nl-stat-row"><span>Total</span><span>${total}</span></div>
          <div class="nl-stat-row"><span>Successful</span><span>${success}</span></div>
          <div class="nl-stat-row"><span>Failed</span><span>${parseInt(m.failure_count||0)}</span></div>
          <div class="nl-stat-row"><span>Avg Latency</span><span>${Math.round(m.avg_latency_ms||0)}ms</span></div>
          <div class="nl-stat-row"><span>Fallbacks</span><span>${parseInt(m.fallback_count||0)}</span></div>
          ${m.last_request_at?`<div class="nl-stat-row"><span>Last Request</span><span style="font-size:11px">${m.last_request_at}</span></div>`:''}
        </div>`;
      });
      document.getElementById('nl-metrics-grid').innerHTML=html;
    }).catch(e=>document.getElementById('nl-metrics-grid').innerHTML='<p style="color:#ff3b30">Error: '+e.message+'</p>');
})();
</script>
</div>
