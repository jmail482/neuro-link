<?php defined('ABSPATH')||exit;
// Handle token creation / revocation
$message = '';
if ( isset($_POST['nl_create_token']) && check_admin_referer('nl_tokens') ) {
    $label  = sanitize_text_field($_POST['token_label'] ?? 'API Token');
    $scope  = sanitize_text_field($_POST['token_scope'] ?? 'read,write');
    $plain  = \NeuroLink\API_Auth::create_token($label, $scope);
    $message = '<div class="nl-notice success">Token created. <strong>Copy it now — it will never be shown again:</strong><br><code style="font-size:13px;padding:6px 10px;background:#f6f7f7;display:inline-block;margin-top:6px;border-radius:6px">'.$plain.'</code></div>';
}
if ( isset($_POST['nl_revoke_token']) && check_admin_referer('nl_tokens') ) {
    \NeuroLink\API_Auth::revoke((int)($_POST['token_id']??0));
    $message = '<div class="nl-notice">Token revoked.</div>';
}
$tokens = \NeuroLink\API_Auth::list_tokens();
?>
<div class="wrap nl-page">
<h1>🔑 API Tokens</h1>
<p class="nl-muted">Tokens allow external clients (desktop assistant, Zapier, scripts) to authenticate with the REST API. Send as <code>Authorization: Bearer &lt;token&gt;</code></p>
<?php echo $message; ?>
<div class="nl-card" style="max-width:500px;margin-bottom:16px">
  <h3>Create New Token</h3>
  <form method="post">
    <?php wp_nonce_field('nl_tokens'); ?>
    <label class="nl-label">Label</label>
    <input name="token_label" type="text" class="nl-input" style="width:100%;margin-bottom:10px" placeholder="e.g. Desktop Assistant" value="API Token">
    <label class="nl-label">Scope</label>
    <select name="token_scope" class="nl-input" style="width:100%;margin-bottom:12px">
      <option value="read,write">Read + Write</option>
      <option value="read">Read Only</option>
    </select>
    <button type="submit" name="nl_create_token" class="nl-btn">✚ Create Token</button>
  </form>
</div>
<div class="nl-card">
  <h3>Existing Tokens</h3>
  <?php if(!$tokens): ?>
    <p class="nl-muted">No tokens yet.</p>
  <?php else: ?>
  <table class="nl-table">
    <thead><tr><th>Label</th><th>Scope</th><th>Last Used</th><th>Created</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($tokens as $t): ?>
    <tr style="<?php echo $t['revoked']?'opacity:0.5':''; ?>">
      <td><?php echo esc_html($t['label']); ?></td>
      <td><code><?php echo esc_html($t['scope']); ?></code></td>
      <td><?php echo esc_html($t['last_used']??'Never'); ?></td>
      <td><?php echo esc_html($t['created_at']); ?></td>
      <td><?php echo $t['revoked']?'<span style="color:#ff3b30">Revoked</span>':'<span style="color:#34c759">Active</span>'; ?></td>
      <td><?php if(!$t['revoked']): ?>
        <form method="post" style="display:inline">
          <?php wp_nonce_field('nl_tokens'); ?>
          <input type="hidden" name="token_id" value="<?php echo (int)$t['id']; ?>">
          <button type="submit" name="nl_revoke_token" class="nl-btn-sm danger" onclick="return confirm('Revoke this token?')">Revoke</button>
        </form>
      <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
</div>
