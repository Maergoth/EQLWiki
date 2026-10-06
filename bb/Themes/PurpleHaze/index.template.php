<?php
/**
 * Simple Machines Forum (SMF)
 *
 * @package SMF
 * @author Simple Machines https://www.simplemachines.org
 * @copyright 2022 Simple Machines and individual contributors
 * @license https://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 2.1.3
 */

/**
 * Initialize the template... mainly little settings.
 */
function template_init()
{
	global $settings, $txt;

	$settings['theme_version'] = '2.1';
	$settings['require_theme_strings'] = true;
	$settings['avatars_on_indexes'] = false;
	$settings['avatars_on_boardIndex'] = false;
	$settings['login_main_menu'] = false;

	$settings['page_index'] = array(
		'extra_before' => '<span class="pages">' . $txt['pages'] . '</span>',
		'previous_page' => '<span class="main_icons previous_page"></span>',
		'current_page' => '<span class="current_page">%1$d</span> ',
		'page' => '<a class="nav_page" href="{URL}">%2$s</a> ',
		'expand_pages' => '<span class="expand_pages" onclick="expandPages(this, {LINK}, {FIRST_PAGE}, {LAST_PAGE}, {PER_PAGE});"> ... </span>',
		'next_page' => '<span class="main_icons next_page"></span>',
		'extra_after' => '',
	);

	if (!isset($settings['disable_files']))
		$settings['disable_files'] = array();
}

/**
 * The main sub template above the content.
 */
