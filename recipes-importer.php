<?php
/*
    Plugin Name: Zip Recipes Importer
    Text Domain: zrdn-importer
    Domain Path: /languages
    Plugin URI: http://www.ziprecipes.net/
    Plugin GitHub: https://github.com/rlankhorst/zip-recipes-free
    Description: A plugin that imports recipes into Zip Recipes
    Version: 1.0.0
    Author: RogierLankhorst
    Author URI: http://www.ziprecipes.net/
    License: GPLv3 or later
    Copyright 2020 Rogier Lankhorst
*/
/**
 * Adjust constant to change the batch count
 */
//number of recipes to process in one batch
define('ZIP_RECIPE_BATCH', 10);

/**
  Schedule cron jobs if useCron is true
  Else start the functions.
*/
function zipimporter_schedule_cron() {
	$useCron = false;
	if ( $useCron ) {

		if ( ! wp_next_scheduled( 'zipimporter_every_five_minutes_hook' ) ) {
			wp_schedule_event( time(), 'zipimporter_every_five_minutes', 'zipimporter_every_five_minutes_hook' );
		}

		add_action( 'zipimporter_every_five_minutes_hook', 'zipimporter_run_recipe_import' );

	} else {
		add_action( 'shutdown', 'zipimporter_run_recipe_import' );

	}
}
add_action( 'init', 'zipimporter_schedule_cron' );



/**
 * Define our custom schedules
 * @param $schedules
 *
 * @return array
 */

function zipimporter_filter_cron_schedules( $schedules ) {
	$schedules['zipimporter_every_five_minutes']   = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Once every 5 minutes' )
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'zipimporter_filter_cron_schedules' );


/**
 * If deactivated, we want to clear up the cron jobs
 */

function zipimporter_clear_scheduled_hooks() {
	wp_clear_scheduled_hook( 'zipimporter_every_five_minutes_hook' );
}
register_deactivation_hook( __FILE__, 'zipimporter_clear_scheduled_hooks' );

/**
 * Run a recipe import batch
 */
function zipimporter_run_recipe_import() {

	//exit if Zip is not enabled
	if (!class_exists('ZRDN\Recipe')) return;

	if (defined('AMD_YRECIPE_VERSION_NUM')) {
		zrdn_import_yummly();
	}
}

function zrdn_import_yummly(){
	//import all recipes
	global $wpdb;
	if (!get_option('zrdn_yummly_database_import_completed')) {
		$zip_table = $wpdb->prefix . "amd_zlrecipe_recipes";
		$yummly_table = $wpdb->prefix . "amd_yrecipe_recipes";
		//make sure we know which recipes are from yummly

		$wpdb->query( "ALTER TABLE $zip_table ADD original_zip_record int(11);" );
		$wpdb->query( "UPDATE $zip_table SET original_zip_record = 1" );

		//copy data
		$wpdb->query( "INSERT INTO $zip_table (post_id, recipe_title, recipe_image, summary, prep_time, cook_time, yield, serving_size, calories, fat, ingredients, instructions, notes, created_at) SELECT post_id, recipe_title, recipe_image, summary, prep_time, cook_time, yield, serving_size, calories, fat, ingredients, instructions, notes, created_at FROM $yummly_table" );


		update_option( 'zrdn_yummly_database_import_completed', true );
	}

	$recipes = $wpdb->get_results( "select * from $zip_table where original_zip_record is null" );
	if (!get_option('zrdn_yummly_posts_import_completed')) {
		if ($recipes and is_array($recipes)) {
			foreach ($recipes as $recipe){
				//get the post it was attached to
				$post_id = $recipe->post_id;

				//check if it's completed
				if (get_post_meta($post_id, 'zrdn_import_complete', true)) continue;

				$recipe_id = $recipe->recipe_id;
				$post = get_post($post_id);
				$oldshortcode = '/\[amd-yrecipe-recipe\:([0-9]+)\]/i';

				$new_shortcode = ZRDN\Util::get_shortcode($recipe_id);
				$content = preg_replace($oldshortcode, $new_shortcode, $post->post_content, 1);
				$post_data = array(
					'ID' => $post_id,
					'post_content' => $content,
				);
				wp_update_post($post_data);
				update_post_meta($post_id, 'zrdn_import_complete', true);
			}
		}
		update_option('zrdn_yummly_posts_import_completed', true);

	}
}
