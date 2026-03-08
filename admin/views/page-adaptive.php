<?php defined('ABSPATH')||exit; ?>
<div class="wrap nl-page">
<h1>🧠 Adaptive (Dev)</h1>
<div class="nl-card-grid">
  <div class="nl-card" style="min-width:280px">
    <h3>FreeGPT35 Status</h3>
    <div id="nl-adp-status"><p class="nl-muted">Loading…</p></div>
    <button class="nl-btn" id="nl-adp-toggle" style="margin-top:10px">Toggle</button>
  </div>
  <div class="nl-card" style="flex:1;min-width:300px">
    <h3>Run Comparison</h3>
    <textarea id="nl-adp-prompt" class="nl-input" rows="3" placeholder="Enter prompt to compare…" style="width:100%"></textarea>
    <button class="nl-btn" id="nl-adp-compare" style="margin-top:8px">⚡ Compare</button>
    <div id="nl-adp-result" style="margin-top:10px;font-size:13px;white-space:pre-wrap"></div>
  </div>
</div>
<div class="nl-card" style="margin-top:16px">
  <h3>Recent Comparisons</h3>
  <div id="nl-adp-history"><p class="nl-muted">Loading…</p></div>
</div>
<script>
(function(){
  const api=(s,o)=>fetch(NeuroLink.root+s,Object.assign({headers:{'X-WP-Nonce':NeuroLink.nonce,'Content-Type':'application/json'}},o)).then(r=>r.json());
  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;');}
  function loadStatus(){
    api('/freegpt35/status').then(d=>{
      const en=d.enabled;const av=d.available;
      document.getElementById('nl-adp-status').innerHTML=`
        <div class="nl-stat-row"><span>Enabled</span><span style="color:${en?'#34c759':'#ff3b30'}">${en?'Yes':'No'}</span></div>
        <div class="nl-stat-row"><span>Available</span><span>${av?'✅':'❌'}</span></div>
        <div class="nl-stat-row"><span>Reliability</span><span>${Math.round((d.reliability||0)*100)}%</span></div>
        <div class="nl-stat-row"><span>URL</span><span>${esc(d.url||'—')}</span></div>`;
    });
  }
  function loadHistory(){
    api('/freegpt35/comparisons').then(d=>{
      const rows=d.comparisons||[];
      if(!rows.length){document.getElementById('nl-adp-history').innerHTML='<p class="nl-muted">No comparisons yet.</p>';return;}
      let html='<table class="nl-table"><thead><tr><th>Prompt (excerpt)</th><th>Provider</th><th>Divergence</th><th>Ref OK</th><th>Time</th></tr></thead><tbody>';
      rows.forEach(r=>{
        html+=`<tr><td>${esc((r.primary_text||'').substring(0,60))}…</td><td>${esc(r.primary_provider)}</td><td>${parseFloat(r.divergence_score||0).toFixed(3)}</td><td>${r.ref_success?'✅':'❌'}</td><td>${esc(r.created_at)}</td></tr>`;
      });
      document.getElementById('nl-adp-history').innerHTML=html+'</tbody></table>';
    });
  }
  document.getElementById('nl-adp-toggle').addEventListener('click',function(){
    api('/freegpt35/status').then(d=>api('/freegpt35/toggle',{method:'POST',body:JSON.stringify({enabled:!d.enabled})})).then(()=>{loadStatus();});
  });
  document.getElementById('nl-adp-compare').addEventListener('click',function(){
    const p=document.getElementById('nl-adp-prompt').value.trim();
    if(!p)return;
    this.disabled=true;document.getElementById('nl-adp-result').textContent='⏳ Running…';
    api('/freegpt35/compare',{method:'POST',body:JSON.stringify({prompt:p,provider:'ollama'})}).then(d=>{
      document.getElementById('nl-adp-result').textContent=JSON.stringify(d,null,2);
      this.disabled=false;loadHistory();
    }).catch(e=>{document.getElementById('nl-adp-result').textContent='Error: '+e.message;this.disabled=false;});
  });
  loadStatus();loadHistory();
})();
</script>
</div>
