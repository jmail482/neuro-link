<?php defined('ABSPATH')||exit; ?>
<div class="wrap nl-page">
<h1>🔌 Provider Health</h1>
<div style="display:flex;justify-content:flex-end;margin-bottom:12px">
  <button class="nl-btn secondary" id="nl-health-reload">⟳ Refresh</button>
</div>
<div id="nl-health-grid" class="nl-card-grid"><p class="nl-muted">Loading…</p></div>
<script>
(function(){
  function load(){
    fetch(NeuroLink.root+'/provider-health',{headers:{'X-WP-Nonce':NeuroLink.nonce}})
      .then(r=>r.json()).then(d=>{
        // FIX: REST returns {health:[...]} wrapper
        const items=d.health||d||[];
        if(!items.length){document.getElementById('nl-health-grid').innerHTML='<p class="nl-muted">No provider data yet — run a task or chat to populate.</p>';return;}
        let html='';
        items.forEach(p=>{
          const color=p.state==='closed'?'#34c759':p.state==='open'?'#ff3b30':'#ff9500';
          const icon =p.state==='closed'?'✅':p.state==='open'?'🔴':'🟡';
          html+=`<div class="nl-card" style="min-width:210px">
            <div style="font-size:20px;font-weight:700;margin-bottom:4px">${icon} ${(p.provider||'—').toUpperCase()}</div>
            <div style="font-size:13px;color:${color};font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:12px">${p.state}</div>
            <div class="nl-stat-row"><span>Failures</span><span>${p.failure_count||0}</span></div>
            <div class="nl-stat-row"><span>Last Failure</span><span style="font-size:11px">${p.last_failure_at||'Never'}</span></div>
            <div class="nl-stat-row"><span>Last Success</span><span style="font-size:11px">${p.last_success_at||'Never'}</span></div>
            ${p.cooldown_until?`<div class="nl-stat-row"><span>Cooldown Until</span><span style="color:#ff9500;font-size:11px">${p.cooldown_until}</span></div>`:''}
          </div>`;
        });
        document.getElementById('nl-health-grid').innerHTML=html;
      }).catch(e=>document.getElementById('nl-health-grid').innerHTML='<p style="color:#ff3b30">Error: '+e.message+'</p>');
  }
  document.getElementById('nl-health-reload').addEventListener('click',load);
  load();
})();
</script>
</div>
