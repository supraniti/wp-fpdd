<?php
// Do not run without explicit request from wordpress
$pid = $_GET['pid'];
if ( !$pid || $pid == '' ){
	exit;
}

// Some presets to let the script run without timeouts
ignore_user_abort();
set_time_limit(0);

// Override default php memory limit
ini_set('memory_limit','256M');

// Init wordpress core
define( 'ABSPATH',$_GET['abspath'] );
define( 'SHORTINIT', true );
require_once ABSPATH . 'wp-load.php';
global $wpdb;

// Load configuration
$config = json_decode(file_get_contents(__DIR__  . '/wp-fpdd.config.json'));

// Load cache state
$cache_state = json_decode( file_get_contents( __DIR__  . '/cache_state' ), true );

// If not exists produce initial state
if ( !$cache_state ){
  $cache_state = [];
  foreach ($config as $config_object){
    $cache_state[$config_object['post_type']] = array(
      'count'=>0,
      'date'=>0,
      'signature'=>0
    );
  }
}

// Security measure #1 - Script will not run if a pid do not match filename
$fileName = __DIR__  . '/' . $pid;
if ( file_exists($fileName) ){
  // Security measure #2 - Script will not run if pid do not match wp option value
	$query = "
		SELECT option_value
		FROM $wpdb->options
		WHERE option_name='fpdd-async-pid'
	";
	$auth = $wpdb->get_results($query)[0]->option_value;
	if ( $auth != $pid ){
		exit;
	}
  // Get last update timestamp
	$query = "
		SELECT option_value
		FROM $wpdb->options
		WHERE option_name='fpdd-cache-version'
	";
	$last_update = $wpdb->get_results($query)[0]->option_value;
  // Buffer of Minimum 300 seconds between updates
	if ( ( time() - intval($last_update) ) >= 300 ){
    // Get current databse state
    foreach ($config as $config_object){
      $key = $config_object['post_type'];
      if ($key == 'comment'){
        $query = "
          SELECT max(comment_date) as date, count(comment_date) as count
          FROM $wpdb->comments
        ";
      }else{
        $query = "
          SELECT max(post_modified) as date, count(post_modified) as count
          FROM $wpdb->posts
          WHERE post_type='$key'
        ";
      }
      // Update cache_state
      $cache_state[$key]['count'] = $wpdb->get_results($query)[0]->count;
      $cache_state[$key]['date'] = $wpdb->get_results($query)[0]->date;
      $signature = $cache_state[$key]['count'] . '|' . $cache_state[$key]['date'];
      // If db has changed
      if (!$cache_state[$key]['signature'] || $cache_state[$key]['signature'] != $signature){
        // Update static cache
        generateStaticCache($config_object);
        $cache_state[$key]['signature'] = $signature;
        // Update cache version
        update_option('fpdd-cache-version', time());
        // Update cache state
        file_put_contents( __DIR__  . '/cache_state', json_encode($cache_state) );
      }
      // Update script sign of life
      file_put_contents( $fileName . '.info', time() );
    }
	}
  // Update script sign of life
	file_put_contents( $fileName . '.info', time() );
}
/*************************************************
 	Product Dataset for Frontend App
*************************************************/
// Helper to sort multidimensional array by field
function sort_by_field(&$arr, $field) {
	usort($arr, function($a, $b) use ($field) {
		if ($field === 'name'){
			return strcmp(strtolower($a['name']), strtolower($b['name']));
		}else{
			if ( ($a[$field] + 0) === ($b[$field] + 0) ){
				return 0;
			}else{
				return ($a[$field] + 0) - ($b[$field] + 0) > 0 ? 1 : -1;
			}
		}
	});
}
// Helper to avoid excessive memory usage by splitting database queries to a known size
function partial_query($wpdb,$query,$query_size,$query_offset){
	$query_limit = 'LIMIT ' . $query_size . ' OFFSET ' . $query_offset*$query_size;
	$result = $wpdb->get_results($query . $query_limit);
	return count($result) > 0 ? $result : false;
}

