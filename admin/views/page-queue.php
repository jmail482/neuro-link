<?php defined('ABSPATH')||exit; ?>
<div class="wrap nl-page">
<h1>📋 Task Queue</h1>
<div class="nl-card">
  <div class="nl-toolbar">
    <select id="nl-q-status"><option value="">All Statuses</option><option>pending</option><option>running</option><option>completed</option><option>failed</option><option>dead_letter</option><option>cancelled</option></select>
    <button class="nl-btn" id="nl-q-load">⟳ Refresh</button>
    <button class="nl-btn secondary" id="nl-q-run-worker">▶ Run Worker Now</button>
  </div>
  <div id="nl-q-wrap"><p class="nl-muted">Loading…</p></div>
</div>
<script>
(function(){
  const api = s => fetch(NeuroLink.root+s,{headers:{'X-WP-Nonce':NeuroLink.nonce}}).then(r=>r.json());
  const post = (s,b) => fetch(NeuroLink.root+s,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':NeuroLink.nonce},body:JSON.stringify(b)}).then(r=>r.json());
  function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
  function load(){
    const status=document.getElementById('nl-q-status').value;
    api('/tasks?limit=50'+(status?'&status='+status:'')).then(d=>{
      const tasks=d.tasks||[];
      if(!tasks.length){document.getElementById('nl-q-wrap').innerHTML='<p class="nl-muted">No tasks found.</p>';return;}
      let html='<table class="nl-table"><thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Provider</th><th>Attempts</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
      tasks.forEach(t=>{
        const badge=`<span class="nl-badge nl-badge-${esc(t.status)}">${esc(t.status)}</span>`;
        html+=`<tr><td><code style="font-size:11px">${esc(t.request_id.substring(0,8))}…</code></td><td>${esc(t.task_type||'—')}</td><td>${badge}</td><td>${esc(t.provider_used||'—')}</td><td>${esc(t.attempt_count||0)}/${esc(t.max_attempts||3)}</td><td>${esc(t.created_at||'')}</td><td>`;
        if(['failed','dead_letter'].includes(t.status)) html+=`<button class="nl-btn-sm" onclick="retryTask('${esc(t.request_id)}')">Retry</button> `;
        if(['pending','leased'].includes(t.status)) html+=`<button class="nl-btn-sm danger" onclick="cancelTask('${esc(t.request_id)}')">Cancel</button>`;
        html+=`</td></tr>`;
      });
      html+='</tbody></table>';
      document.getElementById('nl-q-wrap').innerHTML=html;
    });
  }
  window.retryTask=id=>post('/tasks/'+id+'/retry',{}).then(load);
  window.cancelTask=id=>post('/tasks/'+id+'/cancel',{}).then(load);
  document.getElementById('nl-q-load').addEventListener('click',load);
  document.getElementById('nl-q-status').addEventListener('change',load);
  document.getElementById('nl-q-run-worker').addEventListener('click',function(){
    this.disabled=true;this.textContent='Running…';
    fetch(NeuroLink.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=neuro_link_run_worker&_ajax_nonce='+NeuroLink.ajaxNonce})
      .then(r=>r.json()).then(()=>{this.disabled=false;this.textContent='▶ Run Worker Now';load();});
  });
  load();
})();
</script>
</div>