function template_html_above()
{
	global $context, $scripturl, $txt, $modSettings, $auth_secret, $cookiename;

	$eqlInlineImageToken = '';

	if (!empty($context['user']['is_logged'])) {
		$cookieName = !empty($cookiename) ? $cookiename : 'SMFCookie912';
		$cookieData = !empty($_COOKIE[$cookieName]) ? json_decode($_COOKIE[$cookieName], true) : null;

		if (is_array($cookieData) && isset($cookieData[0], $cookieData[1])) {
			$memberId = (int) $cookieData[0];
			$cookieToken = (string) $cookieData[1];
			$secret = !empty($auth_secret) ? $auth_secret : '';

			if ($memberId > 0 && $cookieToken !== '' && $secret !== '') {
				$eqlInlineImageToken = hash_hmac(
					'sha256',
					$memberId . '|' . $cookieToken . '|' . gmdate('Ymd'),
					$secret
				);
			}
		}
	}

	loadCSSFile('https://use.fontawesome.com/releases/v6.1.2/css/all.css', array('external' => true));
	loadJavaScriptFile('themeswitch.js', array('minimize' => false));
	loadJavaScriptFile('eql_paste_image_upload.js', array('minimize' => false));

	echo '<!DOCTYPE html>
<html', $context['right_to_left'] ? ' dir="rtl"' : '', !empty($txt['lang_locale']) ? ' lang="' . str_replace("_", "-", substr($txt['lang_locale'], 0, strcspn($txt['lang_locale'], "."))) . '"' : '', '>
<head>
	<meta charset="', $context['character_set'], '">';

	template_css();

	echo '
<script>
	window.EQL_INLINE_IMAGE_CSRF = ', json_encode($eqlInlineImageToken), ';
</script>';

	template_javascript();

	echo '
	<title>', $context['page_title_html_safe'], '</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
	<meta http-equiv="Pragma" content="no-cache">
	<meta http-equiv="Expires" content="0">';

	foreach ($context['meta_tags'] as $meta_tag)
	{
		echo '
	<meta';

		foreach ($meta_tag as $meta_key => $meta_value)
			echo ' ', $meta_key, '="', $meta_value, '"';

		echo '>';
	}

	echo '
	<meta name="theme-color" content="#557EA0">';

	if (!empty($context['robot_no_index']))
		echo '
	<meta name="robots" content="noindex">';

	if (!empty($context['canonical_url']))
		echo '
	<link rel="canonical" href="', $context['canonical_url'], '">';

	echo '
	<link rel="help" href="', $scripturl, '?action=help">
	<link rel="contents" href="', $scripturl, '">', ($context['allow_search'] ? '
	<link rel="search" href="' . $scripturl . '?action=search">' : '');

	if (!empty($modSettings['xmlnews_enable']) && (!empty($modSettings['allow_guestAccess']) || $context['user']['is_logged']))
		echo '
	<link rel="alternate" type="application/rss+xml" title="', $context['forum_name_html_safe'], ' - ', $txt['rss'], '" href="', $scripturl, '?action=.xml;type=rss2', !empty($context['current_board']) ? ';board=' . $context['current_board'] : '', '">
	<link rel="alternate" type="application/atom+xml" title="', $context['forum_name_html_safe'], ' - ', $txt['atom'], '" href="', $scripturl, '?action=.xml;type=atom', !empty($context['current_board']) ? ';board=' . $context['current_board'] : '', '">';

	if (!empty($context['links']['next']))
		echo '
	<link rel="next" href="', $context['links']['next'], '">';

	if (!empty($context['links']['prev']))
		echo '
	<link rel="prev" href="', $context['links']['prev'], '">';

	if (!empty($context['current_board']))
		echo '
	<link rel="index" href="', $scripturl, '?board=', $context['current_board'], '.0">';

	echo $context['html_headers'];

	echo '
<script>
	document.addEventListener("DOMContentLoaded", function () {
		function rewriteForumAccountLinks() {
			document.querySelectorAll("a").forEach(function (link) {
				var text = (link.textContent || "").trim().toLowerCase();
				var href = link.getAttribute("href") || "";

				// Forum logout should use unified wiki+forum logout.
				if (
					text === "log out" ||
					text === "logout" ||
					href.indexOf("action=logout") !== -1 ||
					href.indexOf("sa=logout") !== -1
				) {
					link.setAttribute("href", "https://eqlwiki.com/eql_logout.php");
					link.removeAttribute("onclick");
					return;
				}

				// Forum account security/password settings should be managed by the wiki.
				if (
					text === "account settings" ||
					text === "account security" ||
					text === "two-factor authentication" ||
					text === "enable two-factor authentication" ||
					text === "change password" ||
					href.indexOf("action=profile;area=account") !== -1 ||
					href.indexOf("action=profile;area=tfa") !== -1 ||
					href.indexOf("area=account") !== -1 ||
					href.indexOf("area=tfa") !== -1
				) {
					link.setAttribute("href", "https://eqlwiki.com/index.php?title=Special:Preferences");
					link.removeAttribute("onclick");
					return;
				}
			});
		}

		rewriteForumAccountLinks();

		var observer = new MutationObserver(rewriteForumAccountLinks);
		observer.observe(document.body, {
			childList: true,
			subtree: true
		});
	});
</script>
</head>
<body id="', $context['browser_body_id'], '" class="action_', !empty($context['current_action']) ? $context['current_action'] : (!empty($context['current_board']) ? 'messageindex' : (!empty($context['current_topic']) ? 'display' : 'home')), !empty($context['current_board']) ? ' board_' . $context['current_board'] : '', '">
<div id="footerfix">';
}

/**
 * The upper part of the main template layer.
 */