function generateStaticCache($config_object){
  $key = $config_object['post_type'];
  $wpdb->query('SET SESSION group_concat_max_len = 10000');
  if ($key == 'comment'){
    $query = "
  		SELECT c.*,
  		GROUP_CONCAT(cm.meta_key ORDER BY cm.meta_key DESC SEPARATOR '||') as meta_keys,
  		GROUP_CONCAT(IFNULL(cm.meta_value,'NULL') ORDER BY cm.meta_key DESC SEPARATOR '||') as meta_values
  		FROM $wpdb->comments c
  		LEFT JOIN $wpdb->commentmeta cm on cm.comment_id = c.comment_ID
  		WHERE c.comment_approved = '1' and c.comment_author != 'ActionScheduler'
  		GROUP BY c.comment_ID
  	";
  	$query_offset = 0;
  	$query_size = 500;
  	$COMMENTSDATA = [];
  	while ($comments = partial_query($wpdb,$query,$query_size,$query_offset)){
  		foreach($comments as $comment){
  			$meta = array_combine(explode('||',$comment->meta_keys),array_map('maybe_unserialize',explode('||',$comment->meta_values)));
  			$COMMENTSDATA[$comment->comment_post_ID][]=array(
  				'date' => strtotime($comment->comment_date),
  				'content' => $comment->comment_content,
  				'author' => $comment->comment_author,
  				'email' => $comment->comment_author_email,
  			);
  		}
  		$query_offset++;
  	}
  	file_put_contents( __DIR__ . '/comments.json'  , json_encode($COMMENTSDATA,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE ) );
  	$COMMENTSDATA = null;
  }else{
    $INDICES = [];
    $POSTSDATA = [];
  	$query = "
  		SELECT p.*,
  		GROUP_CONCAT(pm.meta_key ORDER BY pm.meta_key DESC SEPARATOR '||') as meta_keys,
  		GROUP_CONCAT(IFNULL(pm.meta_value,'NULL') ORDER BY pm.meta_key DESC SEPARATOR '||') as meta_values
  		FROM $wpdb->posts p
  		LEFT JOIN $wpdb->postmeta pm on pm.post_id = p.ID
  		WHERE p.post_type = $key
  		GROUP BY p.ID
  	";
    $query_offset = 0;
    $query_size = 500;
    while ($posts = partial_query($wpdb,$query,$query_size,$query_offset)){
      foreach($posts as $post){
        $meta = array_combine(explode('||',$post->meta_keys),array_map('maybe_unserialize',explode('||',$post->meta_values)));
        // Sort Indices
        $INDICEDATA = [];
        foreach ($config_object['core_indices'] as $indice_key){
          $INDICEDATA[$indice_key] = $post->$indice_key;
        }
        foreach ($config_object['meta_indices'] as $indice_key){
          $INDICEDATA[$indice_key] = $meta['$indice_key'];
        }
        $INDICES[] = $INDICEDATA;
        // Post Data
        foreach ($config_object['core_fields'] as $indice_key){
          $POSTSDATA[$post->ID][$indice_key] = $post->$indice_key;
        }
        foreach ($config_object['meta_fields'] as $indice_key){
          $POSTSDATA[$post->ID][$indice_key] = $meta[$indice_key];
        }
        // Images
        foreach($config_object['attachment_meta_keys'] as $amk){
          $gallery = explode(',' , $wpdb->get_var("SELECT meta_value FROM $wpdb->postmeta where meta_key = $amk and post_id = '$post->ID'"));
          foreach ($gallery as $attachment_id){
            $_wp_attachment_metadata = unserialize($wpdb->get_var("SELECT meta_value FROM $wpdb->postmeta where meta_key = '_wp_attachment_metadata' and post_id = $attachment_id"));
            foreach($config_object['image_sizes'] as $size){
              if ($size == 'full'){
                $url = $_wp_attachment_metadata['file'];
              }
              else{
                $url = dirname($_wp_attachment_metadata['file']) . '/' . $_wp_attachment_metadata['sizes'][$size]['file'];
              }
              $POSTSDATA[$post->ID]['images'][$amk][$size] = $url;
            }
          }
        }
      }
      $query_offset++;
    }
    file_put_contents( __DIR__ . '\/cache\/' . $key . '.json', json_encode($POSTSDATA) );
    $SORTEDINDICES = [];
    foreach ($config_object['core_indices'] as $field){
      sort_by_field($INDICES, $field);
      $SORTEDINDICES[$field] = array_column($INDICES, 'id');
    }
    foreach ($config_object['meta_indices'] as $field){
      sort_by_field($INDICES, $field);
      $SORTEDINDICES[$field] = array_column($INDICES, 'id');
    }
    file_put_contents( __DIR__ . '\/cache\/'. $key . '_INDICES.json', json_encode($SORTEDINDICES) );
    // Post Taxonomies
    $query = "
      SELECT p.ID, tt.taxonomy,
      GROUP_CONCAT(tt.term_id) as terms
      FROM $wpdb->posts AS p
      INNER JOIN $wpdb->term_relationships AS tr ON ( p.ID = tr.object_id)
      INNER JOIN $wpdb->term_taxonomy AS tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id)
      INNER JOIN $wpdb->terms AS t ON (t.term_id = tt.term_id)
      WHERE p.post_type = $key
      GROUP BY p.ID, tt.taxonomy
      ";
    $ptax = $wpdb->get_results($query);
    $POSTS_TAXONOMIES = [];
    foreach ($ptax as $post){
      $POSTS_TAXONOMIES[$post->ID][$post->taxonomy] = explode(',',$post->terms);
    }
    file_put_contents( __DIR__ . '\/cache\/'. $key . '_TAXONOMIES.json', json_encode($POSTS_TAXONOMIES) );
    file_put_contents( __DIR__ . '\/cache\/'. $key . '_INDICES.json', json_encode($SORTEDINDICES) );
    // Cleanup
    $SORTEDINDICES = null;
    $INDICES = null;
    $POSTSDATA = null;
    $POSTS_TAXONOMIES = null;
    if ( $config_object['update_taxonomy_dictionary'] ){
      generateTaxonomyDictionary();
    }
  }
}
function generateTaxonomyDictionary(){
  $query = "
    SELECT *
    FROM $wpdb->term_taxonomy as term
    WHERE term.count > 0
    ";
  $terms = $wpdb->get_results($query);
  $TAXONOMIES = [];
  foreach ($terms as $term){
    $TAXONOMIES['MAP'][$term->taxonomy]['term_ids'][] = $term->term_id;
    $TAXONOMIES['MAP'][$term->taxonomy]['term_parent'][] = $term->parent;
    $TAXONOMIES['MAP'][$term->taxonomy]['term_count'][] = $term->count;
  }
  //Woocommerce taxonomy support
  $query = "
    SELECT attribute_label, attribute_name
    FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
    WHERE attribute_name != ''
    ";
  $woo_attributes = $wpdb->get_results($query);
  foreach ($woo_attributes as $attribute){
    $TAXONOMIES['MAP']['pa_' . $attribute->attribute_name]['label'] = $attribute->attribute_label;
  }
  $query = "
    SELECT term_id, name
    FROM $wpdb->terms
    ";
  $DBterms = $wpdb->get_results($query);
  foreach ($DBterms as $term){
    $TAXONOMIES['TERMS'][$term->term_id] = $term->name;
  }
  file_put_contents( __DIR__ . '/TAXONOMIES.json', json_encode($TAXONOMIES,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE) );
  $TAXONOMIES = null;
  $terms = null;
  $woo_attributes = null;
  $DBterms = null;
}
