<?php
/*
Plugin Name: HungryFEED
Plugin URI: http://verysimple.com/products/hungryfeed/
Description: HungryFEED displays RSS feeds on a page or post using Shortcodes.	Respect!
Version: 1.3.9
Author: VerySimple
Author URI: http://verysimple.com/
License: GPL2
*/

define('HUNGRYFEED_VERSION','1.3.9');
define('HUNGRYFEED_DEFAULT_CACHE_DURATION',3600);
define('HUNGRYFEED_DEFAULT_CSS',"h3.hungryfeed_feed_title {}\np.hungryfeed_feed_description {}\ndiv.hungryfeed_items {}\ndiv.hungryfeed_item {margin-bottom: 10px;}\ndiv.hungryfeed_item_title {font-weight: bold;}\ndiv.hungryfeed_item_description {}\ndiv.hungryfeed_item_author {}\ndiv.hungryfeed_item_date {}");
define('HUNGRYFEED_DEFAULT_HTML',"<div class=\"hungryfeed_item\">\n<h3><a href=\"{{permalink}}\">{{title}}</a></h3>\n<div>{{description}}</div>\n<div>Author: {{author}}</div>\n<div>Posted: {{post_date}}</div>\n</div>");
define('HUNGRYFEED_DEFAULT_CACHE_LOCATION',ABSPATH . 'wp-content/cache');
define('HUNGRYFEED_DEFAULT_FEED_FIELDS','title,description');
define('HUNGRYFEED_DEFAULT_ITEM_FIELDS','title,description,author,date');
define('HUNGRYFEED_DEFAULT_LINK_ITEM_TITLE',1);
define('HUNGRYFEED_DEFAULT_ENABLE_WIDGET_SHORTCODES',0);
define('HUNGRYFEED_DEFAULT_DATE_FORMAT','F j, Y, g:i a');

/**
 * import supporting libraries
 */
include_once(plugin_dir_path(__FILE__).'settings.php');
include_once(plugin_dir_path(__FILE__).'libs/utils.php');

add_shortcode('hungryfeed', 'hungryfeed_display_rss');
add_action('admin_menu', 'hungryfeed_create_menu');
add_filter('query_vars', 'hungryfeed_queryvars' );

// only enable widget shortcode processing if specified in the settings
if (get_option('hungryfeed_enable_widget_shortcodes',HUNGRYFEED_DEFAULT_ENABLE_WIDGET_SHORTCODES))
{
	add_filter('widget_text', 'do_shortcode' );
}

/**
 * Displays the RSS feed on the page
 * @param unknown_type $params
 */
