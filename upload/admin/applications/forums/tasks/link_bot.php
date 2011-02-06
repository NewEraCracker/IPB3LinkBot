<?php

/**
 * LINK BOT v2.1 for IPB3
 * Build 17 by ujcogtha
 * 
 * Author: nihalz
 * Modified by NewEraCracker
 * Modified by ujcogtha
 * 
 * Web (nihalz): http://nihalz.forums-free.com/
 * Web (NewEraCracker): http://planet-dl.org/
 **/

if ( ! defined( 'IN_IPB' ) )
{
	print "<h1>Incorrect access</h1>You cannot access this file directly. If you have recently upgraded, make sure you upgraded all the relevant files.";
	exit();
}

class task_item
{
	/**
	* Parent task manager class
	*
	* @access	protected
	* @var		object
	*/
	protected $class;

	/**
	* This task data
	*
	* @access	protected
	* @var		array
	*/
	protected $task			= array();
	
	/**
	* Registry Object Shortcuts
	*/
	protected $registry;
	protected $settings;
	
	/**
	* Constructor
	*
	* @access	public
	* @param	 object		ipsRegistry reference
	* @param	 object		Parent task class
	* @param	array		 This task data
	* @return	void
	*/
	public function __construct( ipsRegistry $registry, $class, $task )
	{
		/* Make registry objects */
		$this->registry	= $registry;
		$this->DB		  = $this->registry->DB();
		$this->settings   =& $this->registry->fetchSettings();
		$this->request	=& $this->registry->fetchRequest();
		$this->lang		  = $this->registry->getClass('class_localization');
		$this->member	  = $this->registry->member();
		$this->memberData =& $this->registry->member()->fetchMemberData();
		$this->cache	  = $this->registry->cache();
		$this->caches	 =& $this->registry->cache()->fetchCaches();
		
		$this->class	= $class;
		$this->task		= $task;
	}
	
