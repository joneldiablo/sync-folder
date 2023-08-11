<?php

/**
 * Plugin Name: Sync Folder
 * Description: Synchronizes a folder with the WordPress media library.
 * Version: 1.0
 * Author: joneldiablo
 */

// Admin Menu
function sync_folder_menu()
{
  add_options_page('Sync Folder', 'Sync Folder', 'manage_options', 'sync-folder', 'sync_folder_options');
}
add_action('admin_menu', 'sync_folder_menu');

function sync_folder_read_statistics()
{
  $folder_path = get_option('sync_folder_path', '');
  $file_types = get_option('sync_folder_file_types', 'jpg,jpeg,png,gif');

  $total_files_found = 0;
  $total_matching_files = 0;
  $folder = ABSPATH . trim($folder_path, '/') . '/';
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder));

  foreach ($iterator as $file) {
    if ($file->isFile()) {
      $total_files_found++;
      if (in_array($file->getExtension(), explode(',', $file_types))) {
        $total_matching_files++;
      }
    }
  }

  $args = array(
    'post_type'      => 'attachment',
    'posts_per_page' => -1,
  );
  $query = new WP_Query($args);

  $total_synced_files = 0;
  foreach ($query->posts as $post) {
    $file_path = get_attached_file($post->ID);
    if (strpos($file_path, $folder_path) !== false) {
      $total_synced_files++;
    }
  }

  update_option('sync_folder_total_files_found', $total_files_found);
  update_option('sync_folder_total_matching_files', $total_matching_files);
  update_option('sync_folder_total_synced_files', $total_synced_files);

  return array($total_files_found, $total_matching_files, $total_synced_files);
}

function is_variant($folder, $filename)
{
  $base_filename = pathinfo($filename, PATHINFO_FILENAME); // Obtiene el nombre del archivo sin la extensión.
  $files = glob($folder . $base_filename . '*'); // Busca archivos que comiencen con el nombre base.

  // Si encuentra más de un archivo que comience con el nombre base, asume que hay variantes.
  return count($files) > 1;
}

function find_attachment_by_url($url)
{
  global $wpdb;

  // Buscar el archivo adjunto en la base de datos por su URL.
  $attachment_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid LIKE %s AND post_type = 'attachment'", '%' . $wpdb->esc_like($url) . '%'));

  if ($attachment_id) {
    return get_post($attachment_id); // Si encuentra el archivo adjunto, devuelve el objeto del post.
  }

  return false; // Si no encuentra el archivo adjunto, devuelve false.
}

// Options Page
function sync_folder_options()
{
  if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
  }

  // Save Options
  if (isset($_POST['folder_path']) && isset($_POST['file_types']) && isset($_POST['author_id'])) {
    update_option('sync_folder_path', sanitize_text_field($_POST['folder_path']));
    update_option('sync_folder_file_types', sanitize_text_field($_POST['file_types']));
    update_option('sync_folder_author_id', intval($_POST['author_id']));
  }

  // Get Options
  $folder_path = get_option('sync_folder_path', '');
  $file_types = get_option('sync_folder_file_types', 'jpg,jpeg,png,gif');
  $author_id = get_option('sync_folder_author_id', 1);
  $total_files_found = get_option('sync_folder_total_files_found', false);
  $total_matching_files = get_option('sync_folder_total_matching_files', false);
  $total_synced_files = get_option('sync_folder_total_synced_files', false);

  $next_sync = wp_next_scheduled('sync_folder_cron_task');
  $next_sync_date = $next_sync ? date('Y-m-d H:i:s', $next_sync) : 'No scheduled';

  // If "read again" button clicked, calculate the statistics
  if (isset($_GET['read_again']) && $_GET['read_again'] == 'true') {
    sync_folder_read_statistics();
  }

  // Options Form
?>
  <div class="wrap">
    <h2>Sync Folder</h2>
    <form method="post" action="">
      <table class="form-table">
        <tr>
          <th scope="row">Folder Path (relative to WP installation):</th>
          <td><input type="text" name="folder_path" value="<?php echo $folder_path; ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row">File Types (comma separated):</th>
          <td><input type="text" name="file_types" value="<?php echo $file_types; ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row">User ID (owner of the files):</th>
          <td><input type="number" name="author_id" value="<?php echo $author_id; ?>" /></td>
        </tr>
      </table>
      <p class="submit">
        <input type="submit" class="button-primary" value="Save Changes" />
      </p>
    </form>
    <table class="form-table">
      <tr>
        <th scope="row">Total files found:</th>
        <td><?php echo $total_files_found; ?></td>
      </tr>
      <tr>
        <th scope="row">Files matching allowed extensions:</th>
        <td><?php echo $total_matching_files; ?></td>
      </tr>
      <tr>
        <th scope="row">Total files synchronized:</th>
        <td><?php echo $total_synced_files; ?></td>
      </tr>
      <tr>
        <th scope="row">Next scheduled synchronization:</th>
        <td><?php echo $next_sync_date; ?></td>
      </tr>
    </table>
    <p class="submit"><a href="<?php echo admin_url('options-general.php?page=sync-folder&read_again=true'); ?>" class="button-primary">Read Again</a></p>
    <p class="submit"><a href="<?php echo admin_url('options-general.php?page=sync-folder&sync_now=true'); ?>" class="button-primary">Synchronize Now</a></p>
    <?php
    // En la parte superior de la función de opciones
    if (isset($_GET['sync_now']) && $_GET['sync_now'] == 'true') {
      sync_folder_cron_task();
      echo '<div class="updated"><p>Synchronization completed.</p></div>';
    }
    ?>
  </div>
<?php
}

// Cron Task
function sync_folder_cron_task()
{
  $folder_path = get_option('sync_folder_path', '');
  $file_types = get_option('sync_folder_file_types', 'jpg,jpeg,png,gif');
  $author_id = get_option('sync_folder_author_id', 1);

  if ($folder_path && $file_types) {
    $folder = ABSPATH . trim($folder_path, '/') . '/';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder));
    $total_files = 0;
    $total_matching_files = 0;

    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $total_files++;
        if (in_array($file->getExtension(), explode(',', $file_types))) {
          $total_matching_files++;
        }
      }
    }

    foreach (glob($folder . '*.{' . $file_types . '}', GLOB_BRACE) as $filename) {
      $full_path = $folder . basename($filename);
      echo $full_path;
      $existing_attachment = find_attachment_by_url($full_path);
      $is_variant = is_variant($folder, basename($filename));

      if (!$existing_attachment && !$is_variant) {
        echo " X<br />";
        return;
      }
      echo " !<br />";
      // Get file type
      $filetype = wp_check_filetype(basename($filename), null);

      // Prepare an array of post data for the attachment
      $attachment = array(
        'guid'           => $folder . basename($filename),
        'post_mime_type' => $filetype['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($filename)),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_author'    => $author_id
      );

      // Insert the attachment
      $attach_id = wp_insert_attachment($attachment, $filename);

      // If the file is an image, generate attachment metadata
      if (strpos($filetype['type'], 'image/') === 0) {
        // Include the image.php file to use wp_generate_attachment_metadata() function
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Generate attachment metadata and update the database record
        $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
        wp_update_attachment_metadata($attach_id, $attach_data);
      }
    }
  }

  sync_folder_read_statistics();
}

if (!wp_next_scheduled('sync_folder_cron_task')) {
  wp_schedule_event(time(), 'daily', 'sync_folder_cron_task');
}
add_action('sync_folder_cron_task', 'sync_folder_cron_task');

?>