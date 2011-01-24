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
	const DEBUG_MESSAGES = false;
	const API_BASE_URL = 'http://api.twitter.com/1/statuses/user_timeline/';
	const CACHE_UPDATE_TIMEOUT = 60;
	
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
			),
			array(
				'name'=>'cache_lifetime',
				'label'=>'Refresh interval',
				'size'=>3,
				'default'=>1,
				'note'=>'Measured in minutes. Values lower than 1 may trigger errors due to Twitter API rate limits.'
			)
		));
	}
	
	function render($fields) {
		self::debug_msg('twitter user feed widget render');
		// check if the cache is recent enough to use
		if ($this->cache_younger_than($fields['cache_lifetime'])){
			self::debug_msg("cached feed from ".(time() - $this->cache_date())." seconds ago");
			$tweet_output = $this->get_cache();
		} elseif($this->is_updating_cache()) {
			self::debug_msg("update in progress, showing cached feed from ".(time() - $this->cache_date())." seconds ago");
			$tweet_output = $this->get_cache();
		} else {
			$this->start_cache_update();
			$tweet_output = $this->render_tweets($fields);
			if ($tweet_output) {
				$update_time = $this->update_cache_date();
				update_option($this->cache_key(), $tweet_output);
				self::debug_msg("refreshed feed at ".$update_time);
			} else {
				// houston, we have a problem.
				self::debug_msg("error retrieving twitter feed! displaying cached output from ".$this->cache_date());
				$tweet_output = $this->get_cache();
			}
			$this->finish_cache_update();
		}
		
		$this->parseUsers($fields);
		
		$output = '';
		
		$title = apply_filters('widget_title', $fields['title']);
		if ($fields['linktitle']){
			$title = "<a href='http://twitter.com/{$this->users[0]}'>$title</a>";
		}
		if ( $title ) $output .= $this->template('before_title') . $title . $this->template('after_title');
		
		$output .= $tweet_output;
		
		echo $this->template('before_widget').$output.$this->template('after_widget');
	}
	
	private function parseUsers($fields){
		if (isset($this->users)) return true;
		$this->users = array_filter(preg_split('/[,\s]+/', $fields['username']));
		return true;
	}
	
	protected function after_update($new_instance, $old_instance){
		$this->update_cache_date(0);
	}
	
	private static function debug_msg($msg)
	{
		if (self::DEBUG_MESSAGES){
			echo "\n<!-- {$msg} -->\n";
		}
	}
	
	private function render_tweets($fields)
	{
		$this->parseUsers($fields);
		$users = $this->users;
		$number = (int) $fields['number'];
		$viewall = $fields['viewall'];
		$dateformat = $fields['dateformat'];
		$responses = array();
		
		foreach ($users as $user){
			$raw_response = wp_remote_get(self::API_BASE_URL."{$user}.json?count={$number}&include_entities=1&include_rts=1");
			
			if ( is_wp_error($raw_response) ) {
				self::debug_msg("Failed to update from Twitter!\n".$raw_response->errors['http_request_failed'][0]);
				return false;
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
		
		$tweets = array();
		foreach ($responses as $user => $response){
			self::debug_msg('adding '.count($response).' tweets from '.$user);
			$tweets = array_merge($tweets, $response);
		}
		
		if (count($users) > 1){
			// sort tweets if we are aggregating multiple users - otherwise they are returned sorted by the API
			usort($tweets, 'Twitter_Feed_Widget::sort_tweets');
			// truncate list of tweets to the total number requested
			$tweets = array_slice($tweets, 0, $number);
		}
		
		$tweet_html = array();
		
		foreach ( $tweets as $tweet ) {
			$text = self::text_with_entity_links($tweet);
			$user = $tweet['user']['screen_name'];
			$image_url = $tweet['user']['profile_image_url'];
			$user_url = "http://twitter.com/$user";
			$source_url = "$user_url/status/{$tweet['id_str']}";
			$tweet_via = $tweet['source'];
			$tweet_date = strtotime($tweet['created_at']);
			
			self::debug_msg("tweet id ".$tweet['id_str']);
			
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
		$tweet_output = "<ul>\n".$tweet_output."</ul>\n";
		return $tweet_output;
	}
	
	public static function sort_tweets($a, $b){
		$a_date = strtotime($a['created_at']);
		$b_date = strtotime($b['created_at']);
		if ($a_date == $b_date){
			return 0;
		}
		// sort in descending order
		return ($a_date > $b_date) ? -1 : 1;
	}
	
	private function get_cache()
	{
		return get_option($this->cache_key());
	}
	
	private function cache_younger_than($lifetime)
	{
		$lifetime = (float) $lifetime;
		return (time() < ($this->cache_date() + ($lifetime * 60)));
	}
	
	/** option key accessors **/
	private function cache_key(){
		if (!$this->cache_key){
			$this->cache_key = $this->id.'_cache';
		}
		return $this->cache_key;
	}
	
	private function cache_updated_key(){
		if (!$this->cache_updated_key){
			$this->cache_updated_key = $this->id.'_cache_updated';
		}
		return $this->cache_updated_key;
	}
	
	private function cache_worker_key(){
		if (!$this->cache_worker_key){
			$this->cache_worker_key = $this->id.'_cache_worker';
		}
		return $this->cache_worker_key;
	}
	/** end option key accessors **/
	
	private function cache_date()
	{
		return get_option($this->cache_updated_key());
	}
	
	private function update_cache_date($time=false)
	{
		if ($time === false || $time === null || $time === '') $time = time();
		update_option($this->cache_updated_key(), $time);
		return $time;
	}
	
	private function start_cache_update()
	{
		update_option($this->cache_worker_key(), time());
	}
	
	private function finish_cache_update()
	{
		update_option($this->cache_worker_key(), 0);
	}
	
	private function is_updating_cache(){
		return ((time() - get_option($this->cache_worker_key())) < self::CACHE_UPDATE_TIMEOUT);
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
					'content'=>self::entity_link($entity_type, $entity)
				);
				$entities_sort[] = $entity['indices'][0];
			}
		}
		array_multisort($entities_sort, SORT_DESC, $entities);
		$nodes = array();
		$working = $text;
		foreach ($entities as $entity){
			$text_node = self::h_encode(mb_substr($working, $entity['end']));
			$entity_node = $entity['content'];
			array_unshift($nodes, $entity_node, $text_node);
			$working = mb_substr($working, 0, $entity['start']);
		}
		if (mb_strlen($working) > 0){
			array_unshift($nodes, self::h_encode($working));
		}
		$text = implode('', $nodes);
		return $text;
	}
	
	private static function entity_link($type, $entity)
	{
		switch ($type){
			case 'hashtags':
				return self::interpolate_entity('<a class="hashtag" href="http://twitter.com/search?q=%%23%1$s">#%2$s</a>', $entity['text']);
			case 'user_mentions':
				return self::interpolate_entity('<a class="user_mention" href="http://twitter.com/%1$s">@%2$s</a>', $entity['screen_name']);
			case 'urls':
				return sprintf('<a href="%1$s">%2$s</a>', $entity['url'], self::h_encode($entity['url']));
			default:
				throw new Exception("Encountered unexpected entity type {$type} in tweet!");
		}
	}
	
	private static function interpolate_entity($template, $entity)
	{
		return sprintf($template, urlencode($entity), self::h_encode($entity));
	}
	
	private static function h_encode($str)
	{
		return htmlentities($str, ENT_COMPAT, 'UTF-8');
	}
}

add_action('widgets_init', create_function('', 'return register_widget("Twitter_Feed_Widget");'));

?>
