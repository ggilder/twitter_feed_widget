<?php
/*
Plugin Name: Twitter Feed Widget
Plugin URI: http://github.com/ggilder/twitter_feed_widget
Description: A widget for displaying the most recent twitter status updates for a particular user or users.
Author: Gabriel Gilder
Author URI: http://github.com/ggilder/twitter_feed_widget
Version: 0.3.0

        Based on Twitter Hash Tag plugin by Matt Martz (http://sivel.net/)
*/

class Twitter_Feed_Widget extends WidgetZero {
	const API_BASE_URL = 'http://api.twitter.com/1/statuses/user_timeline/';
	
	function Twitter_Feed_Widget() {
		$widget_ops = array('classname' => 'twitter_feed_widget', 'description' => __( "Real time Twitter user following") );
		$this->WP_Widget('twitter_feed', __('Twitter Feed'), $widget_ops);
		
		// set up fields
		$this->set_fields(array(
			array(
				'name'=>'title',
			),
			array(
				'name'=>'linktitle',
				'label'=>'Link on title?',
				'type'=>'toggle',
				'note'=>'Title will link to the first user name you provide below.'
			),
			array(
				'name'=>'username',
				'label'=>'User Name(s)',
				'note' => 'Separate multiple user names with commas and/or spaces.'
			),
			array(
				'name'=>'number',
				'label'=>'Number of tweets to show',
				'size' => '3',
				'default' => '3'
			),
			array(
				'name'=>'showuser',
				'label'=>'Show username on tweet?',
				'type'=>'toggle'
			),
			array(
				'name'=>'images',
				'label'=>'Show user images?',
				'type'=>'toggle',
			),
			array(
				'name'=>'via',
				'label'=>'Show tweet source?',
				'type'=>'toggle',
				'note'=>'(web, iPhone app, etc.)'
			),
			array(
				'name'=>'dateformat',
				'label'=>'Date format',
				'note'=>'(See <a href="http://us3.php.net/manual/en/function.date.php" target="_blank">guide to date formats</a>)'
			),
			array(
				'name'=>'permalink',
				'label'=>'Permalink style',
				'type'=>'select',
				'optionlist' => array(
					'none' => "None",
					'double_arrow' => "Double arrow (&raquo;)",
					'date' => "Link on tweet date",
				)
			),
			array(
				'name'=>'viewall',
				'label'=>'View All link text',
				'note'=>'(Leave blank for no view all link)'
			)
		));
	}
	
