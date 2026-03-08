/* global NeuroLink */
(function () {
  'use strict';

  const ROOT  = (NeuroLink.root || '').replace(/\/$/, '');
  const NONCE = NeuroLink.nonce;

  let providers      = {};
  let ollamaModels   = [];
  let activeProvider = 'ollama';
  let ghHistory      = [];
  let busy           = { chat: false, github: false, multi: false };

  // ── API ───────────────────────────────────────────────────────────────────
  async function api(path, body) {
    const res = await fetch(ROOT + path, {
      method: body ? 'POST' : 'GET',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || data.error || res.statusText);
    return data;
  }

  // ── Tab nav ───────────────────────────────────────────────────────────────
  document.querySelectorAll('.nl-nav-item').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.nl-nav-item').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.nl-tab').forEach(t => t.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
  });

  // ── Load providers + Ollama models ────────────────────────────────────────
  async function init() {
    try {
      [providers] = await Promise.all([ api('/providers') ]);
    } catch(e) {
      providers = {};
    }
    try {
      const r = await api('/ollama-models');
      ollamaModels = r.models || [];
    } catch(e) { ollamaModels = []; }

    renderProviderPills();
    renderMultiChecks();
    renderMultiGrid();
    populateModelDropdowns();
  }

  function renderProviderPills() {
    const el = document.getElementById('nl-chat-pills');
    el.innerHTML = '';
    const entries = Object.entries(providers);
    if (!entries.length) { el.innerHTML = '<span class="nl-loading-text" style="color:#ff3b30">No providers — check Settings</span>'; return; }
    entries.forEach(([id, p]) => {
      const btn = document.createElement('button');
      btn.className = 'nl-pill' + (id === activeProvider ? ' active' : '') + (!p.available ? ' unavailable' : '');
      btn.textContent = p.label;
      btn.title = p.available ? p.model : 'Not configured';
      if (p.available) btn.addEventListener('click', () => { activeProvider = id; renderProviderPills(); toggleModelDropdown(); });
      el.appendChild(btn);
    });
  }

  function toggleModelDropdown() {
    const sel = document.getElementById('nl-chat-model');
    if (activeProvider === 'ollama') { sel.style.display = ''; }
    else { sel.style.display = 'none'; }
  }

  function populateModelDropdowns() {
    ['nl-chat-model', 'nl-gh-model'].forEach(id => {
      const sel = document.getElementById(id);
      if (!sel) return;
      sel.innerHTML = '';
      if (!ollamaModels.length) { sel.innerHTML = '<option value="">No models found</option>'; return; }
      ollamaModels.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.name;
        opt.textContent = m.name + (m.size ? ' — ' + m.size : '') + (m.remote ? ' ☁' : '');
        if (m.name === NeuroLink.defaultModel) opt.selected = true;
        sel.appendChild(opt);
      });
    });
    // Set default selection to qwen2.5:7b if available
    ['nl-chat-model', 'nl-gh-model'].forEach(id => {
      const sel = document.getElementById(id);
      if (!sel) return;
      const preferred = ['qwen2.5:7b', 'qwen3-coder:30b', 'qwen2.5'];
      for (const p of preferred) {
        const opt = [...sel.options].find(o => o.value === p);
        if (opt) { sel.value = p; break; }
      }
    });
  }

  function renderMultiChecks() {
    const el = document.getElementById('nl-multi-checks');
    el.innerHTML = '';
    Object.entries(providers).forEach(([id, p]) => {
      const label = document.createElement('label');
      label.className = 'nl-multi-check';
      label.innerHTML = `<input type="checkbox" value="${id}" ${p.available ? 'checked' : 'disabled'}/><span>${p.label}</span>`;
      el.appendChild(label);
    });
  }

  function renderMultiGrid() {
    const grid = document.getElementById('nl-multi-grid');
    grid.innerHTML = '';
    const avail = Object.entries(providers).filter(([,p]) => p.available);
    if (!avail.length) { grid.innerHTML = '<div style="padding:20px;color:#86868b;font-size:13px">No available providers.</div>'; return; }
    avail.forEach(([id, p]) => {
      const col = document.createElement('div');
      col.className = 'nl-multi-col';
      col.innerHTML = `
        <div class="nl-multi-col-head">
          <span class="nl-multi-col-name">${p.label}</span>
          <span class="nl-multi-col-model">${p.model || ''}</span>
        </div>
        <div class="nl-multi-col-body" id="nl-col-body-${id}"></div>
        <div class="nl-multi-col-foot" id="nl-col-foot-${id}"></div>`;
      grid.appendChild(col);
    });
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function ts() { return new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
  function autoResize(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 160) + 'px'; }
  function setBusy(k, v) {
    busy[k] = v;
    const map = {chat:'nl-chat-send', github:'nl-gh-send', multi:'nl-multi-send'};
    const btn = document.getElementById(map[k]);
    if (btn) btn.disabled = v;
  }

  function appendMsg(container, role, text, meta = {}) {
    container.querySelector('.nl-empty')?.remove();
    const wrap = document.createElement('div');
    wrap.className = 'nl-msg ' + role;

    const toolBadges = (meta.tool_calls_log || []).map(t =>
      `<span class="nl-tool-badge ${t.ok?'ok':'fail'}">⚙ ${esc(t.tool)}</span>`
    ).join('');

    const providerLabel = meta.provider ? `<span class="nl-msg-provider">${esc(meta.provider)}</span>` : '';
    const latLabel = meta.latency_ms ? `<span class="nl-msg-lat">${meta.latency_ms}ms</span>` : '';

    wrap.innerHTML = `
      <div class="nl-msg-meta">
        <span class="nl-msg-role ${role}">${role === 'user' ? 'You' : 'Assistant'}</span>
        ${providerLabel}
        <span class="nl-msg-time">${ts()}</span>
        ${latLabel}
      </div>
      ${toolBadges ? `<div class="nl-tool-badges">${toolBadges}</div>` : ''}
      <div class="nl-msg-body">${esc(text)}</div>`;
    container.appendChild(wrap);
    container.scrollTop = container.scrollHeight;
  }

  function appendThinking(container) {
    container.querySelector('.nl-empty')?.remove();
    const wrap = document.createElement('div');
    wrap.className = 'nl-msg assistant nl-thinking-wrap';
    wrap.innerHTML = `<div class="nl-msg-meta"><span class="nl-msg-role assistant">Thinking</span></div>
      <div class="nl-msg-body nl-thinking"><span class="nl-dots"><span>●</span><span>●</span><span>●</span></span></div>`;
    container.appendChild(wrap);
    container.scrollTop = container.scrollHeight;
    return wrap;
  }

  // ── CHAT TAB ──────────────────────────────────────────────────────────────
  const chatInput = document.getElementById('nl-chat-input');
  const chatSend  = document.getElementById('nl-chat-send');
  const chatMsgs  = document.getElementById('nl-chat-messages');

  chatInput.addEventListener('input', () => autoResize(chatInput));
  chatInput.addEventListener('keydown', e => { if (e.key==='Enter'&&!e.shiftKey){e.preventDefault();chatSend.click();} });

  chatSend.addEventListener('click', async () => {
    const text = chatInput.value.trim();
    if (!text || busy.chat) return;
    chatInput.value = ''; autoResize(chatInput);
    setBusy('chat', true);
    appendMsg(chatMsgs, 'user', text);
    const thinking = appendThinking(chatMsgs);
    try {
      const model = document.getElementById('nl-chat-model')?.value || '';
      const data = await api('/chat', { input: text, provider: activeProvider, model });
      thinking.remove();
      if (data.success) appendMsg(chatMsgs, 'assistant', data.text, { provider: data.model || activeProvider, latency_ms: data.latency_ms });
      else appendMsg(chatMsgs, 'error', data.error || data.error_message || 'Error');
    } catch(e) { thinking.remove(); appendMsg(chatMsgs, 'error', e.message); }
    setBusy('chat', false);
  });

  // ── GITHUB CHAT TAB ───────────────────────────────────────────────────────
  const ghInput  = document.getElementById('nl-gh-input');
  const ghSend   = document.getElementById('nl-gh-send');
  const ghMsgs   = document.getElementById('nl-gh-messages');
  const ghSaveT  = document.getElementById('nl-gh-token-save');

  ghInput.addEventListener('input', () => autoResize(ghInput));
  ghInput.addEventListener('keydown', e => { if (e.key==='Enter'&&!e.shiftKey){e.preventDefault();ghSend.click();} });

  ghSaveT.addEventListener('click', async () => {
    const token = document.getElementById('nl-gh-token').value.trim();
    if (!token) return;
    try {
      await api('/settings', { github_token: token });
      ghSaveT.textContent = 'Saved ✓';
      setTimeout(() => { ghSaveT.textContent = 'Save Token'; }, 2000);
    } catch(e) { alert('Save failed: ' + e.message); }
  });

  ghSend.addEventListener('click', async () => {
    const text = ghInput.value.trim();
    if (!text || busy.github) return;
    ghInput.value = ''; autoResize(ghInput);
    setBusy('github', true);
    appendMsg(ghMsgs, 'user', text);
    const thinking = appendThinking(ghMsgs);
    ghHistory.push({ role: 'user', content: text });
    try {
      const model = document.getElementById('nl-gh-model')?.value || '';
      const data = await api('/github-chat', { messages: ghHistory, model });
      thinking.remove();
      if (data.success) {
        ghHistory.push({ role: 'assistant', content: data.text });
        appendMsg(ghMsgs, 'assistant', data.text, { provider: model || 'qwen2.5:7b', latency_ms: data.latency_ms, tool_calls_log: data.tool_calls_log });
      } else {
        appendMsg(ghMsgs, 'error', data.error_message || data.error || 'Error');
        ghHistory.pop();
      }
    } catch(e) { thinking.remove(); appendMsg(ghMsgs, 'error', e.message); ghHistory.pop(); }
    setBusy('github', false);
  });

  // ── MULTI TAB ─────────────────────────────────────────────────────────────
  const multiInput = document.getElementById('nl-multi-input');
  const multiSend  = document.getElementById('nl-multi-send');

  multiInput.addEventListener('input', () => autoResize(multiInput));
  multiInput.addEventListener('keydown', e => { if (e.key==='Enter'&&!e.shiftKey){e.preventDefault();multiSend.click();} });

  multiSend.addEventListener('click', async () => {
    const text = multiInput.value.trim();
    if (!text || busy.multi) return;
    const selected = [...document.querySelectorAll('#nl-multi-checks input:checked')].map(c => c.value);
    if (!selected.length) { alert('Select at least one provider.'); return; }
    multiInput.value = ''; autoResize(multiInput);
    setBusy('multi', true);
    selected.forEach(id => {
      const b = document.getElementById('nl-col-body-' + id);
      if (b) { b.className = 'nl-multi-col-body nl-thinking'; b.innerHTML = '<span class="nl-dots"><span>●</span><span>●</span><span>●</span></span>'; }
    });
    try {
      const data = await api('/multi-chat', { input: text, providers: selected });
      Object.entries(data.results || {}).forEach(([id, r]) => {
        const b = document.getElementById('nl-col-body-' + id);
        const f = document.getElementById('nl-col-foot-' + id);
        if (!b) return;
        b.className = 'nl-multi-col-body' + (!r.success ? ' nl-error' : '');
        b.textContent = r.success ? r.text : (r.error || r.error_message || 'Error');
        if (f) f.textContent = r.latency_ms ? r.latency_ms + 'ms' : '';
      });
    } catch(e) {
      selected.forEach(id => { const b = document.getElementById('nl-col-body-'+id); if(b){b.className='nl-multi-col-body nl-error';b.textContent=e.message;} });
    }
    setBusy('multi', false);
  });

  // ── Init ──────────────────────────────────────────────────────────────────
  init();

})();
