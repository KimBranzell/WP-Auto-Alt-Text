<div class="wrap">
  <h1>Auto Alt Text Options</h1>
  <form method="post" action="options.php">
    <?php
    settings_fields(SETTINGS_GROUP);
    do_settings_sections(SETTINGS_GROUP);
    ?>
    <table class="form-table">
      <tr valign="top">
        <th scope="row">API Key</th>
        <td><input type="text" name="auto_alt_text_api_key" value="<?php echo esc_attr(get_option('auto_alt_text_api_key')); ?>" /></td>
      </tr>
    </table>
    <?php submit_button(); ?>
  </form>
</div>