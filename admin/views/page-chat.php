<?php
/**
 * Neuro Link — Chat GUI
 * 6 provider boxes, full data flow control, rolling backup
 */
namespace NeuroLink;
defined( 'ABSPATH' ) || exit;

$rest_url  = esc_url( rest_url( 'neuro-link/v1/chat' ) );
$rest_multi = esc_url( rest_url( 'neuro-link/v1/multi-chat' ) );
$nonce     = wp_create_nonce( 'wp_rest' );
// Real Ollama models — zero budget build
$providers = [
    'qwen2.5:7b'       => 'Qwen 2.5 7B',
    'phi3:mini'        => 'Phi3 Mini',
    'gemma3:4b'        => 'Gemma3 4B',
    'qwen3:4b'         => 'Qwen3 4B',
    'deepseek-r1:8b'   => 'DeepSeek R1',
    'gemma3:27b'       => 'Gemma3 27B',
];
// Pass model as provider key — REST handler will use it as the model param
$rest_url = esc_url( rest_url( 'neuro-link/v1/chat' ) );
?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:#1a1a1a}
.nl-wrap{padding:16px;background:#1e1e1e;min-height:100vh;font-family:-apple-system,"SF Pro Text",sans-serif;color:#e8e8e8}
.nl-top{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.nl-title{font-size:16px;font-weight:700;color:#fff;flex:1}
.nl-prompt-wrap{background:#2a2a2a;border:1px solid #3a3a3a;border-radius:8px;padding:12px;margin-bottom:12px}
.nl-prompt-wrap h3{font-size:10px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
#nl-prompt{width:100%;min-height:80px;background:#1a1a1a;border:1px solid #3a3a3a;border-radius:6px;padding:10px;font-size:13px;color:#e8e8e8;resize:vertical;outline:none;font-family:inherit;line-height:1.5}
#nl-prompt:focus{border-color:#0a84ff}
.nl-controls{display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap}
.nl-controls label{font-size:12px;color:#888;font-weight:600}
/* provider toggle buttons */
.nl-llm-btn{padding:5px 14px;border-radius:20px;border:1.5px solid #3a3a3a;background:#2a2a2a;font-size:12px;font-weight:500;cursor:pointer;color:#aaa;transition:all .15s}
.nl-llm-btn.on{border-color:#0a84ff;background:#0a84ff;color:#fff}
.nl-llm-btn:hover:not(.on){border-color:#666;color:#fff}
/* action buttons */
.nl-btn{padding:7px 18px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .15s}
.nl-btn-send{background:#0a84ff;color:#fff}
.nl-btn-send:hover{background:#0070e0}
.nl-btn-send:disabled{opacity:.4;cursor:not-allowed}
.nl-btn-clear-all{background:#3a3a3a;color:#ccc}
.nl-btn-clear-all:hover{background:#4a4a4a}
.nl-btn-pipe{background:#30d158;color:#000;font-weight:700}
.nl-btn-pipe:hover{background:#28b848}
.nl-btn-backup{background:#ff9f0a;color:#000}
.nl-btn-backup:hover{background:#e08a00}
/* 6-box grid */
.nl-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px}
@media(max-width:1100px){.nl-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){.nl-grid{grid-template-columns:1fr}}
.nl-box{background:#2a2a2a;border:2px solid #3a3a3a;border-radius:8px;display:flex;flex-direction:column;min-height:260px;transition:border-color .2s}
.nl-box.running{border-color:#0a84ff}
.nl-box.done{border-color:#30d158}
.nl-box.error{border-color:#ff453a}
.nl-box.selected{box-shadow:0 0 0 2px #ff9f0a}
.nl-box-head{padding:8px 12px;background:#333;border-radius:6px 6px 0 0;display:flex;align-items:center;gap:8px}
.nl-box-name{font-size:13px;font-weight:700;color:#fff;flex:1}
.nl-box-status{font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;display:none}
.running .nl-box-status{display:inline;background:#003d99;color:#6eb3ff}
.done .nl-box-status{display:inline;background:#003d20;color:#30d158}
.error .nl-box-status{display:inline;background:#4a0000;color:#ff453a}
.nl-box-select{width:14px;height:14px;cursor:pointer;accent-color:#ff9f0a}
.nl-box-body{flex:1;padding:10px 12px;overflow-y:auto;font-size:12.5px;line-height:1.6;color:#e8e8e8;word-break:break-word}
.nl-box-body em{color:#666;font-style:normal}
.nl-box-body pre{background:#111;color:#e8e8e8;padding:8px;border-radius:4px;overflow-x:auto;font-size:11px;white-space:pre-wrap;margin:4px 0}
.nl-box-foot{padding:7px 10px;border-top:1px solid #3a3a3a;background:#252525;border-radius:0 0 6px 6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.nl-box-timing{font-size:11px;color:#666;margin-right:auto}
.nl-ft-btn{padding:4px 12px;border-radius:4px;border:1px solid #3a3a3a;background:#333;font-size:11px;cursor:pointer;color:#ccc;transition:all .12s}
.nl-ft-btn:hover{background:#444;color:#fff}
.nl-ft-btn.blue{border-color:#0a84ff;color:#0a84ff}
.nl-ft-btn.green{border-color:#30d158;color:#30d158}
.nl-ft-btn.red{border-color:#ff453a;color:#ff453a}
/* pipe/route section */
.nl-route-wrap{background:#2a2a2a;border:1px solid #3a3a3a;border-radius:8px;padding:12px;margin-bottom:12px}
.nl-route-wrap h3{font-size:10px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.nl-route-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.nl-sel{background:#1a1a1a;border:1px solid #3a3a3a;border-radius:5px;padding:5px 8px;font-size:12px;color:#e8e8e8;min-width:110px}
.nl-arrow{color:#888;font-weight:700;font-size:13px}
/* backup log */
.nl-backup-log{background:#1a1a1a;border:1px solid #3a3a3a;border-radius:5px;padding:8px;max-height:80px;overflow-y:auto;font-size:11px;color:#666;margin-top:8px;font-family:monospace}
</style>

<div class="nl-wrap">
  <div class="nl-title">⚡ Neuro Link — Multi-LLM Control</div>

  <!-- PROMPT -->
  <div class="nl-prompt-wrap">
    <h3>Prompt</h3>
    <textarea id="nl-prompt" placeholder="Type your prompt… (Ctrl+Enter to send)"></textarea>
    <div class="nl-controls">
      <label>Send to:</label>
      <?php foreach ( $providers as $id => $label ) : ?>
      <button class="nl-llm-btn on" data-provider="<?php echo esc_attr($id); ?>" onclick="NL.toggle(this)"><?php echo esc_html($label); ?></button>
      <?php endforeach; ?>
      <button class="nl-btn nl-btn-send" id="nl-send-all" onclick="NL.sendAll()">▶ Send All</button>
      <button class="nl-btn nl-btn-clear-all" onclick="NL.clearAll()">⌫ Clear All</button>
      <button class="nl-btn nl-btn-backup" onclick="NL.backup()">💾 Backup</button>
    </div>
  </div>

  <!-- PIPE / ROUTE -->
  <div class="nl-route-wrap">
    <h3>⚡ Route — pipe outputs into a summarizer</h3>
    <div class="nl-route-row">
      <label style="font-size:12px;color:#888">From:</label>
      <select class="nl-sel" id="route-a">
        <option value="">— LLM A —</option>
        <?php foreach ( $providers as $id => $label ) : ?>
        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></option>
        <?php endforeach; ?>
      </select>
      <span class="nl-arrow">+</span>
      <select class="nl-sel" id="route-b">
        <option value="">— LLM B (opt) —</option>
        <?php foreach ( $providers as $id => $label ) : ?>
        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></option>
        <?php endforeach; ?>
      </select>
      <span class="nl-arrow">→ summarize with:</span>
      <select class="nl-sel" id="route-dest">
        <?php foreach ( $providers as $id => $label ) : ?>
        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></option>
        <?php endforeach; ?>
      </select>
      <button class="nl-btn nl-btn-pipe" onclick="NL.route()">▶ Run Summary</button>
      <button class="nl-btn nl-btn-send" style="background:#5e5ce6" onclick="NL.pipeSelected()">▶ Pipe Selected → Prompt</button>
    </div>
    <div id="nl-backup-log" class="nl-backup-log">Backup log ready.</div>
  </div>

  <!-- 6 OUTPUT BOXES -->
  <div class="nl-grid" id="nl-grid">
    <?php foreach ( $providers as $id => $label ) : ?>
    <div class="nl-box" id="box-<?php echo esc_attr($id); ?>">
      <div class="nl-box-head">
        <input type="checkbox" class="nl-box-select" id="sel-<?php echo esc_attr($id); ?>" title="Select for pipe">
        <span class="nl-box-name"><?php echo esc_html($label); ?></span>
        <span class="nl-box-status" id="status-<?php echo esc_attr($id); ?>">●</span>
      </div>
      <div class="nl-box-body" id="out-<?php echo esc_attr($id); ?>"><em>Waiting…</em></div>
      <div class="nl-box-foot">
        <span class="nl-box-timing" id="timing-<?php echo esc_attr($id); ?>"></span>
        <button class="nl-ft-btn green" onclick="NL.useAsInput('<?php echo esc_attr($id); ?>')">↑ Use as Input</button>
        <button class="nl-ft-btn blue" onclick="NL.copyOut('<?php echo esc_attr($id); ?>')">⎘ Copy</button>
        <button class="nl-ft-btn red" onclick="NL.clearBox('<?php echo esc_attr($id); ?>')">✕ Clear</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function(){
'use strict';
var REST   = <?php echo json_encode($rest_url); ?>;
var NONCE  = <?php echo json_encode($nonce); ?>;
var BACKUP = [];
var MAX_BACKUPS = 20;

var markedReady = false;
(function(){var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/marked@9/marked.min.js';s.onload=function(){markedReady=true;};document.head.appendChild(s);})();

function render(t){
  if(markedReady&&window.marked){try{return marked.parse(t);}catch(e){}}
  return '<pre style="white-space:pre-wrap">'+t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</pre>';
}

function setBox(pid, state, html, ms){
  var box=document.getElementById('box-'+pid);
  var st=document.getElementById('status-'+pid);
  var out=document.getElementById('out-'+pid);
  var tim=document.getElementById('timing-'+pid);
  box.className='nl-box'+(state?' '+state:'');
  st.textContent=state==='running'?'Running…':state==='done'?'Done':state==='error'?'Error':'●';
  if(html!==null)out.innerHTML=html;
  if(ms!==undefined&&ms!==null)tim.textContent=ms?(ms/1000).toFixed(2)+'s':'';
}

function callLLM(pid, prompt, onDone, onErr){
  var t0=Date.now();
  setBox(pid,'running','<em>Thinking…</em>',null);
  fetch(REST,{
    method:'POST',
    headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE},
    body:JSON.stringify({input:prompt,provider:pid})
  })
  .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
  .then(function(d){
    var ms=Date.now()-t0;
    var txt=d.text||d.message||JSON.stringify(d,null,2);
    setBox(pid,'done',render(txt),ms);
    if(onDone)onDone(txt,ms);
  })
  .catch(function(e){
    setBox(pid,'error','<span style="color:#ff453a">Error: '+e.message+'</span>',null);
    if(onErr)onErr(e);
  });
}

function logBackup(msg){
  var log=document.getElementById('nl-backup-log');
  var ts=new Date().toLocaleTimeString();
  log.innerHTML='['+ts+'] '+msg+'<br>'+log.innerHTML;
}

window.NL={
  toggle:function(btn){btn.classList.toggle('on');},

  getTargets:function(){
    return Array.from(document.querySelectorAll('.nl-llm-btn.on')).map(function(b){return b.dataset.provider;});
  },

  sendAll:function(){
    var prompt=document.getElementById('nl-prompt').value.trim();
    if(!prompt){document.getElementById('nl-prompt').focus();return;}
    var targets=NL.getTargets();
    if(!targets.length){alert('Select at least one provider.');return;}
    // auto-backup before send
    NL.backup();
    var sendBtn=document.getElementById('nl-send-all');
    sendBtn.disabled=true;
    var done=0;
    function fin(){done++;if(done>=targets.length)sendBtn.disabled=false;}
    targets.forEach(function(pid){callLLM(pid,prompt,fin,fin);});
  },

  clearAll:function(){
    <?php foreach(array_keys($providers) as $id): ?>
    NL.clearBox('<?php echo $id; ?>');
    <?php endforeach; ?>
  },

  clearBox:function(pid){
    setBox(pid,'','<em>Waiting…</em>',null);
    document.getElementById('timing-'+pid).textContent='';
  },

  useAsInput:function(pid){
    var txt=document.getElementById('out-'+pid).innerText.trim();
    if(!txt||txt==='Waiting…')return;
    document.getElementById('nl-prompt').value=txt;
    document.getElementById('nl-prompt').focus();
    window.scrollTo({top:0,behavior:'smooth'});
  },

  copyOut:function(pid){
    var txt=document.getElementById('out-'+pid).innerText.trim();
    if(!txt||txt==='Waiting…')return;
    navigator.clipboard.writeText(txt).then(function(){
      var btn=event.target;var orig=btn.textContent;
      btn.textContent='Copied!';setTimeout(function(){btn.textContent=orig;},1500);
    });
  },

  pipeSelected:function(){
    var parts=[];
    document.querySelectorAll('.nl-box-select:checked').forEach(function(cb){
      var pid=cb.id.replace('sel-','');
      var txt=document.getElementById('out-'+pid).innerText.trim();
      if(txt&&txt!=='Waiting…')parts.push('['+pid+']:\n'+txt);
    });
    if(!parts.length){alert('Check at least one output box first.');return;}
    document.getElementById('nl-prompt').value=parts.join('\n\n');
    document.getElementById('nl-prompt').focus();
    window.scrollTo({top:0,behavior:'smooth'});
  },

  route:function(){
    var srcA=document.getElementById('route-a').value;
    var srcB=document.getElementById('route-b').value;
    var dest=document.getElementById('route-dest').value;
    if(!srcA){alert('Pick at least one source LLM.');return;}
    var parts=[];
    var tA=document.getElementById('out-'+srcA).innerText.trim();
    if(!tA||tA==='Waiting…'){alert(srcA+' has no output yet.');return;}
    parts.push('['+srcA+']:\n'+tA);
    if(srcB&&srcB!==srcA){
      var tB=document.getElementById('out-'+srcB).innerText.trim();
      if(!tB||tB==='Waiting…'){alert(srcB+' has no output yet.');return;}
      parts.push('['+srcB+']:\n'+tB);
    }
    var sp='Synthesize these AI responses into one clear concise answer:\n\n'+parts.join('\n\n');
    callLLM(dest,sp,null,null);
  },

  backup:function(){
    var state={ts:new Date().toISOString(),boxes:{}};
    <?php foreach(array_keys($providers) as $id): ?>
    state.boxes['<?php echo $id; ?>']={
      html:document.getElementById('out-<?php echo $id; ?>').innerHTML,
      timing:document.getElementById('timing-<?php echo $id; ?>').textContent
    };
    <?php endforeach; ?>
    state.prompt=document.getElementById('nl-prompt').value;
    BACKUP.unshift(state);
    if(BACKUP.length>MAX_BACKUPS)BACKUP.pop();
    logBackup('Saved state #'+BACKUP.length+' — '+BACKUP.length+'/'+MAX_BACKUPS+' rolling backups');
  },

  restore:function(idx){
    var state=BACKUP[idx||0];
    if(!state){alert('No backup at index '+(idx||0));return;}
    document.getElementById('nl-prompt').value=state.prompt||'';
    Object.keys(state.boxes).forEach(function(pid){
      var b=state.boxes[pid];
      var out=document.getElementById('out-'+pid);
      var tim=document.getElementById('timing-'+pid);
      if(out)out.innerHTML=b.html||'<em>Waiting…</em>';
      if(tim)tim.textContent=b.timing||'';
    });
    logBackup('Restored state from '+state.ts);
  }
};

document.getElementById('nl-prompt').addEventListener('keydown',function(e){
  if(e.ctrlKey&&e.key==='Enter'){e.preventDefault();NL.sendAll();}
});

// auto-backup every 2 mins
setInterval(function(){NL.backup();},120000);

})();
</script>
