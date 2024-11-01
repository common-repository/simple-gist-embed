<?php
/*
Plugin Name: Simple Gist Embed
Plugin URI: http://en.bainternet.info
Description: Embed Gist in your post with ease.
Version: 1.2
Author: bainternet
Author URI: http://en.bainternet.info
*/
/*
		* 	Copyright (C) 2011  Ohad Raz
		*	http://en.bainternet.info
		*	admin@bainternet.info

		This program is free software; you can redistribute it and/or modify
		it under the terms of the GNU General Public License as published by
		the Free Software Foundation; either version 2 of the License, or
		(at your option) any later version.

		This program is distributed in the hope that it will be useful,
		but WITHOUT ANY WARRANTY; without even the implied warranty of
		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
		GNU General Public License for more details.

		You should have received a copy of the GNU General Public License
		along with this program; if not, write to the Free Software
		Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/* Disallow direct access to the plugin file */
if (basename($_SERVER['PHP_SELF']) == basename (__FILE__)) {
	die('Sorry, but you cannot access this page directly.');
}

class gist_embed {
	
	protected $style_added = false;
	
 	/**
 	 * __construct class constructor
 	 */
	function __construct() {
		global $pagenow;
		add_shortcode('gist', array($this, 'handle_shortcode'));
		//tinymce button
		add_filter( 'mce_buttons', array($this,'Add_custom_buttons' ));
		add_filter( 'tiny_mce_before_init', array($this,'Insert_custom_buttons' ));
		//ajax functions
		add_action('wp_ajax_gist_ajax_tb', array($this,'load_tb'));
		add_action('wp_ajax_get_my_gists_ajax',array($this,'aj_get_list'));
		add_action('wp_ajax_create_gist',array($this,'ajax_create_gist'));
		add_action('wp_ajax_delete_cached_gist',array($this,'ajax_delete_cached_gist'));
		
		if (is_admin() && ($pagenow=='post-new.php' OR $pagenow=='post.php')){
			add_action('admin_head',array($this,'declare_gist_load'));
			add_action('admin_enqueue_scripts',array($this,'enqueue_tabs'));
		}
	}
 
	function handle_shortcode($atts,$content = null) {
		
		global $post;
		
		$saved_gist = get_post_meta($post->ID,'_simple_gist',true);

		if (!empty($saved_gist)){
			if (isset($saved_gist[trim($atts['id'])])){
				if ($this->style_added !== true){
					$this->style_added = true;
					$dir = plugin_dir_url(__FILE__);
					$suffix = ( defined( 'SCRIPT_DEBUG' ) AND SCRIPT_DEBUG ) ? '' : '.min';
					return $saved_gist[trim($atts['id'])]['syntax'].'<style type="text/css">@import url("'.$dir.'css/gist-embed'.$suffix.'.css"); .gistem .highlight {background: inherit; !important;}</style>';
				}
					return $saved_gist[trim($atts['id'])]['syntax'];
			}
		}
		
		$url = 'https://gist.github.com/' . trim($atts['id']).'.json';
		$json = $this->get_gist($url);
		
		$assoc = json_decode($json, true);
		$assoc['div'] = str_replace ('brought to you by <a href="http://github.com">GitHub</a>','is brought to you using <a href="http://en.bainternet.info/2011/simple-gist-embed"><small>Simple Gist Embed</small></a>' ,$assoc['div']);
		

		
		//$assoc['div'] = preg_replace('/<div class="gist\-meta">.*?(<\/div>)/is', '', $assoc['div']);
		
		
		//cache gist
		$saved_gist[trim($atts['id'])]  = array('id' => $assoc['repo'],'description' => $assoc['description'],'syntax' => '<div class="gistem">'.$assoc['div'].'</div>');
		update_post_meta($post->ID,'_simple_gist',$saved_gist);
		if ($this->style_added !== true){
			$this->style_added = true;
			$dir = plugin_dir_url(__FILE__);
			$suffix = ( defined( 'SCRIPT_DEBUG' ) AND SCRIPT_DEBUG ) ? '' : '.min';
			return $saved_gist[trim($atts['id'])]['syntax'].'<style type="text/css">@import url("'.$dir.'css/gist-embed'.$suffix.'.css"); .gistem .highlight {background: inherit; !important;}</style>';
		}
		return $saved_gist[trim($atts['id'])]['syntax'];
	}
	
		
	//set buttons
	public function Insert_custom_buttons( $initArray ){
		$initArray['setup'] = <<<JS
[function(ed) {
    ed.addButton('Gist', {
        title : 'Gist',
        image : 'http://i.imgur.com/NKLic.png',
        onclick : function() {
			load_gist_ajax(); 
        }
    });
}][0]
JS;
		return $initArray;
	}
	