function hungryfeed_display_rss($params)
{
	// if simplepie isn't installed then we can't continue
	if (!hungryfeed_include_simplepie()) return "";
	
	// read in all the possible shortcode parameters
	$url = hungryfeed_val($params,'url','http://verysimple.com/feed/');
	$force_feed = hungryfeed_val($params,'force_feed','0');
	$xml_dump = hungryfeed_val($params,'xml_dump','0');
	$decode_url = hungryfeed_val($params,'decode_url','1');
	$max_items = hungryfeed_val($params,'max_items',0);
	$template_id = hungryfeed_val($params,'template',0);
	$date_format = hungryfeed_val($params,'date_format',HUNGRYFEED_DEFAULT_DATE_FORMAT);
	$allowed_tags = hungryfeed_val($params,'allowed_tags','');
	$strip_ellipsis = hungryfeed_val($params,'strip_ellipsis',0);
	$filter = hungryfeed_val($params,'filter','');
	$link_target = hungryfeed_val($params,'link_target','');
	$page_size = hungryfeed_val($params,'page_size',0);
	
	$feed_fields = explode(",", hungryfeed_val($params,'feed_fields',HUNGRYFEED_DEFAULT_FEED_FIELDS));
	$item_fields = explode(",", hungryfeed_val($params,'item_fields',HUNGRYFEED_DEFAULT_ITEM_FIELDS));
	$link_item_title = hungryfeed_val($params,'link_item_title',HUNGRYFEED_DEFAULT_LINK_ITEM_TITLE);
	
	
	// fix weirdness in the url due to the wordpress visual editor
	if ($decode_url) $url = html_entity_decode($url);
	
	// the target code for any links in the feed
	$target_code = ($link_target) ? "target='$link_target'" : "";
	
	// buffer the output.
	ob_start();
	
	echo "<style>\n" .  get_option('hungryfeed_css',HUNGRYFEED_DEFAULT_CSS) . "\n</style>";

	// catch any errors that simplepie throws
	set_error_handler('hungryfeed_handle_rss_error');
	$feed = new SimplePie();
	
	$cache_duration = get_option('hungryfeed_cache_duration',HUNGRYFEED_DEFAULT_CACHE_DURATION);
	if ($cache_duration)
	{
		$feed->enable_cache(true);
		$feed->set_cache_duration($cache_duration);
		$feed->set_cache_location(HUNGRYFEED_DEFAULT_CACHE_LOCATION);
	}
	else
	{
		$feed->enable_cache(false);
	}
	
	$feed->set_feed_url($url);
	
	// @HACK: SimplePie adds this weird shit into eBay feeds
	$feed->feed_url = str_replace("%23038;","",$feed->feed_url);
	
	if ($force_feed) $feed->force_feed(true);
	
	if (!$feed->init())
	{	
		hungryfeed_fatal("SimplePie reported: " . $feed->error,"HungryFEED can't get feed.  Don't be mad at HungryFEED.");
		
		if ($xml_dump)
		{
			// this will cause messed up output since simplepie outputs xml headers
			// but there seems to be no other way to get the raw xml back out for debuggin
			
			echo "\n\n\n<!-- BEGIN DEBUG OUTPUT FROM FEED at $feed->feed_url -->\n\n\n";
			
			$feed->xml_dump = true;
			$feed->init();
			
			echo "\n\n\n<!-- END DEBUG OUTPUT FROM FEED -->\n\n\n";
			
		}

		$buffer = ob_get_clean();
		return $buffer;
	}
	
	// restore the normal wordpress error handling
	restore_error_handler();
	
	if (in_array("title",$feed_fields)) echo '<h3 class="hungryfeed_feed_title">' . $feed->get_title() . "</h3>\n";
	if (in_array("description",$feed_fields)) echo '<p class="hungryfeed_feed_description">' . $feed->get_description() . "</p>\n";
	
	echo "<div class=\"hungryfeed_items\">\n";
	
	$counter = 0;
	$template_html = "";
	
	if ($template_id == "1" || $template_id == "2" || $template_id == "3")
	{
		$template_html = get_option('hungryfeed_html_'.$template_id,HUNGRYFEED_DEFAULT_HTML);
	}
	
	$allowed_tags = $allowed_tags 
		? ('<' . implode('><',explode(",",$allowed_tags)) . '>') 
		: '';
	
	$items = $feed->get_items();
	$pages = array();
	$page_num = 1;
	
	if ($page_size)
	{
		// array chunk used for pagination
		$pages = array_chunk($items, $page_size);

		// grab the requested page from the querystring, make sure it's legit
		global $wp_query;
		if (isset($wp_query->query_vars['hf_page']))  $page_num = $wp_query->query_vars['hf_page'];
		if (is_numeric($page_num) == false || $page_num < 1 || $page_num > count($pages)  ) $page_num = 1;
	}
	else
	{
		$pages[] = $items;
		$page_num = 1;
	}
	
	$num_pages = count($pages);
		
	foreach ($pages[$page_num-1] as $item)
	{
		$counter++;
		$author = $item->get_author();
		$author_name = ($author ? $author->get_name() : '');
		$title = $item->get_title();
		$description = $item->get_description();
		
		// if a filter was specified, then only show the feed items that contain the filter text
		if ($filter && strpos($description,$filter) === false && strpos($title,$filter) === false)
		{
			continue;
		}
		
		if ($allowed_tags) $description = strip_tags($description,$allowed_tags);
		
		if ($strip_ellipsis) $description = str_replace(array('[...]','...'),array('',''),$description);

		if ($target_code) $description = str_replace('<a ','<a '.$target_code.' ',$description);
		
		if ($max_items > 0 && $counter > $max_items) break;
		
		// either use a template, or the default layout
		if ($template_html)
		{
			$rss_values = array(
				'permalink' => $item->get_permalink(),
				'title' => $title,
				'description' => $description,
				'author' => $author_name,
				'post_date' => $item->get_date($date_format)
			);
			
			echo hungryfeed_merge_template($template_html,$rss_values);
		}
		else
		{
			echo "<div class=\"hungryfeed_item\">\n";
				if (in_array("title",$item_fields)) 
					echo $link_item_title 
						? '<div class="hungryfeed_item_title"><a href="' . $item->get_permalink() . '" '. $target_code .'>' . $title . "</a></div>\n"
						: '<div class="hungryfeed_item_title">' . $title . '</div>';
				if (in_array("description",$item_fields)) 
					echo '<div class="hungryfeed_item_description">' . $description . "</div>\n";
				if ($author_name && in_array("author",$item_fields)) 
					echo '<div class="hungryfeed_item_author">Author: ' . $author_name . "</div>\n";
				if ($item->get_date() && in_array("date",$item_fields)) 
					echo '<div class="hungryfeed_item_date">Posted: ' . $item->get_date($date_format) . "</div>\n";
			echo "</div>\n";
		}
	}
	
	echo "</div>\n";
	
	if ($page_size)
	{
		echo "<p class=\"hungryfeed_pagenav\"><span>Viewing page $page_num of $num_pages</span>";
		
		if ($page_num > 1) echo "<span>|</span><span><a href=\"". hungryfeed_create_url(array("hf_page" => $page_num - 1)) . "\">Previous Page</a></span>";
		if ($page_num < $num_pages) echo "<span>|<span><a href=\"". hungryfeed_create_url(array("hf_page" => $page_num + 1)) . "\">Next Page</a></span>";
		
		echo "</p>";
	}
	
	// flush the buffer and return
	$buffer = ob_get_clean();
	return $buffer;
}

$hungryfeed_merge_template_values = null;

/**
 * Replaces
 * @param string template
 * @param array key/value pair
 */
function hungryfeed_merge_template($template, $values)
{
	global $hungryfeed_merge_template_values;
	$hungryfeed_merge_template_values = $values;
	return preg_replace_callback('!\{\{(\w+)\}\}!', 'hungryfeed_merge_template_callback', $template);
}

function hungryfeed_merge_template_callback($matches)
{
	global $hungryfeed_merge_template_values;
	
	//echo "<div>called for ".$matches[1]."<div/>";
	//print_r($hungryfeed_merge_template_values);
	return $hungryfeed_merge_template_values[$matches[1]];
}

/**
 * registration for queryvars used by hungryfeed
 * @param array original array of allowed wordpress query vars
 * @return array $qvars with extra allowed vars added to the array
 */
function hungryfeed_queryvars( $qvars )
{
	$qvars[] = 'hf_page';
 	return $qvars;
}
