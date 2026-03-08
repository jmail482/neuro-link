<?php defined('ABSPATH')||exit; ?>
<div class="wrap nl-page">
<h1>🔒 Permissions</h1>
<p class="nl-muted">Controls which WordPress roles can access Neuro Link features. Currently all features require <code>manage_options</code> (Administrator). Future versions will support granular role assignment.</p>

<div class="nl-card" style="max-width:700px">
  <h3>Current Access Rules</h3>
  <table class="nl-table">
    <thead><tr><th>Feature</th><th>Required Capability</th><th>Who has it</th></tr></thead>
    <tbody>
      <tr><td>Admin Dashboard (all pages)</td><td><code>manage_options</code></td><td>Administrator</td></tr>
      <tr><td>REST API (session auth)</td><td><code>manage_options</code></td><td>Administrator</td></tr>
      <tr><td>REST API (token auth)</td><td>Valid <code>nlk_</code> token</td><td>Any external client with token</td></tr>
      <tr><td>Run Worker (AJAX)</td><td><code>manage_options</code></td><td>Administrator</td></tr>
      <tr><td>Create/Revoke API Tokens</td><td><code>manage_options</code></td><td>Administrator</td></tr>
      <tr><td>Zapier Inbound Webhook</td><td>Shared secret or token</td><td>Zapier + authenticated clients</td></tr>
    </tbody>
  </table>
</div>

<div class="nl-card" style="max-width:700px">
  <h3>Capability Test</h3>
  <p class="nl-muted">Check whether the current user has a specific capability.</p>
  <div style="display:flex;gap:8px;align-items:center">
    <input id="nl-cap-input" type="text" class="nl-input" placeholder="e.g. manage_options, edit_posts" style="flex:1">
    <button class="nl-btn" id="nl-cap-check">Check</button>
  </div>
  <div id="nl-cap-result" style="margin-top:10px;font-size:13px"></div>
</div>

<script>
(function(){
  document.getElementById('nl-cap-check').addEventListener('click', function(){
    const cap = document.getElementById('nl-cap-input').value.trim();
    if(!cap) return;
    fetch(NeuroLink.root+'/permissions/check?cap='+encodeURIComponent(cap),{headers:{'X-WP-Nonce':NeuroLink.nonce}})
      .then(r=>r.json()).then(d=>{
        const el=document.getElementById('nl-cap-result');
        el.textContent = d.has_cap
          ? '✅ Current user HAS capability: ' + cap
          : '❌ Current user does NOT have capability: ' + cap;
        el.style.color = d.has_cap ? '#34c759' : '#ff3b30';
      }).catch(()=>{
        document.getElementById('nl-cap-result').textContent='(Endpoint not registered yet — coming soon)';
      });
  });
})();
</script>
</div>