	//add buttons
	public function Add_custom_buttons( $mce_buttons ){
		$mce_buttons[] = 'Gist';
		return $mce_buttons;
	}
	
	
	//ajax load tinymce dialog
	public function load_tb(){
		?>
		<div id="tabs">
			<ul>
				<li><a href="#tabs-1">My Gists</a></li>
				<li><a href="#tabs-2">New Gist</a></li>
				<li><a href="#tabs-3">Cached Gists</a></li>
				<li><a href="#tabs-4">About</a></li>
			</ul>
			<div id="tabs-1" style="padding: 0px !important;">
				<h2>Select From Your Gists List</h2>
				<div class="get_list">
		<?php
		$github = get_option('simple_gist');
		if (!empty($github)){
			if (!isset($github['list'])){
				$gists = $this->get_users_gists($github['username']);
				$my_gists = json_decode($gists,true);
				$re = '<table cellpadding="8px" style="border: 1px solid #000 !important; background-color: #FFFFFF;"><tr style="border: 1px solid #000 !important;"><td style="border: 1px solid #000 !important;">Gist ID</td><td style="border: 1px solid #000 !important;">Description</td><td style="border: 1px solid #000 !important;">Actions</td></tr>';
				foreach($my_gists as $gist){
					$re .= '<tr style="border: 1px solid #000 !important;"><td style="border: 1px solid #000 !important;">'.$gist['id'].'</td><td style="border: 1px solid #000 !important;">'.$gist['description'].'</td><td style="border: 1px solid #000 !important;">
					<a target="_blank" href="'.$gist['html_url'].'">
					<img alt="preview" title="preview" src="http://i.imgur.com/ftKWq.png" width="16px" height="16px"></a> - <img src="http://i.imgur.com/ONW2n.png" width="16px" height="16px" alt="insert to post" title="insert to post" class="insert_to_post" gist_id="'.$gist['id'].'"></td></tr>';
				}
				$re .=  '</table>
					<br/> <input type="submit" value="Update List" id="update_my_list">';
				echo $re;
			}else{
				echo $github['list'];
			}
		}else{
			?>	
					<form method="POST">
						<p><label for="github_user1">GitHub Username :</label><br />
							<input id="github_user1" name="github_user1" type="text" />
						</p>
						<input type="hidden" name="action" value="list_gist" />
						<input type="submit" value="Get List" id="get_my_list">
					</form>
					

			<?php
		}
		?>
				<div class="mylist_status" style="display: none;"></div>
			</div>
			</div>
			<div class="create_gist_Form" id="tabs-2">
				<h2>create a new gist</h2>
				<form name="create_gist_Form" method="post">
				<input type="hidden" name="action" value="create_gist" />
				<p><label for="gist_description">Gist description :</label><br />
					<input id="gist_description" name="gist_description" type="text"  style="width: 97%;" />
				</p>
				<p><label for="file_ext">Language :</label><br />
					<select name="file_ext" id="file_ext">
						<option value=".txt">Plain Text</option>
						<option value=".as">ActionScript</option>
						<option value=".c">C</option>
						<option value=".cs">C#</option>
						<option value=".cpp">C++</option>
						<option value=".lisp">Common Lisp</option>
						<option value=".css">CSS</option>
						<option value=".diff">Diff</option>
						<option value=".el">Emacs Lisp</option>
						<option value=".erl">Erlang</option>
						<option value=".hs">Haskell</option>
						<option value=".html">HTML</option>
						<option value=".java">Java</option>
						<option value=".js">JavaScript</option>
						<option value=".lua">Lua</option>
						<option value=".m">Objective-C</option>
						<option value=".pl">Perl</option>
						<option value=".php">PHP</option>
						<option value=".py">Python</option>
						<option value=".rb">Ruby</option>
						<option value=".scala">Scala</option>
						<option value=".scm">Scheme</option>
						<option value=".sql">SQL</option>
						<option value=".tex">TeX</option>
						<option value=".xml">XML</option>
						<option>
					---    </option>
						<option value=".adb">Ada</option>
						<option value=".scpt">AppleScript</option>
						<option value=".arc">Arc</option>
						<option value=".asp">ASP</option>
						<option value=".asm">Assembly</option>
						<option value=".ahk">AutoHotkey</option>
						<option value=".bat">Batchfile</option>
						<option value=".befunge">Befunge</option>
						<option value=".bmx">BlitzMax</option>
						<option value=".boo">Boo</option>
						<option value=".b">Brainfuck</option>
						<option value=".c-objdump">C-ObjDump</option>
						<option value=".ck">ChucK</option>
						<option value=".clj">Clojure</option>
						<option value=".cmake">CMake</option>
						<option value=".coffee">CoffeeScript</option>
						<option value=".cfm">ColdFusion</option>
						<option value=".cppobjdump">Cpp-ObjDump</option>
						<option value=".feature">Cucumber</option>
						<option value=".pyx">Cython</option>
						<option value=".d">D</option>
						<option value=".d-objdump">D-ObjDump</option>
						<option value=".darcspatch">Darcs Patch</option>
						<option value=".pas">Delphi</option>
						<option value=".dylan">Dylan</option>
						<option value=".e">Eiffel</option>
						<option value=".fs">F#</option>
						<option value=".factor">Factor</option>
						<option value=".fy">Fancy</option>
						<option value=".f90">FORTRAN</option>
						<option value=".s">GAS</option>
						<option value=".kid">Genshi</option>
						<option value=".ebuild">Gentoo Ebuild</option>
						<option value=".eclass">Gentoo Eclass</option>
						<option value=".po">Gettext Catalog</option>
						<option value=".go">Go</option>
						<option value=".gs">Gosu</option>
						<option value=".man">Groff</option>
						<option value=".groovy">Groovy</option>
						<option value=".gsp">Groovy Server Pages</option>
						<option value=".haml">Haml</option>
						<option value=".hx">HaXe</option>
						<option value=".mustache">HTML+Django</option>
						<option value=".erb">HTML+ERB</option>
						<option value=".phtml">HTML+PHP</option>
						<option value=".cfg">INI</option>
						<option value=".io">Io</option>
						<option value=".ik">Ioke</option>
						<option value=".weechatlog">IRC log</option>
						<option value=".jsp">Java Server Pages</option>
						<option value=".json">JSON</option>
						<option value=".ly">LilyPond</option>
						<option value=".lhs">Literate Haskell</option>
						<option value=".ll">LLVM</option>
						<option value=".mak">Makefile</option>
						<option value=".mao">Mako</option>
						<option value=".md">Markdown</option>
						<option value=".matlab">Matlab</option>
						<option value=".mxt">Max/MSP</option>
						<option value=".minid">MiniD</option>
						<option value=".duby">Mirah</option>
						<option value=".moo">Moocode</option>
						<option value=".mu">mupad</option>
						<option value=".myt">Myghty</option>
						<option value=".n">Nemerle</option>
						<option value=".nim">Nimrod</option>
						<option value=".nu">Nu</option>
						<option value=".numpy">NumPy</option>
						<option value=".objdump">ObjDump</option>
						<option value=".j">Objective-J</option>
						<option value=".ml">OCaml</option>
						<option value=".ooc">ooc</option>
						<option value=".cl">OpenCL</option>
						<option value=".parrot">Parrot</option>
						<option value=".pasm">Parrot Assembly</option>
						<option value=".pir">Parrot Internal Representation</option>
						<option value=".pl">Prolog</option>
						<option value=".pd">Pure Data</option>
						<option value=".pytb">Python traceback</option>
						<option value=".r">R</option>
						<option value=".rkt">Racket</option>
						<option value=".raw">Raw token data</option>
						<option value=".r">Rebol</option>
						<option value=".cw">Redcode</option>
						<option value=".rst">reStructuredText</option>
						<option value=".rhtml">RHTML</option>
						<option value=".rs">Rust</option>
						<option value=".sass">Sass</option>
						<option value=".self">Self</option>
						<option value=".sh">Shell</option>
						<option value=".st">Smalltalk</option>
						<option value=".tpl">Smarty</option>
						<option value=".sml">Standard ML</option>
						<option value=".sc">SuperCollider</option>
						<option value=".tcl">Tcl</option>
						<option value=".tcsh">Tcsh</option>
						<option value=".textile">Textile</option>
						<option value=".twig">Twig</option>
						<option value=".vala">Vala</option>
						<option value=".v">Verilog</option>
						<option value=".vhd">VHDL</option>
						<option value=".vim">VimL</option>
						<option value=".vb">Visual Basic</option>
						<option value=".xq">XQuery</option>
						<option value=".xs">XS</option>
						<option value=".yml">YAML</option>
					</select>
				</p>
				<p><label for="file-contents">The Gist :</label><br />
					<textarea id="file_contents" name="file_contents" style="width: 97%;"></textarea>
				</p>
				<p><label for="file-Gist_Status">Make Gist :</label><br />
					<select id="Gist_Status">
					
						<option value="1" selected="selected">Public</option>
						<option value="0">Private</option>
					</select>
				</p>
				<p><label for="github_user">GitHub Username :</label><br />
					<input id="github_user" name="github_user" type="text" /></p>
				<p><label for="github_pass">GitHub Password :</label><br />
					<input id="github_pass" name="github_pass" type="password" /></p>
				<div>
					<input id="Creat_new_gist" type="submit" value="Create a new Gist" />
				</div>
				</form>    
				<div class="new_gist_status" style="display: none"></div>
			</div>
			<div class="saved_gists" id="tabs-3">
				<div class="cached_gs">
				<?php
				$cpid = $_REQUEST['cpid'];
				if (isset($cpid))
					echo $this->get_cached_gists($cpid);
				?>
				</div>
				<div class="delete_status" style="display: none">&nbsp;</div>
				
			</div>
			<div class="about" id="tabs-4">
				<ul style="list-style: square inside none; width: 300px; font-weight: bolder; padding: 20px; border: 2px solid; background-color: #FFFFE0; border-color: #E6DB55;">
					<li> Any feedback or suggestions are welcome at <a href="http://en.bainternet.info/2011/simple-gist-embed">plugin homepage</a></li>
					<li> <a href="http://wordpress.org/tags/simple-gist-embed/?forum_id=10">Support forum</a> for help and bug submittion</li>
					<li> Also check out <a href="http://en.bainternet.info/category/plugins">my other plugins</a></li>
					<li> And if you like my work <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=PPCPQV8KA3UQA"><img src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!"></a></li>
				</ul>
			</div>
		</div>
		<script>
		jQuery(document).ready(function($) {
			//enable tabs
			$(function() {
				$( "#tabs" ).tabs({ cache: false });
				//.tabs( "destroy" )
			});
			//get list
			$("#get_my_list").live('click', function() {
				if ($("#github_user1").val() == ''){
					alert('You must specifiy your github username.');
				}else{
					var data = {
						action: 'get_my_gists_ajax',
						user: $("#github_user1").val()
					};
					$(".mylist_status").html('<p>Getting Gists list...</p>');
					$(".mylist_status").show('fast');
					$.post(ajaxurl, data, function(response) {
						$(".get_list").html(response);
						$(".mylist_status").html('<p>Done</p>');
						$(".mylist_status").show('fast');
						$(".mylist_status").hide('5222');
					});
					
				}
				return false;
			});
			//update list
			$("#update_my_list").live('click', function() {
					var data = {
						action: 'get_my_gists_ajax',
					};
					$(".mylist_status").html('<p>Updating Gists list...</p>');
					$(".mylist_status").show('fast');
					$.post(ajaxurl, data, function(response) {
						$(".get_list").html(response);
						alert('Done!');
						$(".mylist_status").hide('5222');
					});
				return false;
			});
			
			//Creat_new_gist
			$("#Creat_new_gist").live('click', function() {
				//minor validation:
				var new_error = '';
				if($("#gist_description").val() == ''){
					new_error = 'Please add a short description to your Gist \n';
				}
				if($("#file_ext").val() == ''){
					new_error = new_error + 'Please select your Gist type \n';
				}
				if($("#github_user").val() == ''){
					new_error = new_error + 'Please enter your Github username \n';
				}
				if($("#github_pass").val() == ''){
					new_error = new_error + 'Please enter your Github password \n';
				}
				if($("#file_contents").val() == ''){
					new_error = new_error + 'Please Add something to your Gist \n';
				}
				if (new_error.length > 1){
					alert(new_error);
					return false;
				}
				
				
				var data = {
					action: 'create_gist',
					gist_description: $("#gist_description").val(),
					file_ext: $("#file_ext").val(),
					file_contents: $("#file_contents").val(),
					Gist_Status: $("#Gist_Status").val(),
					github_user: $("#github_user").val(),
					github_pass: $("#github_pass").val()
				};
				$(".new_gist_status").html('<p>Creating a new Gist...</p>');
				$(".new_gist_status").show('fast');
				$.post(ajaxurl, data, function(response) {
					alert(response);
					$(".new_gist_status").hide('6000');
				});
				return false;
			});
			//insert shortcode
			$(".insert_to_post").live('click', function() {
			//[gist id="1164863" nometa="true"]
				var shortcode = '';
				shortcode = '[gist id="' + $(this).attr("gist_id") + '"]';
				tinyMCE.activeEditor.execCommand('mceInsertContent', 0, shortcode);
				$("#dialog").dialog( "close" );
				return false;
			});
			
			$(".Delete_c_Gist").live('click', function() {
				var data = {
					action: 'delete_cached_gist',
					gist_id: $(this).attr('gist_id'),
					post_id: $(this).attr('cpid')
				};
				$(".delete_status").html('<p>Crearing From Cache...</p>');
				$(".delete_status").show("fast");
				$.post(ajaxurl, data, function(response) {
					$(".cached_gs").html(response).show('1500');;
					alert("Done!");
					$(".delete_status").hide("1500");
				});
			});
		});
		</script>
		<?php
		die();
	}
	
