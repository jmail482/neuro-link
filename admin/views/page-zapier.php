<?php defined('ABSPATH')||exit; ?>
<div class="wrap nl-page">
<h1>⚡ Zapier Integration</h1>
<?php
$inbound_url = rest_url('neuro-link/v1/zapier/inbound');
$secret_set  = !empty(get_option(\NeuroLink\Zapier_Webhook::SECRET_KEY,''));
$webhook_url = get_option(\NeuroLink\Zapier_Webhook::WEBHOOK_URL,'');
?>
<div class="nl-card-grid" style="margin-bottom:16px">
  <div class="nl-card">
    <div class="nl-label">Your Inbound URL (paste into Zapier)</div>
    <div style="display:flex;gap:8px;align-items:center">
      <code style="background:#f6f7f7;padding:7px 10px;border-radius:6px;font-size:12px;border:1px solid #e0e0e0;flex:1"><?php echo esc_html($inbound_url); ?></code>
      <button class="nl-btn-sm" onclick="navigator.clipboard.writeText('<?php echo esc_js($inbound_url);?>');this.textContent='✅';setTimeout(()=>this.textContent='Copy',1500)">Copy</button>
    </div>
  </div>
  <div class="nl-card">
    <div class="nl-label">Status</div>
    <div style="font-size:22px"><?php echo $webhook_url ? '✅ Connected' : '⛔ Not configured'; ?></div>
    <div style="font-size:12px;color:#86868b;margin-top:4px">Secret: <?php echo $secret_set ? '🔒 Set' : '⚠️ Not set'; ?></div>
  </div>
</div>
<div class="nl-card" style="max-width:600px;margin-bottom:16px">
  <h3>Configuration</h3>
  <label class="nl-label">Outbound Webhook URL (where results go back to Zapier)</label>
  <input id="nl-zap-out" type="url" class="nl-input" style="width:100%;margin-bottom:10px" value="<?php echo esc_attr($webhook_url);?>" placeholder="https://hooks.zapier.com/hooks/catch/…">
  <label class="nl-label">Shared Secret</label>
  <input id="nl-zap-secret" type="text" class="nl-input" style="width:100%;margin-bottom:10px" placeholder="Leave blank to auto-generate" value="">
  <button class="nl-btn" id="nl-zap-save">💾 Save</button>
  <button class="nl-btn secondary" id="nl-zap-test" style="margin-left:8px">🧪 Send Test Task</button>
</div>
<div class="nl-card">
  <h3>Recent Events</h3>
  <div id="nl-zap-log"><p class="nl-muted">Loading…</p></div>
</div>
<script>
(function(){
  const api=(s,o)=>fetch(NeuroLink.root+s,Object.assign({headers:{'X-WP-Nonce':NeuroLink.nonce,'Content-Type':'application/json'}},o)).then(r=>r.json());
  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;');}
  function loadLog(){
    api('/zapier/status').then(d=>{
      const log=d.recent_events||[];
      if(!log.length){document.getElementById('nl-zap-log').innerHTML='<p class="nl-muted">No events yet.</p>';return;}
      let html='<table class="nl-table"><thead><tr><th>Direction</th><th>Request ID</th><th>Type</th><th>Status</th><th>Time</th></tr></thead><tbody>';
      log.forEach(e=>{html+=`<tr><td>${e.direction==='inbound'?'⬇ In':'⬆ Out'}</td><td><code style="font-size:11px">${esc(e.request_id||'—')}</code></td><td>${esc(e.task_type||'—')}</td><td>${e.success!==undefined?(e.success?'✅':'❌'):'⏳'}</td><td>${esc(e.time||'')}</td></tr>`;});
      document.getElementById('nl-zap-log').innerHTML=html+'</tbody></table>';
    });
  }
  document.getElementById('nl-zap-save').addEventListener('click',function(){
    const url=document.getElementById('nl-zap-out').value.trim();
    const secret=document.getElementById('nl-zap-secret').value.trim();
    api('/zapier/register',{method:'POST',body:JSON.stringify({webhook_url:url,secret:secret||undefined})}).then(()=>{this.textContent='✅ Saved';setTimeout(()=>this.textContent='💾 Save',2000);loadLog();});
  });
  document.getElementById('nl-zap-test').addEventListener('click',function(){
    api('/zapier/inbound',{method:'POST',body:JSON.stringify({task_type:'zapier_task',prompt:'Test ping from Neuro Link — '+new Date().toISOString()})}).then(d=>alert('✅ Test queued! ID: '+d.request_id));
  });
  loadLog();
})();
</script>
</div>