function template_body_above()
{
	global $context, $settings, $scripturl, $txt, $modSettings, $maintenance;

	echo (!empty($settings['custom_forum_width'])) ? '<div id="header" style="max-width: ' . $settings['custom_forum_width'] . '">' : '<div id="header">';

	echo '
			<h1 class="forumtitle">
				<a href="https://eqlwiki.com/">
					', empty($context['header_logo_url_html_safe'])
						? '<img src="' . $settings['images_url'] . '/custom/logo.png" alt="Back to the wiki!" title="Back to the wiki!" >'
						: '<img src="' . $context['header_logo_url_html_safe'] . '" alt="Back to the wiki!" title="Back to the wiki!" >', '
				</a>
			</h1>';

	if ($context['allow_search'])
	{
		echo '
			<form id="search_form" class="floatright" action="', $scripturl, '?action=search2" method="post" accept-charset="', $context['character_set'], '">
				<input type="search" name="search" value="">&nbsp;';

		if (!empty($context['current_topic']))
			echo '
				<input type="hidden" name="sd_topic" value="', $context['current_topic'], '">';
		elseif (!empty($context['current_board']))
			echo '
				<input type="hidden" name="sd_brd" value="', $context['current_board'], '">';

		echo '
				<input type="submit" name="search2" value="', $txt['search'], '" class="button">
				<input type="hidden" name="advanced" value="0">
			</form>';
	}

	echo '
	<div class="theme-switch-wrapper">
		<label class="theme-switch" for="checkbox">
			<input type="checkbox" id="checkbox">
			<span class="slider round"></span>
		</label>
	</div>
	</div>';

	echo (!empty($settings['custom_forum_width'])) ? '<div id="wrapper" style="max-width: ' . $settings['custom_forum_width'] . '">' : '<div id="wrapper">';

	echo '
				<a class="mobile_user_menu">
					<span class="menu_icon"></span>
					<span class="text_menu">', $txt['mobile_user_menu'], '</span>
				</a>
				<div id="main_menu">
					<div id="mobile_user_menu" class="popup_container">
						<div class="popup_window description">
							<div class="popup_heading">', $txt['mobile_user_menu'], '
								<a href="javascript:void(0);" class="main_icons hide_popup"></a>
							</div>
							', template_menu(), '
						</div>
					</div>
				</div>';

	if (!empty($settings['social_icons_menu_enabled']))
	{
		echo '
		<div class="social-icons">';

		if(!empty($settings['facebook_url']))
			echo '
			<a href="', $settings['facebook_url'] , '" title="' , $txt['facebook_title']  , '"> <i class="fab fa-facebook"></i></a>';

		if(!empty($settings['twitter_url']))
			echo '
			<a href="', $settings['twitter_url'] , '" title="' , $txt['twitter_title']  , '"><i class="fab fa-twitter"></i></a>';

		if(!empty($settings['youtube_url']))
			echo '
			<a href="', $settings['youtube_url'] , '" title="' , $txt['youtube_title']  , '"><i class="fab fa-youtube"></i></a>';

		if(!empty($settings['twitch_url']))
			echo '
			<a href="', $settings['twitch_url'] , '" title="' , $txt['twitch_title']  , '"><i class="fab fa-twitch"></i></a>';

		if(!empty($settings['discord_url']))
			echo '
			<a href="', $settings['discord_url'] , '" title="' , $txt['discord_title']  , '"><i class="fab fa-discord"></i></a>';

		if(!empty($settings['linkedin_url']))
			echo '
			<a href="', $settings['linkedin_url'] , '" title="' , $txt['linkedin_title']  , '"><i class="fab fa-linkedin"></i></a>';

		if(!empty($settings['github_url']))
			echo '
			<a href="', $settings['github_url'] , '" title="' , $txt['github_title']  , '"><i class="fab fa-github"></i></a>';

		if(!empty($settings['rss_url']))
			echo '
			<a href="', $settings['rss_url'] , '" title="' , $txt['rss_title']  , '"><i class="fas fa-rss"></i></a>';

		echo '
		</div>';
	}

	echo '
		<div id="upper_section">
			<div id="inner_section">
				<div id="inner_wrap"', !$context['user']['is_logged'] ? ' class="hide_720"' : '', '>';

	$hasWikiLoginCookie = false;
	$hasValidWikiSession = false;
	$hasSmfCookie = false;

	foreach ($_COOKIE as $cookieName => $cookieValue) {
		if (strpos($cookieName, '_UserID') !== false || strpos($cookieName, 'mw_UserID') !== false) {
			$hasWikiLoginCookie = true;
		}

		if (strpos($cookieName, 'SMFCookie') !== false) {
			$hasSmfCookie = true;
		}
	}

	if ($hasWikiLoginCookie) {
		$wikiSessionBridgeUrl = (getenv('EQL_WIKI_URL') ?: 'https://eqlwiki.com') . '/wiki_session_bridge.php';
		$sharedSecret = (require dirname(__DIR__, 3) . '/BridgeSecrets.php')['forumSession'];

		$postData = 'secret=' . rawurlencode($sharedSecret);
		$headers = "Content-Type: application/x-www-form-urlencoded\r\n";

		if (!empty($_SERVER['HTTP_COOKIE'])) {
			$headers .= "Cookie: " . $_SERVER['HTTP_COOKIE'] . "\r\n";
		}

		$bridgeContext = stream_context_create([
			'http' => [
				'method'  => 'POST',
				'header'  => $headers,
				'content' => $postData,
				'timeout' => 5,
			],
		]);

		$bridgeResponse = @file_get_contents($wikiSessionBridgeUrl, false, $bridgeContext);
		$bridgeData = json_decode($bridgeResponse, true);

		if (is_array($bridgeData) && isset($bridgeData['status']) && $bridgeData['status'] === 'OK') {
			$hasValidWikiSession = true;
		}
	}

	if ($context['user']['is_logged'])
	{
		if (!$hasValidWikiSession)
			echo '
			<script>
				window.location.href = "/bb/smf_mediawiki_logout.php?return=" + encodeURIComponent(window.location.href);
			</script>';

		echo '
			<ul class="floatleft" id="top_info">
				<li>
					<a href="', $scripturl, '?action=profile"', !empty($context['self_profile']) ? ' class="active"' : '', ' id="profile_menu_top">';

		if (!empty($context['user']['avatar']))
			echo $context['user']['avatar']['image'];

		echo '<span class="textmenu">', $context['user']['name'], '</span></a>
					<div id="profile_menu" class="top_menu"></div>
				</li>';

		if ($context['allow_pm'])
			echo '
				<li>
					<a href="', $scripturl, '?action=pm"', !empty($context['self_pm']) ? ' class="active"' : '', ' id="pm_menu_top">
						<span class="main_icons inbox"></span>
						<span class="textmenu">', $txt['pm_short'], '</span>', !empty($context['user']['unread_messages']) ? '
						<span class="amt">' . $context['user']['unread_messages'] . '</span>' : '', '
					</a>
					<div id="pm_menu" class="top_menu scrollable"></div>
				</li>';

		echo '
				<li>
					<a href="', $scripturl, '?action=profile;area=showalerts;u=', $context['user']['id'], '"', !empty($context['self_alerts']) ? ' class="active"' : '', ' id="alerts_menu_top">
						<span class="main_icons alerts"></span>
						<span class="textmenu">', $txt['alerts'], '</span>', !empty($context['user']['alerts']) ? '
						<span class="amt">' . $context['user']['alerts'] . '</span>' : '', '
					</a>
					<div id="alerts_menu" class="top_menu scrollable"></div>
				</li>
				<li class="button_logout">
					<a href="https://eqlwiki.com/eql_logout.php">
						<span class="main_icons logout"></span>
						<span class="textmenu">Log out</span>
					</a>
				</li>
			</ul>';
	}
	elseif (empty($maintenance))
	{
		if ($hasValidWikiSession && !$hasSmfCookie)
			echo '
			<script>
				if (window.location.href.indexOf("mw_autologin_failed=1") === -1) {
					window.location.href = "/bb/smf_mediawiki_auto_login.php?return=" + encodeURIComponent(window.location.href);
				}
			</script>';

		if (!empty($settings['login_main_menu']))
		{
			echo '
			<ul class="floatleft">
				<li class="welcome">', sprintf($txt[$context['can_register'] ? 'welcome_guest_register' : 'welcome_guest'], $context['forum_name_html_safe'], $scripturl . '?action=login', 'return reqOverlayDiv(this.href, ' . JavaScriptEscape($txt['login']) . ', \'login\');', $scripturl . '?action=signup'), '</li>
			</ul>';
		}
		else
		{
			echo '
			<ul class="floatleft" id="top_info">
				<li class="welcome">
					', sprintf($txt['welcome_to_forum'], $context['forum_name_html_safe']), '
				</li>
				<li class="button_login">
					<a href="/bb/smf_mediawiki_auto_login.php?login=1&return=/bb/" class="open">
						<span class="main_icons login"></span>
						<span class="textmenu">Log in on EQL Wiki</span>
					</a>
				</li>';

			if ($context['can_register'])
				echo '
				<li class="button_signup">
					<a href="https://eqlwiki.com/index.php?title=Special:CreateAccount" class="open">
						<span class="main_icons regcenter"></span>
						<span class="textmenu">Create Wiki Account</span>
					</a>
				</li>';

			echo '
			</ul>';
		}
	}
	else
		echo '
			<ul class="floatleft welcome">
				<li>', sprintf($txt['welcome_guest'], $context['forum_name_html_safe'], $scripturl . '?action=login', 'return true;'), '</li>
			</ul>';

	echo '
					<div class="user">
						<time datetime="', smf_gmstrftime('%FT%TZ'), '">', $context['current_time'], '</time>';

	if ($context['user']['is_logged'])
		echo '
						<ul class="unread_links">
							<li>
								<a href="', $scripturl, '?action=unread" title="', $txt['unread_since_visit'], '">', $txt['view_unread_category'], '</a>
							</li>
							<li>
								<a href="', $scripturl, '?action=unreadreplies" title="', $txt['show_unread_replies'], '">', $txt['unread_replies'], '</a>
							</li>
						</ul>';

	echo '
					</div>
				</div>';

	theme_linktree();

	echo '
			</div><!-- #inner_section -->
		</div><!-- #upper_section -->
		<div id="content_section">
			<div id="main_content_section">';
}