	//js function for button panel
	public function declare_gist_load(){
		global $post;
		?>
		<script>
		
			var cpost_id = <?php echo $post->ID; ?>;
			function load_gist_ajax(){
				var url = 'admin-ajax.php?action=gist_ajax_tb&cpid=' + cpost_id;
				var dialog = jQuery("#dialog");
				if (jQuery("#dialog").length == 0) {
					dialog = jQuery('<div id="dialog" style="display:hidden"><Loading...</div>').appendTo('body');
					dialog.dialog({ title: 'Simple Gist Embed', modal: true, show: 'slide', minWidth: 500, minHeight: 400 });
					// load remote content
					dialog.load(
						url,
						{},
						function(responseText, textStatus, XMLHttpRequest) {
							dialog.dialog({ title: 'Simple Gist Embed', modal: true, show: 'slide', minWidth: 500, minHeight: 400 });
						}
					);
				}else{
					jQuery("#dialog").dialog("open");
				}
			}
		
		</script>
		<?php
	}
	
	//ajax users gist list
	public function aj_get_list(){
		if (isset($_POST['action']) && $_POST['action'] == 'get_my_gists_ajax' && isset($_POST['user'])){
				$user = $_POST['user'];
		}else{
			$github = get_option('simple_gist');
			$user = $github['username'];
		}
		
		$gists = $this->get_users_gists($user);
			$my_gists = json_decode($gists,true);
			$re = '<table cellpadding="8px" style="background-color: #FFFFFF; border: 1px solid #000 !important;"><tr style="border: 1px solid #000 !important;"><td style="border: 1px solid #000 !important;">Gist ID</td><td style="border: 1px solid #000 !important;">Description</td><td style="border: 1px solid #000 !important;">Actions</td></tr>';
			foreach($my_gists as $gist){
				$re .= '<tr style="border: 1px solid #000 !important;"><td style="border: 1px solid #000 !important;">'.$gist['id'].'</td><td style="border: 1px solid #000 !important;">'.$gist['description'].'</td><td style="border: 1px solid #000 !important;">
				<a target="_blank" href="'.$gist['html_url'].'"><img alt="preview" title="preview" src="http://i.imgur.com/ftKWq.png" width="16px" height="16px"></a> - <img class="insert_to_post" href="#" gist_id="'.$gist['id'].'" src="http://i.imgur.com/ONW2n.png" width="16px" height="16px" alt="insert to post" title="insert to post"></td></tr>';
			}
			$re .=  '</table>';
		$re .=  '<br/> <input type="submit" value="Update List" id="update_my_list">';
		$github = get_option('simple_gist');
		$github['username'] = $user;
		$github['list'] = $re;
		echo $re;
		update_option('simple_gist',$github);
		die();
	}
	
