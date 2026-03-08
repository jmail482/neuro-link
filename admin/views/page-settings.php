<?php
defined( 'ABSPATH' ) || exit;
$s     = \NeuroLink\Settings::all();
$saved = false;

if ( isset( $_POST['nl_save_settings'] ) && check_admin_referer( 'nl_settings' ) ) {
    $fields = [
        'ollama_url', 'ollama_model',
        'openai_api_key', 'openai_model',
        'anthropic_api_key', 'anthropic_model',
        'groq_api_key', 'groq_model',
        'github_token',
        'freegpt35_url',
        'cron_interval', 'lease_seconds', 'batch_size',
    ];
    foreach ( $fields as $f ) {
        if ( isset( $_POST[ $f ] ) ) \NeuroLink\Settings::set( $f, sanitize_text_field( $_POST[ $f ] ) );
    }
    // Freegpt35 enabled toggle
    \NeuroLink\Settings::set( 'freegpt35_enabled', isset( $_POST['freegpt35_enabled'] ) ? '1' : '0' );
    // Enabled providers
    $enabled = isset( $_POST['providers_enabled'] ) ? array_map( 'sanitize_key', (array) $_POST['providers_enabled'] ) : [];
    \NeuroLink\Settings::set( 'providers_enabled', $enabled );
    $s     = \NeuroLink\Settings::all();
    $saved = true;
}
$enabled = \NeuroLink\Settings::get_providers_enabled();
function nl_inp( $name, $type = 'text', $placeholder = '', $s = [] ) {
    $val = esc_attr( $s[ $name ] ?? '' );
    echo "<input name=\"{$name}\" type=\"{$type}\" value=\"{$val}\" placeholder=\"{$placeholder}\" style=\"width:100%;padding:7px 10px;border:1.5px solid #e5e5ea;border-radius:8px;font-size:13px\" />";
}
?>
<div class="wrap nl-page" style="max-width:720px">
<h1>⚙ Settings</h1>
<?php if ( $saved ) : ?>
  <div class="nl-notice">✅ Settings saved.</div>
<?php endif; ?>

<form method="post">
<?php wp_nonce_field( 'nl_settings' ); ?>

<div class="nl-card">
  <h3>🦙 Ollama (Local)</h3>
  <label class="nl-label">URL</label><?php nl_inp('ollama_url','text','http://localhost:11434',$s); ?>
  <label class="nl-label" style="margin-top:10px">Default Model</label><?php nl_inp('ollama_model','text','qwen2.5:7b',$s); ?>
</div>

<div class="nl-card">
  <h3>🤖 OpenAI</h3>
  <label class="nl-label">API Key</label><?php nl_inp('openai_api_key','password','sk-...',$s); ?>
  <label class="nl-label" style="margin-top:10px">Model</label><?php nl_inp('openai_model','text','gpt-4o-mini',$s); ?>
</div>

<div class="nl-card">
  <h3>🧠 Anthropic</h3>
  <label class="nl-label">API Key</label><?php nl_inp('anthropic_api_key','password','sk-ant-...',$s); ?>
  <label class="nl-label" style="margin-top:10px">Model</label><?php nl_inp('anthropic_model','text','claude-haiku-4-5-20251001',$s); ?>
</div>

<div class="nl-card">
  <h3>⚡ Groq</h3>
  <label class="nl-label">API Key</label><?php nl_inp('groq_api_key','password','gsk_...',$s); ?>
  <label class="nl-label" style="margin-top:10px">Model</label><?php nl_inp('groq_model','text','llama-3.3-70b-versatile',$s); ?>
</div>

<div class="nl-card">
  <h3>🐙 GitHub</h3>
  <label class="nl-label">Personal Access Token (for tool-calling on private repos)</label>
  <?php nl_inp('github_token','password','ghp_...',$s); ?>
  <p style="font-size:11px;color:#86868b;margin:4px 0 0">Needs <code>repo</code> scope. Leave blank for public repos.</p>
</div>

<div class="nl-card">
  <h3>🧪 FreeGPT35 (Dev Only)</h3>
  <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:10px">
    <input type="checkbox" name="freegpt35_enabled" value="1" <?php checked( !empty($s['freegpt35_enabled']) ); ?> style="accent-color:#007aff;width:14px;height:14px">
    Enable FreeGPT35 (requires local server running)
  </label>
  <label class="nl-label">Local Server URL</label>
  <?php nl_inp('freegpt35_url','text','http://localhost:3040',$s); ?>
  <p style="font-size:11px;color:#ff9500;margin:6px 0 0">⚠️ DEV ONLY — never enable in production. Auto-disabled if reliability drops below 50%.</p>
</div>

<div class="nl-card">
  <h3>⏱ Worker / Queue</h3>
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
    <div>
      <label class="nl-label">Cron Interval (seconds)</label>
      <?php nl_inp('cron_interval','number','',$s ?: ['cron_interval'=>60]); ?>
      <p style="font-size:11px;color:#86868b;margin:4px 0 0">Min 30. Default 60.</p>
    </div>
    <div>
      <label class="nl-label">Lease Duration (seconds)</label>
      <?php nl_inp('lease_seconds','number','',$s ?: ['lease_seconds'=>120]); ?>
    </div>
    <div>
      <label class="nl-label">Batch Size</label>
      <?php nl_inp('batch_size','number','',$s ?: ['batch_size'=>5]); ?>
    </div>
  </div>
</div>

<div class="nl-card">
  <h3>✅ Enabled Providers</h3>
  <?php foreach ( [ 'ollama'=>'🦙 Ollama', 'openai'=>'🤖 OpenAI', 'anthropic'=>'🧠 Anthropic', 'groq'=>'⚡ Groq' ] as $id => $label ) : ?>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;font-size:13px;cursor:pointer">
      <input type="checkbox" name="providers_enabled[]" value="<?php echo esc_attr($id); ?>"
        <?php checked( in_array($id, $enabled, true) ); ?> style="accent-color:#007aff;width:14px;height:14px" />
      <?php echo esc_html($label); ?>
    </label>
  <?php endforeach; ?>
</div>

<div style="margin-top:8px">
  <input type="submit" name="nl_save_settings" value="💾 Save Settings"
    style="padding:10px 24px;background:#007aff;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit" />
</div>

</form>
</div>