	/**
	* Run this task
	*
	* @access	public
	* @return	void
	*/
	public function runTask()
	{
		//-----------------------------------------
		// CHECK AND GET BOT SETTINGS
		//-----------------------------------------
		
		if($this->settings['linkbot_member_id']=="" OR $this->settings['linkbot_scanfirstpost']=="" OR $this->settings['linkbot_forumids']=="" OR $this->settings['linkbot_trashcan_id']=="" OR $this->settings['linkbot_threshold']=="" OR $this->settings['linkbot_reply_msg']=="" OR !is_numeric($this->settings['linkbot_member_id']) OR !is_numeric($this->settings['linkbot_threshold']))
		{
			$this->class->appendTaskLog( $this->task, "The link checker was not run. Please configure the bot's settings completely and correctly." );
			
			//-----------------------------------------
			// Unlock Task: DO NOT MODIFY!
			//-----------------------------------------
			
			$this->class->unlockTask( $this->task );
			return;
		}
		// Load the bot member
		$this->bot_memberData = IPSMember::load($this->settings['linkbot_member_id'])
		//$bot_name = $this->DB->buildAndFetch(array( 'select' => 'members_l_display_name', 'from' => 'members', 'where' => "member_id=".$this->settings['linkbot_member_id'] ));
		if($this->settings['linkbot_scanfirstpost'] == 1) {
			$scan_first_option = " AND p.new_topic=1";
		}
		
		$hosts = explode("\n", $this->settings['linkbot_filehosts']);
		$total_urls = 0;
		$total_dead_urls = 0;
		$total_errors = 0;
		$i = 0;
		
		//-----------------------------------------
		// BOT START
		//-----------------------------------------
		
		$this->class->appendTaskLog( $this->task, "Started Scanning Forums." );
		
		//-----------------------------------------
		// GET POSTS
		//-----------------------------------------
		
		$this->DB->build(
						array(
								'select' => 't.forum_id,t.title,t.title_seo',
								'from' => array( 'topics' => 't'),
								'where' => "t.forum_id IN (".$this->settings['linkbot_forumids'].") AND t.forum_id<>".$this->settings['linkbot_trashcan_id'],
								'add_join' => array (
														'select' => 'p.post,p.pid,p.topic_id,p.new_topic',
														'from' => array('posts' => 'p'),
														'where' => "t.tid=p.topic_id".$scan_first_option
													)
							)
						);
		$get_post_query = $this->DB->execute();
		
		if ( $this->DB->getTotalRows( $get_post_query ) )
		{
			while( $post = $this->DB->fetch( $get_post_query ) )
			{	
				
				$i++;
				
				//-----------------------------------------
				// FOR DEBUGGING
				//-----------------------------------------
								
				if($i%$this->settings['linkbot_postinterval'] == 0) {
					$this->class->appendTaskLog( $this->task, "Scanned " . $i . " Posts." );
				}
				
				$rs = ""; $mu = "";
				$total_links = 0;
				$total_dead = 0;
				
				//-----------------------------------------
				// GET POST CONTENT AND SCAN FOR LINKS
				//-----------------------------------------
				
				$subject = $post['post'];
				$pattern = "|http://.*/.+|";
				preg_match_all($pattern, $subject, $matches);
				foreach($matches[0] as $link)
				{
				
					//-----------------------------------------
					// INCREASE VARIABLE THAT STORES LINKS COUNT
					//-----------------------------------------					

					$total_links++;	
				
					//-----------------------------------------
					// ADD RS AND MU LINKS TO BATCH ARRAY
					//-----------------------------------------  
					
					if(strpos($link,"rapidshare.com/files"))
					{
						$rs .= $link . "\n";
					}
					if(strpos($link,"megaupload.com"))
					{
						$mu .= $link . "\n";
					}
					
					//-----------------------------------------
					// CHECK HOSTS OTHER THAN RS AND MU
					//-----------------------------------------

					/* Create temporary variables*/
					foreach($hosts as $h)
					{
						$filehosts = explode("|",$h);
						if(strpos($link,$filehosts[0])) { $keyword = $filehosts[1]; break; }
					}
					
					/* Check other hosts defined in ACP */
					if($hosts != "" && $keyword != "")  
					{
					
						// @todo: limit sizes to avoid memory leaks
						
						$ch = curl_init();
						curl_setopt($ch, CURLOPT_URL,$link);
						curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.1.9) Gecko/20100315 Firefox/3.5.10');
						curl_setopt($ch, CURLOPT_HEADER, true);
						curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
						curl_setopt($ch, CURLOPT_FAILONERROR, 1);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
						curl_setopt($ch, CURLOPT_TIMEOUT, 8);
						curl_setopt($ch, CURLOPT_COOKIEFILE, IPSLib::getAppDir( 'forums' ) . '/tasks/mscookie');
						$result = curl_exec($ch);
						$header  = curl_getinfo( $ch );
						curl_close($ch);
						if($header['http_code'] == "200")
						{
							if(!strpos($result,$keyword))
							$total_dead++;
						}
						else
						{
							$total_errors++;
						}
					}
					
					/* Destroy temporary variables */
					unset($keyword,$h,$filehosts);  
				}						
				
				//-----------------------------------------
				// BATCH CHECK RS LINKS
				//-----------------------------------------
				
				if($rs != "")
				{
					$ch = curl_init("http://rapidshare.com/cgi-bin/checkfiles.cgi");
					curl_setopt($ch, CURLOPT_FAILONERROR, 1);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
					curl_setopt($ch, CURLOPT_TIMEOUT, 5);
					curl_setopt($ch, CURLOPT_POST, 1);
					curl_setopt($ch, CURLOPT_POSTFIELDS, "toolmode=1&urls=$rs");
					$result = curl_exec($ch);
					$header  = curl_getinfo( $ch );
					curl_close($ch);
					if($header['http_code'] == "200")
					{
						$total_dead += substr_count($result,",-1");
					}
					else
					{
						$total_errors++;
					}
				}
				
				//-----------------------------------------
				// BATCH CHECK MU LINKS
				//-----------------------------------------
				
				if($mu != "")
				{
					$pattern = '|\?d=\w{8}|';
					preg_match_all($pattern, $mu, $matches);
					$mu = "";
					for($j=0;$j<count($matches[0]);$j++)
					{
						$mu .= "id" . $j . "=" . substr($matches[0][$j],3) . "&";
					}
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL,"http://megaupload.com/mgr_linkcheck.php");
					curl_setopt($ch, CURLOPT_FAILONERROR, 1);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
					curl_setopt($ch, CURLOPT_TIMEOUT, 5);
					curl_setopt($ch, CURLOPT_POST, 1);
					curl_setopt($ch, CURLOPT_POSTFIELDS, "$mu");
					$result = curl_exec($ch);
					$header  = curl_getinfo( $ch );
					curl_close($ch);
					if($header['http_code'] == "200")
					{
						$pattern = '|id\d{1}=1|';
						preg_match_all($pattern, $result, $matches);
						$total_dead += count($matches[0]);
					}
					else
					{
						$total_errors++;
					}
				}

				//-------------------------------------------------
				// IF TOTAL LINKS > 0, TAKE SOME ACTION
				//-------------------------------------------------

				if($total_links > 0)
				{
					
					//-------------------------------------------------
					// IF DEAD LINKS % > THRESHOLD, TAKE SOME ACTION
					//-------------------------------------------------
					
					if(($total_dead/$total_links)*100 >= $this->settings['linkbot_threshold'])
					{
						if($this->settings['linkbot_action'] == "1")
						{	
							//-----------------------------------------
							// POST NOT FIRST POST OF TOPIC, UNAPPROVE
							//-----------------------------------------
							
							if($post['new_topic'] != "1")
							{
								$this->DB->update('posts', array( 'queued' => 1 ), 'pid = ' . $post['pid']);
								
								$pcount = $this->DB->buildAndFetch(array('select'=>'COUNT(pid) as posts','from'=>'posts','where'=>'queued!=1 AND topic_id='.$post['topic_id'])) - 1;
								
								$qpcount = $this->DB->buildAndFetch(array('select'=>'COUNT(pid) as posts','from'=>'posts','where'=>'queued=1 AND topic_id='.$post['topic_id']));
								
								$this->DB->update('topics', array( 'posts' => $pcount, 'topic_queuedposts' => $qpcount), 'tid='.$post['topic_id']);
								
								unset($qposts,$qpcount);
							}
							
							//----------------------------------------------------------
							// POST IS FIRST POST OF TOPIC, MOVE TOPIC AND ADD BOT REPLY
							//----------------------------------------------------------
					
							else
							{
								$this->ipbPost( $post['topic_id'], $this->settings['linkbot_member_id'], $this->settings['linkbot_reply_msg'] );
								if($this->ipbMoveTopic( $post['topic_id'], $this->settings['linkbot_trashcan_id'] )) {
									$this->class->appendTaskLog( $this->task, "Topic ID ".$post['topic_id']." was moved to the trash" );
								} else {
									$this->class->appendTaskLog( $this->task, "Topic ID ".$post['topic_id']." could not be moved to the trash. Please check your settings for the Link Bot are correct." );
								}
							}
						}
						
						//-----------------------------------------
						// REPORT POST
						//-----------------------------------------

						if($this->settings['linkbot_action'] == "2")
						{
							$this->request['message'] = "I found " . $total_dead . " dead link(s) in a post from the topic: ". $post['title'] ."<br/>[url=\"" . $this->settings['board_url'] ."/index.php?showtopic=". $post['topic_id'] ."&amp;view=findpost&amp;p=". $post['pid'] ."\"]Click here to view the post[/url]";
							if($this->ipbReportTopic( $topicID, $postID, $forumID )) {
								$this->class->appendTaskLog( $this->task, "Topic ID ".$post['topic_id']." was reported" );
							} else {
								$this->class->appendTaskLog( $this->task, "Topic ID ".$post['topic_id']." could not be reported. Please check that the LinkBot member id has full permissions on the forums." );
							}
						}
					}
					
					//-----------------------------------------
					// ADD BOT CHECK TIME TO POST
					//-----------------------------------------
					
					if($total_links>0) {
						$this->DB->update('posts', array('bot_msg'=>"'".time().",".$total_dead."'"), 'pid=' . $post['pid']);
					}
				}
				
				//-----------------------------------------
				// UPDATE GLOBAL VARIABLES AND CLEAR MEMORY
				//-----------------------------------------
				
				$total_urls += $total_links;
				$total_dead_urls += $total_dead;
				unset($total_links,$total_dead,$subject,$pattern,$matches,$link,$keyword,$ch,$result,$header,$post,$rs,$mu,$j);
			}
		}
		
		//--------------------------------------------------
		// UNLOCK TASK, CLOSE DB CONNECTION AND CLEAR MEMORY
		//--------------------------------------------------
		
		//-----------------------------------------
		// BOT FINISHED CHECKING
		//-----------------------------------------
		
		$this->class->appendTaskLog( $this->task, "Finished! Total Posts Scanned: " . $i . " Total Links Checked: " . $total_urls . " Total Dead: " . $total_dead_urls . " Errors: " . $total_errors );
		
		//-----------------------------------------
		// Unlock Task: DO NOT MODIFY!
		//-----------------------------------------
		
		$this->class->unlockTask( $this->task );
		
		unset($total_urls,$total_dead_urls,$total_errors,$i,$scan_first_option,$get_post_query,$post,$con,$allforums,$fid,$hosts);
	
	}
	
	/**
	* Add a post to existing topic in IP.Board
	*/
	function ipbPost( $topicID, $memberID, $post )
	{
		/* Init classPost */
		if(!$this->registry->isClassLoaded('linkBotPostClass')) {
			require( IPSLib::getAppDir('forums') . '/sources/classes/post/classPost.php' );
			$this->registry->setClass('linkBotPostClass', new classPost( $this->registry ));
		}
		
		/* Fetch Topic */
		$topicID = intval( $topicID );
		$topic = $this->DB->buildAndFetch( array( 'select' => '*', 'from' => 'topics', 'where' => "tid='{$topicID}'" ) );
		
		/* Fetch Forum */
		$forum = $this->registry->getClass('class_forums')->forum_by_id[ $topic['forum_id'] ];
		
		/* Set Variables */
		$this->registry->getClass('linkBotPostClass')->setTopicID( $topicID );
		$this->registry->getClass('linkBotPostClass')->setTopicData( $topic );
		$this->registry->getClass('linkBotPostClass')->setForumID( $forum['id'] );
		$this->registry->getClass('linkBotPostClass')->setForumData( $forum );
		$this->registry->getClass('linkBotPostClass')->setAuthor( $memberID );
		$this->registry->getClass('linkBotPostClass')->setPostContent( $post );
		$this->registry->getClass('linkBotPostClass')->setPublished( TRUE );
		
		/* Make Post */
		$this->registry->getClass('linkBotPostClass')->addReply();
	}
	
	/**
	* Move IP.Board Topic
	*/
	function ipbMoveTopic( $topicID, $moveto )
	{
		/* Init classPost */
		if(!$this->registry->isClassLoaded('linkBotModLib')) {
			require( IPSLib::getAppDir('forums') . '/sources/classes/moderate.php' );
			$this->registry->setClass('linkBotModLib', new moderatorLibrary( $this->registry ));
		}
		/* Fetch Topic */
		$topicID = intval( $topicID );
		return $this->registry->getClass('linkBotModLib')->topicMove($topicID, 0, $moveto, 0);
	}
	
	/**
	* Report IP.Board Topic
	*/
	function ipbReportTopic( $topicID, $postID, $forumID )
	{
		$this->request['topic_id'] = $topicID;
		$this->request['post_id'] = $postID;
		$this->request['forum_id'] = $forumID;
		//-----------------------------------------
		// Make sure we have an rcom
		//-----------------------------------------
		if( ! $topic_id || ! $post_id )
		{
			return false;
		}
		
		$rcom = 'post';

		if( !$rcom )
		{
			return false;
		}
		
		//-----------------------------------------
		// Request plugin info from database
		//-----------------------------------------

		$row = $this->DB->buildAndFetch( array( 'select' => '*', 'from' => 'rc_classes', 'where' => "my_class='{$rcom}' AND onoff=1" ) );
		
		if( !$row['com_id'] )
		{
			return false;
		}
		else
		{
			if(!$this->registry->isClassLoaded('reportNotifications')) {
				$classToLoad = IPSLib::loadLibrary( IPSLib::getAppDir('core') . '/sources/classes/reportNotifications.php', 'reportNotifications' );
				$this->registry->setClass( 'reportNotifications', new $classToLoad( $this->registry ) );
			}
			if(!$this->registry->isClassLoaded('reportLibrary')) {
				$classToLoad = IPSLib::loadLibrary( IPSLib::getAppDir('core') .'/sources/classes/reportLibrary.php', 'reportLibrary' );
				$this->registry->setClass( 'reportLibrary', new $classToLoad( $this->registry ) );
			}
			
			//-----------------------------------------
			// Let's get cooking! Load the plugin
			//-----------------------------------------
			
			$this->registry->getClass('reportLibrary')->loadPlugin( $row['my_class'], $row['app'] );
			
			if( !is_object($this->registry->getClass('reportLibrary')->plugins[ $row['my_class'] ]) )
			{
				return false;
			}
			
			//-----------------------------------------
			// Process 'extra data' for the plugin
			//-----------------------------------------
			
			if( $row['extra_data'] && $row['extra_data'] != 'N;' )
			{
				$this->registry->getClass('reportLibrary')->plugins[ $row['my_class'] ]->_extra = unserialize( $row['extra_data'] );
			}
			else
			{
				$this->registry->getClass('reportLibrary')->plugins[ $row['my_class'] ]->_extra = array();
			}
			
			//-----------------------------------------
			// Sending report... do necessary things
			//-----------------------------------------
			$actualMember = $this->memberData;
			
			$this->memberData = $this->bot_memberData;
			//-----------------------------------------
			// Sending report... do necessary things
			//-----------------------------------------
			
			$report_data = $this->registry->getClass('reportLibrary')->plugins[ $row['my_class'] ]->processReport( $row );
			
			$this->registry->getClass('reportLibrary')->updateCacheTime();
			
			//-----------------------------------------
			// Send out notfications...
			//-----------------------------------------
			
			$this->registry->getClass( 'reportNotifications' )->initNotify( $this->registry->getClass('reportLibrary')->plugins[ $row['my_class'] ]->getNotificationList( IPSText::cleanPermString( $row['mod_group_perm'] ), $report_data ), $report_data );
			$this->registry->getClass( 'reportNotifications' )->sendNotifications();
			
			$this->memberData = $actualMember;
			
			return true;
		}
	}
}