	//get users gist list
	public function get_users_gists($user){
		
		$url = 'https://api.github.com/users/'.$user.'/gists';
		
		/* using WordPress HTTP API */
		$response = wp_remote_get( $url,array(
			'sslverify' => false,
			'timeout' => 30,
		));

		if( is_wp_error( $response ) ) {
		   return 'Something went wrong!';
		} else {
		   return $response['body'];
		}
	}
	
	//ajax create gist
	public function ajax_create_gist(){
		$results = $this->create_json_post();
		$gist = json_decode($results,true);
		if (isset ($gist['message'])){
			echo ''.$gist['message'].'';
			die();
		}else{
			echo 'Gist Created and can now be selected from your list, 
			if its not in the list just update the list.';
		}
	}
	
	// post gist create
	function create_json_post(){
		if (!isset($_POST['action']) || $_POST['action'] != 'create_gist')
			return json_encode(array('message'=>'error'));
		
		//set POST variables
		$url = 'https://api.github.com/gists';
		$fields = array(
				'description'=> stripslashes($_POST['gist_description']),
				'public' => true,
				'files'=> array(
					'file1'.$_POST['file_ext'] => array('content' =>stripslashes($_POST['file_contents']))
					)
				);
		if (isset($_POST['Gist_Status']) && $_POST['Gist_Status'] == 0 ){
			if(isset($_POST['github_user']) && isset($_POST['github_pass'])){
				$fields['public'] = false;
			}
		}

		$data = json_encode($fields);
		$username = $_POST['github_user'];
		$password =  $_POST['github_pass'];
		
		/* using WordPress HTTP API */
		$headers = array( 'Authorization' => 'Basic ' . base64_encode( "$username:$password" ),'Content-Type' => 'application/json; charset=utf-8');
		$result = wp_remote_post( $url, array(
		'method' => 'POST',
		'timeout' => 35,
		'redirection' => 5,
		'httpversion' => '1.0',
		'blocking' => true,
		'sslverify' => false,
		'body' => $data,
		'headers' => $headers ) 
		);
	
		if( is_wp_error( $result ) ) {
			return 'Something went wrong!';
		} else {
			return $result['body'];
		}
	}
	