/**
 * The stuff shown immediately below the main content, including the footer.
 */
function template_body_below()
{
	global $context, $txt, $settings, $scripturl, $modSettings;

	echo '
			</div><!-- #main_content_section -->
		</div><!-- #content_section -->
	</div><!-- #wrapper -->
</div><!-- #footerfix -->';

	echo '
	<div id="footer">';

	echo (!empty($settings['custom_forum_width'])) ? '<div class="inner_wrap" style="max-width: ' . $settings['custom_forum_width'] . '">' : '<div class="inner_wrap">';

	echo '
		<ul>
			<li class="floatright"><a href="', $scripturl, '?action=help">', $txt['help'], '</a> ', (!empty($modSettings['requireAgreement']) ? '| <a href="' . $scripturl . '?action=agreement">' . $txt['terms_and_rules'] . '</a>' : ''), ' | <a href="#top_section">', $txt['go_up'], ' &#9650;</a></li>
			<li class="copyright">', theme_copyright(), ' | <a href="https://www.simplemachines.org/community/index.php?action=profile;u=218416">PurpleHaze By: TwitchisMental</a> </li>
		</ul>';

	if ($context['show_load_time'])
		echo '
		<p>', sprintf($txt['page_created_full'], $context['load_time'], $context['load_queries']), '</p>';

	echo '
		</div>
	</div><!-- #footer -->';
}