	function render($fields) {
		$users = preg_split('/[,\s]+/', $fields['username']);
		$number = $fields['number'];
		$viewall = $fields['viewall'];
		$dateformat = $fields['dateformat'];
		$responses = array();
		$errors = array();
		$cachekey = 'twitter_feed_cache_'.$this->id;
		
		foreach ($users as $user){
			$raw_response = wp_remote_get(self::API_BASE_URL."{$user}.json?count={$number}&include_entities=1");
			
			if ( is_wp_error($raw_response) ) {
				$errors[] = "<!-- Failed to update from Twitter! -->\n<!-- {$raw_response->errors['http_request_failed'][0]} -->\n";
				break;
			}
			
			if ( function_exists('json_decode') ) {
				$response = self::json_object_to_array(json_decode($raw_response['body']));
			} else {
				include(ABSPATH . WPINC . '/js/tinymce/plugins/spellchecker/classes/utils/JSON.php');
				$json = new Moxiecode_JSON();
				$response = @$json->decode($raw_response['body']);
			}
			
			$responses[$user] = $response;
		}
		
		if (count($errors) == 0) {
			$tweets = array();
			foreach ($responses as $response){
				$tweets = array_merge($tweets, $response);
			}
			
			if (count($users) > 1){
				// sort tweets if we are aggregating multiple users - otherwise they are returned sorted by the API
				usort($tweets, function($a, $b){
					$a_date = strtotime($a['created_at']);
					$b_date = strtotime($b['created_at']);
					if ($a_date == $b_date){
						return 0;
					}
					// sort in descending order
					return ($a_date > $b_date) ? -1 : 1;
				});
				// truncate list of tweets to the total number requested
				$tweets = array_slice($tweets, 0, $number);
			}
			
			$tweet_html = array();
			
			foreach ( $tweets as $tweet ) {
				$text = self::text_with_entity_links($tweet);
				$user = $tweet['user']['screen_name'];
				$image_url = $tweet['user']['profile_image_url'];
				$user_url = "http://twitter.com/$user";
				$source_url = "$user_url/status/{$tweet['id']}";
				$tweet_via = $tweet['source'];
				$tweet_date = strtotime($tweet['created_at']);
				
				$image = '';
				if ( $fields['images'] ) {
					$image = "<span class='userimg'><a href='$user_url'><img src='$image_url' alt='$user' /></a></span> ";
				}
				$handle = '';
				if ($fields['showuser']) {
					$handle = sprintf('<span class="username"><a href="%s">@%s</a>:</span> ', $user_url, $user);
				}
				
				$date_and_permalink = '';
				// permalink
				if ($fields['permalink'] == 'double_arrow') {
					$date_and_permalink .= " <a class='permalink' href='$source_url'>&raquo;</a>";
				}
				// date with optional permalink
				if ($dateformat) {
					$date_string = date($dateformat, $tweet_date);
					if ($fields['permalink'] == 'date'){
						$date_string = "<a class='permalink' href='$source_url'>".$date_string."</a>";
					}
					$date_string = " <span class='date'>".$date_string."</span>";
					$date_and_permalink .= $date_string;
				}
				
				$via = '';
				if ($fields['show_via']) {
					$via = " <span class='source'>via ".$tweet_via."</span>";
				}
				
				$tweet_html[] = "<li>{$image}{$handle}<span class='tweet'>{$text}</span>{$date_and_permalink}{$via}</li>";
			}
			$tweet_output = implode("\n", $tweet_html);
			if ($viewall) {
				$tweet_output .= "<li class='view-all'><a href='http://twitter.com/{$users[0]}'>" . $viewall . "</a></li>\n";
			}
			$tweet_output = "<ul class='twitter-user-widget'>\n".$tweet_output."</ul>\n";
			update_option($cachekey, $tweet_output);
		} else {
			$tweet_output = get_option($cachekey);
		}
		
		$output = '';
		
		$title = apply_filters('widget_title', $fields['title']);
		if ($fields['linktitle']){
			$title = "<a href='http://twitter.com/{$users[0]}'>$title</a>";
		}
		if ( $title ) $output .= $this->template('before_title') . $title . $this->template('after_title');
		
		if ($errors) {
			$output .= implode("\n", $errors);
		}
		
		$output .= $tweet_output;
		
		echo $this->template('before_widget').$output.$this->template('after_widget');
	}
	
	private static function json_object_to_array($json) {
		if (is_object($json)) {
			return self::json_object_to_array(get_object_vars($json));
		} elseif (is_array($json)) {
			foreach($json as $key => $val) {
				$json[$key] = self::json_object_to_array($val);
			}
			return $json;
		} else {
			return $json;
		}
	}
	
	private static function text_with_entity_links($tweet)
	{
		$text = $tweet['text'];
		$entities = array();
		$entities_sort = array();
		foreach ($tweet['entities'] as $entity_type => $entity_list){
			foreach($entity_list as $entity){
				$entities[] = array(
					'start'=>$entity['indices'][0],
					'end'=>$entity['indices'][1],
					'link'=>self::entity_link($entity_type, $entity)
				);
				$entities_sort[] = $entity['indices'][0];
			}
		}
		array_multisort($entities_sort, SORT_DESC, $entities);
		foreach ($entities as $entity){
			$text = substr($text, 0, $entity['start']) . $entity['link'] . substr($text, $entity['end']);
		}
		return $text;
	}
	
	private static function entity_link($type, $entity)
	{
		switch ($type){
			case 'hashtags':
				return sprintf('<a class="hashtag" href="http://twitter.com/search?q=%%23%1$s">#%1$s</a>', $entity['text']);
			case 'user_mentions':
				return sprintf('<a class="user_mention" href="http://twitter.com/%1$s">@%1$s</a>', $entity['screen_name']);
			case 'urls':
				return sprintf('<a href="%1$s">%1$s</a>', $entity['url']);
			default:
				throw new Exception("Encountered unexpected entity type {$type} in tweet!");
		}
	}
}

add_action('widgets_init', create_function('', 'return register_widget("Twitter_Feed_Widget");'));

?>