	//delete cached gist
	public function ajax_delete_cached_gist(){
		$gist_id = (int)$_POST['gist_id'];
		$post_id = (int)$_POST['post_id'];
		$gists = get_post_meta($post_id,'_simple_gist',true);
		if (isset($gists[$gist_id])){
			unset($gists[$gist_id]);
		}
		update_post_meta($post_id,'_simple_gist',$gists);
		echo $this->get_cached_gists($post_id);
		die();
	}
	
	//get cached gists list
	public function get_cached_gists($post_id){
		$saved_gist = get_post_meta($post_id,'_simple_gist',true);
		$re ='';
		if (!empty($saved_gist)){
			$re ='<table cellpadding="8px" style="border: 1px solid #000 !important; background-color: #FFFFFF;" ><tr style="border: 1px solid #000 !important;"><td style="border: 1px solid #000 !important;">Gist ID</td><td style="border: 1px solid #000 !important;">Description</td><td style="border: 1px solid #000 !important;">Actions</td></tr>';
			foreach((array)$saved_gist as $gi){
				$re .= '<tr style="border: 1px solid #000 !important;"><td style="border: 1px solid #000 !important;">'.$gi['id'].'</td><td style="border: 1px solid #000 !important;">'.$gi['description'].'</td><td style="border: 1px solid #000 !important;"><a href="https://gist.github.com/'.$gi['id'].'" target="_blank"><img alt="preview" title="preview" src="http://i.imgur.com/ftKWq.png" width="16px" height="16px"></a> - <img src="http://i.imgur.com/N8n6i.png" title="delete cache" gist_id="'.$gi['id'].'" cpid="'.$post_id.'" class="Delete_c_Gist"></td></tr>';
			}
			$re .= '</table>';
			$re .= '<input type="button" class="clear_all_cache" value="Clear All"/>';
		}else{
			$re .= 'Nothing chached Yet for this post!';
		}
		return $re;
	}
	
	//get Gist
	public function get_gist($url){
		$response = wp_remote_get( $url,array(
		'sslverify' => false,
		'timeout' => 30,
		));
		return $response['body'];
		
	}
	
	//enqueue tabs / dialog
	public function enqueue_tabs(){
		$dir = plugin_dir_url(__FILE__);
		wp_enqueue_style('jquery-ui', $dir.'css/jquery-ui.css');
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'jquery-ui-core' );
		wp_enqueue_script( 'jquery-ui-tabs' );
		wp_enqueue_script( 'jquery-ui-dialog' );
	}
}//end class
 
$gist = new gist_embed();