/**
 * This shows any deferred JavaScript and closes out the HTML.
 */
function template_html_below()
{
	template_javascript(true);

	echo '
</body>
</html>';
}

/**
 * Show a linktree.
 */
function theme_linktree($force_show = false)
{
	global $context, $shown_linktree, $scripturl, $txt;

	if (empty($context['linktree']) || (!empty($context['dont_default_linktree']) && !$force_show))
		return;

	echo '
				<div class="navigate_section">
					<ul>';

	foreach ($context['linktree'] as $link_num => $tree)
	{
		echo '
						<li', ($link_num == count($context['linktree']) - 1) ? ' class="last"' : '', '>';

		if ($link_num != 0)
			echo '
							<span class="dividers">', $context['right_to_left'] ? ' &#9668; ' : ' &#9658; ', '</span>';

		if (isset($tree['extra_before']))
			echo $tree['extra_before'], ' ';

		if (isset($tree['url']))
			echo '
							<a href="' . $tree['url'] . '"><span>' . $tree['name'] . '</span></a>';
		else
			echo '
							<span>' . $tree['name'] . '</span>';

		if (isset($tree['extra_after']))
			echo ' ', $tree['extra_after'];

		echo '
						</li>';
	}

	echo '
					</ul>
				</div><!-- .navigate_section -->';

	$shown_linktree = true;
}

