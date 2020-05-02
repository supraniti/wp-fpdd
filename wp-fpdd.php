<?php
/**
 * Plugin Name: WP Frontend Setup
 * Plugin URI: https://github.com
 * Description: Wordpress as a backend - and much more
 * Version: 1.0.0
 * Author: Supraniti
 * Author URI: https://github.com
 * License: GPL2
 */

 /*************************************************
    Check and sync cache on init
 *************************************************/
 add_action('init', 'fpdd_cache_refresh');
 function fpdd_cache_refresh() {
 	$current_pid = get_option('fpdd-async-pid');
 	$idle_time = 0;
 	if ($current_pid){
 		$file_exists = file_exists(__DIR__  . '/' . $current_pid);
 		$sign_of_life = file_get_contents(__DIR__  . '/' . $current_pid . '.info');
 		$idle_time = time() - intval($sign_of_life);
 	}
 	if ( !$current_pid || !$file_exists || (intval($idle_time) > 900) ){// 15 minutes idletime -> restart process
    unlink(__DIR__  . '/' . $current_pid);
		unlink(__DIR__  . '/' . $current_pid . '.info');
 		$pid = rand(1000,9999) . '-' . rand(1000,9999) . '-' . rand(1000,9999) . '-' . rand(1000,9999);
 		update_option( 'fpdd-async-pid', $pid );
 		file_put_contents(__DIR__  . '/' . $pid, time() );
 		file_put_contents(__DIR__  . '/' . $pid . '.info', time() );
 		wp_remote_get(__DIR__  . '/async.php?pid=' . $pid . '&abspath=' . ABSPATH, array(blocking=>false,timeout=>0) );
 	}
 }
