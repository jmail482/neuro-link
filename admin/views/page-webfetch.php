<?php defined('ABSPATH')||exit; ?>
<div class="wrap nl-page">
<h1>🌐 Web Fetch</h1>
<div class="nl-card" style="max-width:700px">
  <label class="nl-label">URL to Fetch</label>
  <input id="nl-wf-url" type="url" class="nl-input" placeholder="https://example.com/article" style="width:100%">
  <label class="nl-label" style="margin-top:12px">Optional LLM Prompt (leave blank to just fetch text)</label>
  <textarea id="nl-wf-prompt" class="nl-input" rows="2" placeholder="Summarize this page in 3 bullet points…" style="width:100%"></textarea>
  <button class="nl-btn" id="nl-wf-go" style="margin-top:12px">🔍 Fetch</button>
</div>
<div id="nl-wf-result" style="display:none" class="nl-card" style="margin-top:16px;max-width:700px">
  <div id="nl-wf-content" style="white-space:pre-wrap;font-size:13px;line-height:1.6;max-height:500px;overflow-y:auto"></div>
</div>
<script>
(function(){
  document.getElementById('nl-wf-go').addEventListener('click',function(){
    const url=document.getElementById('nl-wf-url').value.trim();
    const prompt=document.getElementById('nl-wf-prompt').value.trim();
    if(!url){alert('Enter a URL.');return;}
    this.disabled=true;this.textContent='⏳ Fetching…';
    const result=document.getElementById('nl-wf-result');
    const content=document.getElementById('nl-wf-content');
    result.style.display='none';
    fetch(NeuroLink.root+'/web-fetch',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':NeuroLink.nonce},body:JSON.stringify({url,prompt})})
      .then(r=>r.json()).then(d=>{
        content.textContent=d.text||d.error||JSON.stringify(d);
        result.style.display='block';
        this.disabled=false;this.textContent='🔍 Fetch';
      }).catch(e=>{content.textContent='Error: '+e.message;result.style.display='block';this.disabled=false;this.textContent='🔍 Fetch';});
  });
})();
</script>
</div>