/**
 * Show the menu up top.
 */
function template_menu()
{
	global $context;

	if (!empty($context['menu_buttons']['logout'])) {
		$context['menu_buttons']['logout']['href'] = 'https://eqlwiki.com/eql_logout.php';
		unset($context['menu_buttons']['logout']['onclick']);
	}

	if (!empty($context['menu_buttons']['profile']['sub_buttons'])) {
		foreach ($context['menu_buttons']['profile']['sub_buttons'] as $key => &$button) {
			if (
				$key === 'logout' ||
				(isset($button['href']) && strpos($button['href'], 'action=logout') !== false) ||
				(isset($button['title']) && strtolower(strip_tags($button['title'])) === 'log out')
			) {
				$button['href'] = 'https://eqlwiki.com/eql_logout.php';
				unset($button['onclick']);
			}
		}
		unset($button);
	}

	echo '
					<ul class="dropmenu menu_nav">';

	foreach ($context['menu_buttons'] as $act => $button)
	{
		echo '
						<li class="button_', $act, '', !empty($button['sub_buttons']) ? ' subsections"' : '"', '>
							<a', $button['active_button'] ? ' class="active"' : '', ' href="', $button['href'], '"', isset($button['target']) ? ' target="' . $button['target'] . '"' : '', isset($button['onclick']) ? ' onclick="' . $button['onclick'] . '"' : '', '>
								', $button['icon'], '<span class="textmenu">', $button['title'], !empty($button['amt']) ? ' <span class="amt">' . $button['amt'] . '</span>' : '', '</span>
							</a>';

		if (!empty($button['sub_buttons']))
		{
			echo '
							<ul>';

			foreach ($button['sub_buttons'] as $childbutton)
			{
				echo '
								<li', !empty($childbutton['sub_buttons']) ? ' class="subsections"' : '', '>
									<a href="', $childbutton['href'], '"', isset($childbutton['target']) ? ' target="' . $childbutton['target'] . '"' : '', isset($childbutton['onclick']) ? ' onclick="' . $childbutton['onclick'] . '"' : '', '>
										', $childbutton['title'], !empty($childbutton['amt']) ? ' <span class="amt">' . $childbutton['amt'] . '</span>' : '', '
									</a>';

				if (!empty($childbutton['sub_buttons']))
				{
					echo '
									<ul>';

					foreach ($childbutton['sub_buttons'] as $grandchildbutton)
						echo '
										<li>
											<a href="', $grandchildbutton['href'], '"', isset($grandchildbutton['target']) ? ' target="' . $grandchildbutton['target'] . '"' : '', isset($grandchildbutton['onclick']) ? ' onclick="' . $grandchildbutton['onclick'] . '"' : '', '>
												', $grandchildbutton['title'], !empty($grandchildbutton['amt']) ? ' <span class="amt">' . $grandchildbutton['amt'] . '</span>' : '', '
											</a>
										</li>';

					echo '
									</ul>';
				}

				echo '
								</li>';
			}

			echo '
							</ul>';
		}

		echo '
						</li>';
	}

	echo '
					</ul><!-- .menu_nav -->';
}

/**
 * Generate a strip of buttons.
 */
