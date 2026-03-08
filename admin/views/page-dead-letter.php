<?php defined('ABSPATH')||exit; ?>
<div class="wrap nl-page">
<h1>☠ Dead Letter Queue</h1>
<p class="nl-muted">Tasks that failed all retry attempts.</p>
<div class="nl-card">
  <div class="nl-toolbar">
    <button class="nl-btn" id="nl-dl-load">⟳ Refresh</button>
    <button class="nl-btn danger" id="nl-dl-purge">🗑 Purge All Dead Letter</button>
  </div>
  <div id="nl-dl-wrap"><p class="nl-muted">Loading…</p></div>
</div>
<script>
(function(){
  const api=(s,o={})=>fetch(NeuroLink.root+s,Object.assign({headers:{'X-WP-Nonce':NeuroLink.nonce,'Content-Type':'application/json'}},o)).then(r=>r.json());
  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;');}
  function load(){
    api('/tasks?status=dead_letter&limit=100').then(d=>{
      const tasks=d.tasks||[];
      if(!tasks.length){document.getElementById('nl-dl-wrap').innerHTML='<p class="nl-muted">No dead letter tasks. 🎉</p>';return;}
      let html='<table class="nl-table"><thead><tr><th>Request ID</th><th>Type</th><th>Error</th><th>Attempts</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
      tasks.forEach(t=>{
        html+=`<tr>
          <td><code style="font-size:11px">${esc(t.request_id)}</code></td>
          <td>${esc(t.task_type)}</td>
          <td style="color:#ff3b30;font-size:12px">${esc(t.error_message||t.error_code||'—')}</td>
          <td>${esc(t.attempt_count)}/${esc(t.max_attempts)}</td>
          <td>${esc(t.created_at)}</td>
          <td><button class="nl-btn-sm" onclick="retryDL('${esc(t.request_id)}')">Retry</button></td>
        </tr>`;
      });
      document.getElementById('nl-dl-wrap').innerHTML=html+'</tbody></table>';
    });
  }
  window.retryDL=id=>api('/tasks/'+id+'/retry',{method:'POST',body:'{}'}).then(load);
  document.getElementById('nl-dl-load').addEventListener('click',load);
  document.getElementById('nl-dl-purge').addEventListener('click',function(){
    if(!confirm('Permanently delete all dead letter tasks?')) return;
    api('/tasks/purge-dead-letter',{method:'POST',body:'{}'}).then(load);
  });
  load();
})();
</script>
</div>
