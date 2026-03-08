<?php
/**
 * Chat GUI - Neuro Link
 * File: admin/views/page-chat.php
 */
namespace NeuroLink;
defined( 'ABSPATH' ) || exit;
$rest_chat = esc_url( rest_url( 'neuro-link/v1/chat' ) );
$rest_url  = $rest_chat;
$nonce     = wp_create_nonce( 'wp_rest' );
$providers = [ 'ollama' => 'Ollama', 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'groq' => 'Groq' ];
$defaults  = [ 1 => 'ollama', 2 => 'openai', 3 => 'anthropic', 4 => 'groq', 5 => 'ollama', 6 => 'openai' ];
?>
<style>
*,*::before,*::after{box-sizing:border-box}
.nl-wrap{padding:16px 20px;background:#f0f0f0;min-height:100vh;font-family:-apple-system,"SF Pro Text",Arial,sans-serif}
.nl-title{font-size:17px;font-weight:700;color:#1a1a1a;margin:0 0 14px}
.nl-section{background:#fff;border:1px solid #c8c8c8;border-radius:8px;padding:14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.07)}
.nl-section h3{margin:0 0 10px;font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px}
#nl-prompt{width:100%;min-height:90px;border:1px solid #d0d0d0;border-radius:6px;padding:9px 11px;font-size:13px;line-height:1.5;resize:vertical;outline:none;font-family:inherit}
#nl-prompt:focus{border-color:#1c7ce5;box-shadow:0 0 0 2px rgba(28,124,229,.15)}
.nl-row{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:10px}
.nl-row label{font-size:12px;font-weight:600;color:#555}
.nl-llm-btn{padding:5px 14px;border-radius:20px;border:1.5px solid #c0c0c0;background:#fff;font-size:12px;font-weight:500;cursor:pointer;color:#333;transition:all .15s}
.nl-llm-btn.on{border-color:#1c7ce5;background:#1c7ce5;color:#fff}
.nl-llm-btn:hover:not(.on){border-color:#888}
.nl-send-btn{margin-left:auto;padding:7px 22px;background:linear-gradient(180deg,#2b84f0,#1565cc);color:#fff;border:1px solid #1058b8;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer}
.nl-send-btn:disabled{opacity:.45;cursor:not-allowed}
.nl-sel{border:1px solid #c8c8c8;border-radius:5px;padding:4px 8px;font-size:12px;background:#fff;color:#333;min-width:110px}
.nl-arrow{color:#555;font-size:12px;font-weight:700;padding:0 2px}
.nl-route-btn{padding:6px 18px;background:linear-gradient(180deg,#34c759,#28a44a);color:#fff;border:1px solid #22943f;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer}
.nl-route-btn:disabled{opacity:.4;cursor:not-allowed}
.nl-hint{font-size:11px;color:#888;margin-top:6px}
.nl-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:1200px){.nl-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){.nl-grid{grid-template-columns:1fr}}
.nl-card{background:#fff;border:2px solid #d0d0d0;border-radius:8px;display:flex;flex-direction:column;min-height:280px;box-shadow:0 1px 3px rgba(0,0,0,.07);transition:border-color .15s}
.nl-card.running{border-color:#1c7ce5}
.nl-card.done{border-color:#34c759}
.nl-card.err{border-color:#ff3b30}
.nl-card-hd{padding:8px 12px;border-bottom:1px solid #e8e8e8;background:linear-gradient(180deg,#fafafa,#f2f2f2);border-radius:6px 6px 0 0;display:flex;align-items:center;justify-content:space-between}
.nl-card-title{font-size:13px;font-weight:700;color:#1a1a1a}
.nl-badge{padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;display:none}
.running .nl-badge{display:inline;background:#dbeafe;color:#1e40af;animation:pulse 1s infinite}
.done .nl-badge{display:inline;background:#d1fae5;color:#065f46}
.err .nl-badge{display:inline;background:#fee2e2;color:#991b1b}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.nl-card-body{flex:1;padding:10px 12px;overflow-y:auto;font-size:12.5px;line-height:1.6;color:#1a1a1a;word-break:break-word}
.nl-card-body em{color:#aaa;font-style:normal}
.nl-card-body pre{background:#1e1e1e;color:#e8e8e8;padding:8px;border-radius:4px;overflow-x:auto;font-size:11px;white-space:pre-wrap;margin:4px 0}
.nl-card-ft{padding:6px 10px;border-top:1px solid #eee;background:linear-gradient(180deg,#f8f8f8,#f0f0f0);border-radius:0 0 6px 6px;display:flex;align-items:center;gap:6px}
.nl-card-ft span{font-size:11px;color:#888;margin-right:auto}
.nl-ft-btn{padding:3px 10px;border-radius:4px;border:1px solid #c8c8c8;background:#fff;font-size:11px;cursor:pointer;color:#333}
.nl-ft-btn:hover{background:#f0f0f0}
.nl-ft-btn.blue{border-color:#1c7ce5;color:#1c7ce5;font-weight:600}
</style>

<div class="nl-wrap">
  <div class="nl-title">Neuro Link &mdash; Multi-LLM Chat</div>

  <!-- PROMPT + LLM TOGGLE BUTTONS -->
  <div class="nl-section">
    <h3>Your Prompt</h3>
    <textarea id="nl-prompt" placeholder="Type your prompt here... (Ctrl+Enter to send)"></textarea>
    <div class="nl-row">
      <label>Send to:</label>
      <?php foreach ( $providers as $id => $label ) : ?>
      <button class="nl-llm-btn on" data-provider="<?php echo esc_attr( $id ); ?>" onclick="NL.toggle(this)"><?php echo esc_html( $label ); ?></button>
      <?php endforeach; ?>
      <button class="nl-send-btn" id="nl-send-all" onclick="NL.sendAll()">&#9654; Send</button>
    </div>
  </div>

  <!-- ROUTING: pipe 1 or 2 LLM outputs into a summarizer -->
  <div class="nl-section">
    <h3>&#9889; Route &mdash; Pipe LLM Outputs into a Summarizer</h3>
    <div class="nl-row">
      <label>Take output from:</label>
      <select class="nl-sel" id="route-a">
        <option value="">-- LLM A --</option>
        <?php foreach ( $providers as $id => $label ) : ?><option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
      </select>
      <span class="nl-arrow">+</span>
      <select class="nl-sel" id="route-b">
        <option value="">-- LLM B (optional) --</option>
        <?php foreach ( $providers as $id => $label ) : ?><option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
      </select>
      <span class="nl-arrow">&#8594; summarize with:</span>
      <select class="nl-sel" id="route-dest">
        <?php foreach ( $providers as $id => $label ) : ?><option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
      </select>
      <button class="nl-route-btn" id="nl-route-btn" onclick="NL.route()">&#9654; Run Summary</button>
    </div>
    <div class="nl-hint">Or click <strong>Use as input</strong> on any output card to pipe it back into the prompt box.</div>
  </div>

  <!-- OUTPUT CARDS - one per LLM -->
  <div class="nl-grid" id="nl-grid">
    <?php foreach ( $providers as $id => $label ) : ?>
    <div class="nl-card" id="card-<?php echo esc_attr( $id ); ?>">
      <div class="nl-card-hd">
        <span class="nl-card-title"><?php echo esc_html( $label ); ?></span>
        <span class="nl-badge" id="badge-<?php echo esc_attr( $id ); ?>"></span>
      </div>
      <div class="nl-card-body" id="out-<?php echo esc_attr( $id ); ?>"><em>Waiting...</em></div>
      <div class="nl-card-ft">
        <span id="timing-<?php echo esc_attr( $id ); ?>"></span>
        <button class="nl-ft-btn blue" onclick="NL.useAsInput('<?php echo esc_attr( $id ); ?>')">Use as input</button>
        <button class="nl-ft-btn" onclick="NL.clearCard('<?php echo esc_attr( $id ); ?>')">Clear</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function(){
'use strict';
var REST  = <?php echo json_encode( $rest_chat ); ?>;
var NONCE = <?php echo json_encode( $nonce ); ?>;
var markedReady=false;
(function(){var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/marked@9/marked.min.js';s.onload=function(){markedReady=true;};document.head.appendChild(s);})();
function render(t){if(markedReady&&window.marked){try{return marked.parse(t);}catch(e){}}return '<pre>'+t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</pre>';}
function setCard(pid,state,html,ms){
  var card=document.getElementById('card-'+pid);
  var badge=document.getElementById('badge-'+pid);
  var out=document.getElementById('out-'+pid);
  var tim=document.getElementById('timing-'+pid);
  card.className='nl-card'+(state?' '+state:'');
  badge.textContent=state==='running'?'Running...':state==='done'?'Done':state==='err'?'Error':'';
  if(html!==null)out.innerHTML=html;
  if(ms!==undefined)tim.textContent=ms?(ms/1000).toFixed(2)+'s':'';
}
function callLLM(pid,prompt,onDone,onErr){
  var t0=Date.now();
  setCard(pid,'running','<em>Thinking...</em>',undefined);
  fetch(REST,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE},body:JSON.stringify({input:prompt,provider:pid})})
  .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
  .then(function(d){var ms=Date.now()-t0;var txt=d.text||d.message||JSON.stringify(d,null,2);setCard(pid,'done',render(txt),ms);if(onDone)onDone(txt,ms);})
  .catch(function(e){setCard(pid,'err','Error: '+e.message,0);if(onErr)onErr(e);});
}
window.NL={
  toggle:function(btn){btn.classList.toggle('on');},
  getTargets:function(){return Array.from(document.querySelectorAll('.nl-llm-btn.on')).map(function(b){return b.dataset.provider;});},
  sendAll:function(){
    var prompt=document.getElementById('nl-prompt').value.trim();
    if(!prompt){document.getElementById('nl-prompt').focus();return;}
    var targets=NL.getTargets();
    if(!targets.length){alert('Select at least one LLM.');return;}
    document.getElementById('nl-send-all').disabled=true;
    var done=0;
    function fin(){done++;if(done>=targets.length)document.getElementById('nl-send-all').disabled=false;}
    targets.forEach(function(pid){callLLM(pid,prompt,fin,fin);});
  },
  route:function(){
    var srcA=document.getElementById('route-a').value;
    var srcB=document.getElementById('route-b').value;
    var dest=document.getElementById('route-dest').value;
    if(!srcA){alert('Pick at least one source LLM.');return;}
    var parts=[];
    var tA=document.getElementById('out-'+srcA).innerText.trim();
    if(!tA||tA==='Waiting...'){alert(srcA+' has no output yet. Send a prompt first.');return;}
    parts.push('['+srcA+']:\n'+tA);
    if(srcB&&srcB!==srcA){var tB=document.getElementById('out-'+srcB).innerText.trim();if(!tB||tB==='Waiting...'){alert(srcB+' has no output yet.');return;}parts.push('['+srcB+']:\n'+tB);}
    var sp='Synthesize these AI responses into one clear concise summary:\n\n'+parts.join('\n\n');
    document.getElementById('nl-route-btn').disabled=true;
    callLLM(dest,sp,function(){document.getElementById('nl-route-btn').disabled=false;},function(){document.getElementById('nl-route-btn').disabled=false;});
  },
  useAsInput:function(pid){
    var txt=document.getElementById('out-'+pid).innerText.trim();
    if(!txt||txt==='Waiting...')return;
    document.getElementById('nl-prompt').value=txt;
    document.getElementById('nl-prompt').focus();
    window.scrollTo({top:0,behavior:'smooth'});
  },
  clearCard:function(pid){setCard(pid,'','<em>Waiting...</em>','');}
};
document.getElementById('nl-prompt').addEventListener('keydown',function(e){if(e.ctrlKey&&e.key==='Enter'){e.preventDefault();NL.sendAll();}});
})();
</script>

  <!-- Numbers toolbar -->
  <div class="nl-toolbar">
    <button class="nl-toolbar-btn">Insert</button>
    <button class="nl-toolbar-btn">Table</button>
    <div class="nl-toolbar-sep"></div>
    <button class="nl-toolbar-btn">Format</button>
    <div class="nl-toolbar-sep"></div>
    <button class="nl-toolbar-btn" onclick="NL.sendAll()">▶ Run All</button>
    <button class="nl-toolbar-btn" onclick="NL.clearAll()">⌫ Clear All</button>
    <span class="nl-toolbar-label">Neuro Link — Multi-LLM</span>
  </div>

  <!-- Sheet tabs -->
  <div class="nl-tabbar">
    <div class="nl-tab active">Sheet 1</div>
    <div class="nl-tab">Results</div>
    <div class="nl-tab">Logs</div>
  </div>

  <!-- Formula bar -->
  <div class="nl-formulabar">
    <span class="nl-cell-ref" id="nl-active-ref">A1</span>
    <span class="nl-fx-icon">fx</span>
    <input class="nl-formulabar-input" id="nl-fx-input" readonly placeholder="Select a cell…" />
  </div>

  <!-- Column headers + grid -->
  <div class="nl-canvas">
    <div class="nl-col-headers">
      <div class="nl-corner-cell"></div>
      <div class="nl-col-header">A</div>
      <div class="nl-col-header">B</div>
      <div class="nl-col-header">C</div>
    </div>

    <div class="nl-numbers-grid" id="nl-chat-grid">
      <!-- Row 1 -->
      <div class="nl-row-header">1</div>
      <?php foreach ([1,2,3] as $i): ?>
      <div class="nl-pane" data-pane="<?php echo $i; ?>" id="pane-<?php echo $i; ?>" tabindex="0"
           onclick="NL.focus(<?php echo $i; ?>)">
        <div class="nl-pane-header">
          <h3><?php echo ['A1','B1','C1'][$i-1]; ?></h3>
          <select class="nl-provider-select" data-pane="<?php echo $i; ?>" id="provider-<?php echo $i; ?>">
            <?php foreach ($providers as $key => $label): ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($defaults[$i], $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="nl-pane-content">
          <textarea class="nl-pane-input" id="input-<?php echo $i; ?>" placeholder="Prompt… (Ctrl+Enter)"></textarea>
          <div class="nl-pane-output" id="output-<?php echo $i; ?>">
            <span class="nl-status-badge running" style="display:none;">Running…</span>
            <span class="nl-status-badge completed" style="display:none;">Done</span>
            <span class="nl-status-badge error" style="display:none;">Error</span>
            <div class="nl-output-text"></div>
          </div>
        </div>
        <div class="nl-pane-footer">
          <span class="nl-timing" id="timing-<?php echo $i; ?>"></span>
          <button class="nl-button secondary" onclick="NL.clear(<?php echo $i; ?>)">Clear</button>
          <button class="nl-button" id="send-<?php echo $i; ?>" onclick="NL.send(<?php echo $i; ?>)">Send</button>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Row 2 -->
      <div class="nl-row-header">2</div>
      <?php foreach ([4,5,6] as $i): ?>
      <div class="nl-pane" data-pane="<?php echo $i; ?>" id="pane-<?php echo $i; ?>" tabindex="0"
           onclick="NL.focus(<?php echo $i; ?>)">
        <div class="nl-pane-header">
          <h3><?php echo ['A2','B2','C2'][$i-4]; ?></h3>
          <select class="nl-provider-select" data-pane="<?php echo $i; ?>" id="provider-<?php echo $i; ?>">
            <?php foreach ($providers as $key => $label): ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($defaults[$i], $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="nl-pane-content">
          <textarea class="nl-pane-input" id="input-<?php echo $i; ?>" placeholder="Prompt… (Ctrl+Enter)"></textarea>
          <div class="nl-pane-output" id="output-<?php echo $i; ?>">
            <span class="nl-status-badge running" style="display:none;">Running…</span>
            <span class="nl-status-badge completed" style="display:none;">Done</span>
            <span class="nl-status-badge error" style="display:none;">Error</span>
            <div class="nl-output-text"></div>
          </div>
        </div>
        <div class="nl-pane-footer">
          <span class="nl-timing" id="timing-<?php echo $i; ?>"></span>
          <button class="nl-button secondary" onclick="NL.clear(<?php echo $i; ?>)">Clear</button>
          <button class="nl-button" id="send-<?php echo $i; ?>" onclick="NL.send(<?php echo $i; ?>)">Send</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div><!-- /nl-numbers-grid -->
  </div><!-- /nl-canvas -->

  <!-- Numbers status bar -->
  <div class="nl-statusbar">
    <span id="nl-sb-cell">Cell: —</span>
    <span id="nl-sb-provider">Provider: —</span>
    <span id="nl-sb-status">Ready</span>
  </div>

</div>

<style>
<?php
$css_file = dirname(__FILE__) . '/../assets/numbers-theme.css';
if (file_exists($css_file)) {
    echo file_get_contents($css_file);
} else {
    echo '/* numbers-theme.css not found */';
}
?>
</style>

<script>
(function() {
  'use strict';

  var REST   = <?php echo json_encode($rest_url); ?>;
  var NONCE  = <?php echo json_encode($nonce); ?>;
  var STATE_KEY = 'nl_chat_state_v1';

  /* ---------- marked.js lazy load ---------- */
  var markedReady = false;
  (function() {
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/marked@9/marked.min.js';
    s.onload = function() { markedReady = true; };
    document.head.appendChild(s);
  })();

  function renderText(txt) {
    if (markedReady && window.marked) {
      try { return marked.parse(txt); } catch(e) {}
    }
    return '<pre style="white-space:pre-wrap;margin:0;">' +
      txt.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>';
  }

  /* ---------- core ---------- */
  window.NL = {

    send: function(pane) {
      var inp    = document.getElementById('input-' + pane);
      var out    = document.getElementById('output-' + pane);
      var sendBtn = document.getElementById('send-' + pane);
      var timing = document.getElementById('timing-' + pane);
      var provider = document.getElementById('provider-' + pane).value;
      var text   = inp.value.trim();
      if (!text) { inp.focus(); return; }

      /* UI: running state */
      out.querySelector('.nl-status-badge.running').style.display = 'inline-block';
      out.querySelector('.nl-status-badge.completed').style.display = 'none';
      out.querySelector('.nl-status-badge.error').style.display = 'none';
      out.querySelector('.nl-output-text').innerHTML = '<em style="color:#888;">Thinking…</em>';
      sendBtn.disabled = true;
      timing.textContent = '';
      var t0 = Date.now();

      var body = JSON.stringify({ input: text, provider: provider });

      fetch(REST, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': NONCE
        },
        body: body
      })
      .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function(data) {
        var ms = Date.now() - t0;
        var txt = data.text || data.message || JSON.stringify(data, null, 2);
        out.querySelector('.nl-output-text').innerHTML = renderText(txt);
        out.querySelector('.nl-status-badge.running').style.display = 'none';
        out.querySelector('.nl-status-badge.completed').style.display = 'inline-block';
        timing.textContent = (ms / 1000).toFixed(2) + 's';
        NL._save();
      })
      .catch(function(err) {
        out.querySelector('.nl-output-text').textContent = 'Error: ' + err.message;
        out.querySelector('.nl-status-badge.running').style.display = 'none';
        out.querySelector('.nl-status-badge.error').style.display = 'inline-block';
        timing.textContent = '';
      })
      .finally(function() {
        sendBtn.disabled = false;
      });
    },

    clear: function(pane) {
      document.getElementById('input-' + pane).value = '';
      var out = document.getElementById('output-' + pane);
      out.querySelector('.nl-output-text').innerHTML = '';
      out.querySelectorAll('.nl-status-badge').forEach(function(b) { b.style.display = 'none'; });
      document.getElementById('timing-' + pane).textContent = '';
      NL._save();
    },

    _save: function() {
      try {
        var state = {};
        for (var i = 1; i <= 6; i++) {
          state[i] = {
            provider : document.getElementById('provider-' + i).value,
            input    : document.getElementById('input-' + i).value,
            output   : document.getElementById('output-' + i).querySelector('.nl-output-text').innerHTML,
            timing   : document.getElementById('timing-' + i).textContent
          };
        }
        localStorage.setItem(STATE_KEY, JSON.stringify(state));
      } catch(e) {}
    },

    _load: function() {
      try {
        var raw = localStorage.getItem(STATE_KEY);
        if (!raw) return;
        var state = JSON.parse(raw);
        for (var i = 1; i <= 6; i++) {
          var s = state[i];
          if (!s) continue;
          if (s.provider) document.getElementById('provider-' + i).value = s.provider;
          if (s.input)    document.getElementById('input-'    + i).value = s.input;
          if (s.output)   document.getElementById('output-'   + i).querySelector('.nl-output-text').innerHTML = s.output;
          if (s.timing)   document.getElementById('timing-'   + i).textContent = s.timing;
          if (s.output) {
            document.getElementById('output-' + i).querySelector('.nl-status-badge.completed').style.display = 'inline-block';
          }
        }
      } catch(e) {}
    },

    _bindKeys: function() {
      document.querySelectorAll('.nl-pane-input').forEach(function(ta) {
        ta.addEventListener('keydown', function(e) {
          if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            var pane = parseInt(ta.id.replace('input-', ''), 10);
            NL.send(pane);
          }
        });
        ta.addEventListener('change', function() { NL._save(); });
      });
      document.querySelectorAll('.nl-provider-select').forEach(function(sel) {
        sel.addEventListener('change', function() { NL._save(); });
      });
    },

    init: function() {
      NL._load();
      NL._bindKeys();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', NL.init);
  } else {
    NL.init();
  }

})();
</script>