function template_button_strip($button_strip, $direction = '', $strip_options = array())
{
	global $context, $txt;

	if (!is_array($strip_options))
		$strip_options = array();

	$buttons = array();

	foreach ($button_strip as $key => $value)
	{
		if (!isset($value['test']) || !empty($context[$value['test']]))
		{
			if (!isset($value['id']))
				$value['id'] = $key;

			$button = '
				<a class="button button_strip_' . $key . (!empty($value['active']) ? ' active' : '') . (isset($value['class']) ? ' ' . $value['class'] : '') . '" ' . (!empty($value['url']) ? 'href="' . $value['url'] . '"' : '') . ' ' . (isset($value['custom']) ? ' ' . $value['custom'] : '') . '>'.(!empty($value['icon']) ? '<span class="main_icons '.$value['icon'].'"></span>' : '').'' . $txt[$value['text']] . '</a>';

			if (!empty($value['sub_buttons']))
			{
				$button .= '
					<div class="top_menu dropmenu ' . $key . '_dropdown">
						<div class="viewport">
							<div class="overview">';

				foreach ($value['sub_buttons'] as $element)
				{
					if (isset($element['test']) && empty($context[$element['test']]))
						continue;

					$button .= '
								<a href="' . $element['url'] . '"><strong>' . $txt[$element['text']] . '</strong>';

					if (isset($txt[$element['text'] . '_desc']))
						$button .= '<br><span>' . $txt[$element['text'] . '_desc'] . '</span>';

					$button .= '</a>';
				}

				$button .= '
							</div><!-- .overview -->
						</div><!-- .viewport -->
					</div><!-- .top_menu -->';
			}

			$buttons[] = $button;
		}
	}

	if (empty($buttons))
		return;

	echo '
		<div class="buttonlist', !empty($direction) ? ' float' . $direction : '', '"', (empty($buttons) ? ' style="display: none;"' : ''), (!empty($strip_options['id']) ? ' id="' . $strip_options['id'] . '"' : ''), '>
			', implode('', $buttons), '
		</div>';
}

/**
 * Generate a list of quickbuttons.
 */
function template_quickbuttons($list_items, $list_class = null, $output_method = 'echo')
{
	global $txt;

	if (!empty($list_class))
		call_integration_hook('integrate_' . $list_class . '_quickbuttons', array(&$list_items));

	foreach ($list_items as $key => $li)
	{
		if ($key == 'more')
		{
			foreach ($li as $subkey => $subli)
				if (isset($subli['show']) && !$subli['show'])
					unset($list_items[$key][$subkey]);

			if (empty($list_items[$key]))
				unset($list_items[$key]);
		}
		elseif (isset($li['show']) && !$li['show'])
			unset($list_items[$key]);
	}

	if (empty($list_items))
		return;

	$output = '
		<ul class="quickbuttons' . (!empty($list_class) ? ' quickbuttons_' . $list_class : '') . '">';

	$list_item_format = function($li)
	{
		$html = '
			<li' . (!empty($li['class']) ? ' class="' . $li['class'] . '"' : '') . (!empty($li['id']) ? ' id="' . $li['id'] . '"' : '') . (!empty($li['custom']) ? ' ' . $li['custom'] : '') . '>';

		if (isset($li['content']))
			$html .= $li['content'];
		else
			$html .= '
				<a href="' . (!empty($li['href']) ? $li['href'] : 'javascript:void(0);') . '"' . (!empty($li['javascript']) ? ' ' . $li['javascript'] : '') . '>
					' . (!empty($li['icon']) ? '<span class="main_icons ' . $li['icon'] . '"></span>' : '') . (!empty($li['label']) ? $li['label'] : '') . '
				</a>';

		$html .= '
			</li>';

		return $html;
	};

	foreach ($list_items as $key => $li)
	{
		if ($key == 'more')
		{
			$output .= '
			<li class="post_options">
				<a href="javascript:void(0);">' . $txt['post_options'] . '</a>
				<ul>';

			foreach ($li as $subli)
				$output .= $list_item_format($subli);

			$output .= '
				</ul>
			</li>';
		}
		else
			$output .= $list_item_format($li);
	}

	$output .= '
		</ul><!-- .quickbuttons -->';

	if ($output_method == 'echo')
		echo $output;
	else
		return $output;
}

/**
 * The upper part of the maintenance warning box.
 */
function template_maint_warning_above()
{
	global $txt, $context, $scripturl;

	echo '
	<div class="errorbox" id="errors">
		<dl>
			<dt>
				<strong id="error_serious">', $txt['forum_in_maintenance'], '</strong>
			</dt>
			<dd class="error" id="error_list">
				', sprintf($txt['maintenance_page'], $scripturl . '?action=admin;area=serversettings;' . $context['session_var'] . '=' . $context['session_id']), '
			</dd>
		</dl>
	</div>';
}

/**
 * The lower part of the maintenance warning box.
 */
function template_maint_warning_below()
{

}

?>