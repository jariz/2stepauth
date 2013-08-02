<?php
/**
 * JARIZ.PRO
 * Date: 25/07/13
 * Time: 21:34
 * Author: JariZ
 */

// Disallow direct access to this file for security reasons
if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

//Login/register shit
$plugins->add_hook("member_do_login_end", "twostepauth_login");
$plugins->add_hook("member_do_register_end", "twostepauth_register");

//UserCP shit
$plugins->add_hook("usercp_start", "twostepauth_usercp_start");
$plugins->add_hook("usercp_menu", "twostepauth_usercp_menu");
$plugins->add_hook("datahandler_user_update", "twostepauth_user_update");
$plugins->add_hook("datahandler_user_validate", "twostepauth_user_validate");
$plugins->add_hook("global_start", "twostepauth_global_start");
$plugins->add_hook("global_end", "twostepauth_global_end");

function twostepauth_info()
{
    global $mybb;
    $info =  array(
        "name" => "<img src=\"{$mybb->settings["bburl"]}/images/lockfolder.gif\"/> 2StepAuth",
        "description" => "A plugin that provides basic 2 step authentication trough Google Authenticator and E-mail.",
        "website" => "http://jariz.pro",
        "author" => "Youtubelers.com",
        "authorsite" => "http://youtubelers.com",
        "version" => "1.0",
        "guid" => "",
        "compatibility" => "*"
    );
    if(twostepauth_is_installed()) $info["description"] .= "<a style='color:#C00; float:right;position: relative;top: -5px;' href='index.php?module=config-plugins&action=deactivate&uninstall=1&plugin=twostepauth&my_post_key={$mybb->post_code}&harakiri=1' onclick=\"if(confirm('Are you sure this is what you want? This will remove ALL AUTHORIZATIONS and 2stepauth user settings, theres no way to recover this ever again. If you want to uninstall the plugin normally, use the uninstall option on the right.')) return confirm('Totally sure? This will remove every trace of 2stepauth ever existing and theres no way to ever get your data back.'); else return false;\">Harakiri</a>";
    return $info;
}

/**
 * INSTALL SHIT
 */

function twostepauth_install()
{
    global $db, $config, $page, $mybb;

    if (!isset($config["2stepauth_secret_encryption_key"])) {
        //ok, the encryption key does not exist. can we write it into the config?
        if (!is_writable(MYBB_ROOT . "inc/config.php")) {
            //show epic custom error page
            twostepauth_admin_error("inc/config.php is not writable.", "<strong>In order to install 2stepauth, this file must be writeable.</strong><br>The installation was aborted.");
        } else {
            //attempt to generate & add our sup0r secret key to the config
            $key = random_str(40);
            $cfile = fopen(MYBB_ROOT . "inc/config.php", "at");
            if (fwrite($cfile, "\n<?\n/**\n * The encryption key used to encrypt all 2stepauth secret tokens. If you change this, all your users will be unable to use 2 step authorization.\n */\n\n\$config[\"2stepauth_secret_encryption_key\"] = \"{$key}\";\n?>") === FALSE)
                twostepauth_admin_error("Unable to write to inc/config.php", "2StepAuth was unable to write it's encryption key to your config file.<br>Your config file might be damaged.<br>The installation was aborted.");
            fclose($cfile);
            //set key temporarily for the rest of the installation
            $mybb->config["2stepauth_secret_encryption_key"] = $key;
        }
    }

    if (!$db->table_exists("twostepauth_authorizations"))
        $db->query("CREATE TABLE `" . TABLE_PREFIX . "twostepauth_authorizations` (
`id`  int NULL AUTO_INCREMENT ,
`ip`  varchar(255) NOT NULL ,
`location` TEXT NOT NULL ,
`code`  int(6) NOT NULL ,
`uid`  int NOT NULL ,
PRIMARY KEY (`id`)
);");

    if(!$db->table_exists("twostepauth_authorization_keys"))
        $db->query("CREATE TABLE `".TABLE_PREFIX."twostepauth_authorization_keys` (
`id`  int NULL AUTO_INCREMENT ,
`uid`  int NULL ,
`key`  text NULL ,
PRIMARY KEY (`id`)
);");

    if (!$db->field_exists("twostepauth_secret", "users"))
        $db->query("ALTER TABLE " . TABLE_PREFIX . "users ADD `twostepauth_secret` TEXT NOT NULL default ''");

    if (!$db->field_exists("twostepauth_enabled", "users"))
        $db->query("ALTER TABLE " . TABLE_PREFIX . "users ADD `twostepauth_enabled` INT(1) NOT NULL default '0'");

    if (!$db->field_exists("twostepauth_hide_hint", "users"))
        $db->query("ALTER TABLE " . TABLE_PREFIX . "users ADD `twostepauth_hide_hint` INT(1) NOT NULL default '0'");

    if (!$db->field_exists("twostepauth_method", "users"))
        $db->query("ALTER TABLE " . TABLE_PREFIX . "users ADD `twostepauth_method` INT(1) NOT NULL default '1'");

    //give secrets to users that don't have them yet
    $auth = new PHPGangsta_GoogleAuthenticator();
    $empties = $db->simple_select("users", "uid", "twostepauth_secret = ''");
    while ($empty = $db->fetch_array($empties)) {
        $db->update_query("users", array("twostepauth_secret" => twostepauth_encrypt($auth->createSecret())), "uid = " . $empty["uid"]);
    }

    // Insert settings in to the database
    $query = $db->query("SELECT disporder FROM " . TABLE_PREFIX . "settinggroups ORDER BY `disporder` DESC LIMIT 1");
    $disporder = $db->fetch_field($query, 'disporder') + 1;

    $setting_group = array(
        'name' => 'twostepauth_settings',
        'title' => '2StepAuth',
        'description' => 'Settings to customize the 2StepAuth system.',
        'disporder' => intval($disporder),
        'isdefault' => 0
    );
    $db->insert_query('settinggroups', $setting_group);
    $gid = $db->insert_id();

    $settings = array(
        'force' => array(
            'title' => 'Force users to use 2StepAuth',
            'description' => 'Normally users can choose if they want to use 2StepAuth, this option forces users to use the system, and also removes the choice if enabling/disabling 2StepAuth',
            'optionscode' => 'onoff',
            'value' => '0'),
        'geoplugin' => array(
            'title' => 'Look up IP locations?',
            'description' => 'Look up user IPs with geoplugin.net (Example: Amsterdam, The Netherlands)',
            'optionscode' => 'onoff',
            'value' => '1'),
        'hint' => array(
            'title' => 'Show notice encouraging users to enable 2StepAuth?',
            'description' => 'This will show a message to all users notifying them of the 2 step authorization option in their user CP\'s. They can dismiss this message.',
            'optionscode' => 'onoff',
            'value' => '1')
    );

    $x = 1;
    foreach ($settings as $name => $setting) {
        $insert_settings = array(
            'name' => $db->escape_string("twostepauth_" . $name),
            'title' => $db->escape_string($setting['title']),
            'description' => $db->escape_string($setting['description']),
            'optionscode' => $db->escape_string($setting['optionscode']),
            'value' => $db->escape_string($setting['value']),
            'disporder' => $x,
            'gid' => $gid,
            'isdefault' => 0
        );
        $db->insert_query('settings', $insert_settings);
        $x++;
    }
}

function twostepauth_templates() {
    $usercp_twostepauth = <<<USERCP
<html>
<head>
    <title>{\$mybb->settings['bbname']} - {\$lang->setup_2stepauth}</title>
    {\$headerinclude}
    <script>
    Event.observe(document, "dom:loaded", function() {
        $("2sa_enable").observe("click", function(x) {
            if($("twostepauth_enable").checked) $("twostepauth_enabled_options").show();
            else $("twostepauth_enabled_options").hide();
        });
        $("twostepauth_method_input").observe("change", function() {
            if($("twostepauth_method_input").value == "1") $("twostepauth_qr").show();
            else $("twostepauth_qr").hide();
        });
    });

    </script>
    <style>
    .app-button {
        background-image:url('http://i.imgur.com/MsYfoap.png');
        width:132px;
        height:42px;
        background-color:#919191;
        border-radius:5px;
        display:inline-block;
        background-position:50% 5px;
        background-repeat:no-repeat;
    }

    .app-button:hover {
    background-color:#5E5E5E;
    }

    .app-button.play-store {
        background-position: 50% -54px;
    }
    </style>
</head>
<body>
{\$header}
<form action="usercp.php" method="post">
    <input type="hidden" name="my_post_key" value="{\$mybb->post_code}"/>
    <table width="100%" border="0" align="center">
        <tr>
            {\$usercpnav}
            <td valign="top">
                {\$errors}
                <table border="0" cellspacing="{\$theme['borderwidth']}" cellpadding="{\$theme['tablespace']}"
                       class="tborder">
                    <tr>
                        <td class="thead" colspan="2"><strong>{\$lang->setup_2stepauth}</strong></td>
                    </tr>
                    <tr>
                        <td width="50%" class="trow1" valign="top">
                            <fieldset class="trow2">
                                <legend><strong>{\$lang->twostepauth_enable}</strong></legend>
                                <table cellspacing="0" cellpadding="2">
                                    <tr id="2sa_enable">
                                        <td valign="top" width="1">
                                            <input type="checkbox" class="checkbox" id="twostepauth_enable" name="twostepauth_enable" {\$twostepauth_enable} value="1"/>
                                        </td>
                                        <td><span class="smalltext"><label for="twostepauth_enable">{\$lang->twostepauth_enable}</label></span></td>
                                    </tr>
                                </table>
                            </fieldset>

                            <div id="twostepauth_enabled_options"{\$options_show}>
                            <fieldset class="trow2">
                                <legend><strong>{\$lang->twostepauth_method}</strong></legend>
                                <table cellspacing="0" cellpadding="2">
                                    <tr>
                                        <td valign="top" width="1">
                                            <select name="twostepauth_method" id="twostepauth_method_input">
                                                <option value="1"{\$method1_selected}>{\$lang->twostepauth_method1}</option>
                                                <option value="2"{\$method2_selected}>{\$lang->twostepauth_method2}</option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                            </fieldset>

                            <fieldset class="trow2" id="twostepauth_qr"{\$qr_show}>
                                <legend><strong>{\$lang->twostepauth_qr}</strong></legend>
                                <table cellspacing="0" cellpadding="2">
                                    <tr>
                                        <td>
                                            <img src="\$qr"/>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><p>{\$lang->twostepauth_qr_explanation}</p></td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" class="app-button play-store"></a>
                                            <a href="https://itunes.apple.com/en/app/google-authenticator/id388497605" class="app-button app-store"></a>
                                        </td>
                                    </tr>
                                </table>
                            </fieldset>
                            </div>
                            <br/>

                            <div align="center">
                                <input type="hidden" name="action" value="do_2stepauth"/>
                                <input type="submit" class="button" name="regsubmit" value="{\$lang->update_twostepauth}"/>
                            </div>
                            <br>
                            </form>
                        </td>
                        <td width="50%" class="trow1" valign="top">
                            <fieldset class="trow2">
                                <legend><strong>{\$lang->twostepauth_authorizations}</strong></legend>
                                <table border="0" cellspacing="1" cellpadding="4" class="tborder">
                                    <tr>
                                        <td class="tcat">IP address</td>
                                        <td class="tcat">Location</td>
                                        <td class="tcat" style="20px"></td>
                                    </tr>
                                    {\$rows}
                                </table>
                            </fieldset>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>


    <!-- note: if you remove this, you will violate maxmind's license: http://www.maxmind.com/download/geoip/database/LICENSE.txt -->
    <!-- remove at your own risk, i, the creator of 2stepauth am not responsible for the consequences -->
    <span class="smalltext float_right" style="display: block; padding-right: 2px; text-align:right;">{\$lang->twostepauth_maxmind} <a href="http://www.maxmind.com">maxmind.com</a><br>
    <!-- kind of a bitch move to remove this, but go ahead if you really need to... -->
    {\$lang->twostepauth_credits} <a href="http://youtubelers.com">youtubelers.com</a></span>
    <br>
{\$footer}
</body>
</html>
USERCP;

    $twostepauth_authorize = <<<AUTHORIZE
<html>
<head>
    <title>{\$mybb->settings['bbname']} - {\$lang->twostepauth}</title>
    {\$headerinclude}
</head>
<body>
{\$header}

{\$twostepauth_error}
<table border="0" cellspacing="1" cellpadding="4" class="tborder">
<tr>
<td class="thead"><span class="smalltext"><strong>{\$lang->twostepauth}</strong></span></td>
</tr>
<tr>
<td class="trow1">
<p>{\$lang->twostepauth_enter_code}<br><a href="{\$mybb->settings['contactlink']}">{\$lang->twostepauth_contact}</a></p>
</td>
</tr>
<tr>
<td class="trow1">
<form action="member.php" method="post">
<input type="text" class="textbox" name="twostepauth" maxlength="6" style="width: 136px;font-size: 40px;margin: 0 auto;display: block;margin-top: 10px;">
<input type="hidden" name="action" value="do_login" />
<input type="hidden" name="username" value="{\$username}" />
<input type="hidden" name="password" value="{\$password}" />
<input type="hidden" name="url" value="{\$redirect_url}" />
<br>
<input type="submit" value="{\$lang->twostepauth_authorize}" style="display:block;margin:0 auto;margin-bottom: 10px;">
</form>
</td>
</tr>
</table>

{\$footer}
</body>
</html>
AUTHORIZE;

    $twostepauth_authorize_email = <<<AUTHORIZE

<html>
<head>
    <title>{\$mybb->settings['bbname']} - {\$lang->twostepauth}</title>
    {\$headerinclude}
</head>
<body>
{\$header}

{\$twostepauth_error}
<table border="0" cellspacing="1" cellpadding="4" class="tborder">
<tr>
<td class="thead"><span class="smalltext"><strong>{\$lang->twostepauth}</strong></span></td>
</tr>
<tr>
<td class="trow1">
<p>{\$lang->twostepauth_email_send}<br><a href="{\$mybb->settings['contactlink']}">{\$lang->twostepauth_contact}</a></p>
</td>
</tr>
<tr>
<td class="trow1">
<form action="member.php" method="post">
<input type="text" class="textbox" name="twostepauth_email" maxlength="10" style="width: 220px;font-size: 40px;margin: 0 auto;display: block;margin-top: 10px;">
<input type="hidden" name="action" value="do_login" />
<input type="hidden" name="username" value="{\$username}" />
<input type="hidden" name="password" value="{\$password}" />
<input type="hidden" name="url" value="{\$redirect_url}" />
<br>
<input type="submit" value="{\$lang->twostepauth_authorize}" style="display:block;margin:0 auto;margin-bottom: 10px;">
</form>
</td>
</tr>
</table>

{\$footer}
</body>
</html>
AUTHORIZE;
    
    $twostepauth_authorize_email_from_link = <<<AUTHORIZE
<html>
<head>
<title>{\$mybb->settings['bbname']} - {\$lang->twostepauth}</title>
{\$headerinclude}
</head>
<body>
{\$header}
<br />
<form action="member.php" method="post"><table border="0" cellspacing="1" cellpadding="4" class="tborder">
<tbody><tr>
<td class="thead" colspan="2"><strong>{\$lang->twostepauth}</strong></td>
</tr>
<tr>
<td class="trow1" colspan="2">{\$lang->twostepauth_please_reenter_login_info}</td>
</tr>
<tr>
<td class="trow1"><strong>{\$lang->username}</strong></td>
<td class="trow1"><input type="text" class="textbox" name="username" size="25" maxlength="30" style="width: 200px;" value=""></td>
</tr>
<tr>
<td class="trow2"><strong>{\$lang->password}</strong><br><span class="smalltext">{\$lang->pw_note}</span></td>
<td class="trow2"><input type="password" class="textbox" name="password" size="25" style="width: 200px;" value=""></td>
</tr>
<tr>
<td class="trow2"><strong>{\$lang->twostepauth_authorization_code}:</strong><br><span class="smalltext">{\$lang->twostepauth_authnote}</span></td>
<td class="trow2"><input type="text" class="textbox" name="twostepauth_email" maxlength="10" value="{\$activation_code}" style="width: 200px;"></td>
</tr>
</tbody></table>
<br />
<div align="center"><input type="submit" class="button" name="submit" value="{\$lang->login}" /></div>
<input type="hidden" name="action" value="do_login" />
<input type="hidden" name="url" value="{\$redirect_url}" />
</form>
{\$footer}
</body>
</html>
AUTHORIZE;

    
    $usercp_twostepauth_row = <<<ROW
<tr>
    <td class="trow1">
        {\$ip}
    </td>
    <td class="trow1">
        {\$location}
    </td>
    <td class="trow1">
        <!-- because of XSS, we use a form here -->
        <form action="usercp.php" method="post">
            <input type="hidden" name="ip" value="{\$ip}">
            <input type="hidden" name="action" value="do_2stepauth_delete">
            <input type="hidden" name="my_post_key" value="{\$mybb->post_code}"/>
            <input type="submit" style="display:block; cursor:pointer; text-indent:-9999px; width:16px; height:16px; border:none; background:url(/images/invalid.gif);">
        </form>
    </td>
</tr>
ROW;

    $twostepauth_hint = <<<HINT
<div class="red_alert" style="text-align: left; color: #3a87ad;  background-color: #d9edf7;border-color: #6CDBF1;padding:5px 10px;">
{\$lang->twostepauth_hint} <a href="/usercp.php?action=2stepauth&hide_notice=1&my_post_key={\$mybb->post_code}">{\$lang->twostepauth_hint_link}</a>
<a style='float:right' href="/usercp.php?action=2stepauth&hide_notice=1&my_post_key={\$mybb->post_code}&redir_back=1">&times;</a>
</div>
HINT;

    $twostepauth_email = <<<EMAIL
<p>{\$lang->twostepauth_email1} <a href="{\$mybb->settings['bburl']}">{\$mybb->settings['bbname']}</a>{\$lang->twostepauth_email2} {\$ip} ({\$location}) {\$lang->twostepauth_email3} <a href="{\$mybb->settings['bburl']}/usercp.php?action=password">{\$lang->twostepauth_email4}</a></p>
<p>{\$lang->twostepauth_email5} <a href="{\$activation_link}">{\$lang->twostepauth_email6}</a></p>
<p>{\$lang->twostepauth_email7} {\$activation_code} </p>
EMAIL;

    $twostepauth_email_plain = <<<EMAIL
{\$lang->twostepauth_email1} {\$mybb->settings['bbname']}{\$lang->twostepauth_email2} {\$ip} (\$location} {\$lang->twostepauth_email3} {\$lang->twostepauth_email4}: {\$mybb->settings['bburl']}/usercp.php?action=password
{\$lang->twostepauth_email5} {\$lang->twostepauth_email6}: {\$activation_link}
{\$lang->twostepauth_email7} {\$activation_code}
EMAIL;

    $usercp_nav_2stepauth= "<tr><td class=\"trow1 smalltext\"><a href=\"usercp.php?action=2stepauth\" class=\"usercp_nav_item\" style=\"background:url('images/lockfolder.gif') no-repeat left center\">{\$lang->nav_usercp_2stepauth}</a></td></tr>";

    $templates = array(
        "usercp_twostepauth" => $usercp_twostepauth,
        "twostepauth_authorize" => $twostepauth_authorize,
        "usercp_twostepauth_row" => $usercp_twostepauth_row,
        "twostepauth_hint" => $twostepauth_hint,
        "twostepauth_email" => $twostepauth_email,
        "twostepauth_email_plain" => $twostepauth_email_plain,
        "twostepauth_authorize_email" => $twostepauth_authorize_email,
        "usercp_nav_2stepauth" => $usercp_nav_2stepauth,
        "twostepauth_authorize_email_from_link" => $twostepauth_authorize_email_from_link
    );

    return $templates;
}

function twostepauth_activate()
{
    global $db;

    $info = twostepauth_info();

    $templates = twostepauth_templates();

    foreach ($templates as $template_title => $template_data) {
        $insert_templates = array(
            'title' => $db->escape_string($template_title),
            'template' => $db->escape_string($template_data),
            'sid' => "-1",
            'version' => $info['intver'],
            'dateline' => TIME_NOW
        );
        $db->insert_query('templates', $insert_templates);
    }
}

function twostepauth_uninstall()
{
    global $db, $mybb;

    // Remove settings
    $result = $db->simple_select('settinggroups', 'gid', "name = 'twostepauth_settings'", array('limit' => 1));
    $group = $db->fetch_array($result);

    if (!empty($group['gid'])) {
        $db->delete_query('settinggroups', "gid='{$group['gid']}'");
        $db->delete_query('settings', "gid='{$group['gid']}'");
        rebuild_settings();
    }


    //user has made the 'harakiri' choice, meaning he wants to remove every trace of twostepauth ever existing
    if($mybb->input["harakiri"] == "1") {
        if ($db->field_exists('twostepauth_enabled', 'users'))
            $db->query("ALTER TABLE " . TABLE_PREFIX . "users DROP column `twostepauth_enabled`");

        if ($db->field_exists('twostepauth_secret', 'users'))
            $db->query("ALTER TABLE " . TABLE_PREFIX . "users DROP column `twostepauth_secret`");

        if ($db->field_exists('twostepauth_hide_hint', 'users'))
            $db->query("ALTER TABLE " . TABLE_PREFIX . "users DROP column `twostepauth_hide_hint`");

        if ($db->field_exists('twostepauth_method', 'users'))
            $db->query("ALTER TABLE " . TABLE_PREFIX . "users DROP column `twostepauth_method`");

        if ($db->table_exists("twostepauth_authorizations"))
            $db->drop_table("twostepauth_authorizations");

        if ($db->table_exists("twostepauth_authorization_keys"))
            $db->drop_table("twostepauth_authorization_keys");

        $config = MYBB_ROOT."inc/config.php";
        $fc = fopen($config, "r");
        //this'll probably never happen because entire mybb can't run if the config isn't readable, but just for the sake of code logic
        if(!$fc) twostepauth_admin_error("inc/config.php is not readable", "inc/config.php is not readable, you'll have to uncomment the last value yourself.");
        $new_config = "";
        foreach(explode("\n", fread($fc, filesize($config))) as $line) {
            if(substr($line, 0, 42) == "\$config[\"2stepauth_secret_encryption_key\"]") $new_config .= "//".$line."\n";
            else $new_config .= $line."\n";
        }
        fclose($fc);
        $fc2 = fopen($config, "w");
        if(!$fc) twostepauth_admin_error("inc/config.php is not writable", "inc/config.php is not writable, you'll have to uncomment the last value yourself.");
        fwrite($fc2, $new_config);
        fclose($fc2);
    }
}

function twostepauth_deactivate()
{
    global $db;

    $templates = twostepauth_templates();

    foreach($templates as $template_title => $template_data) {
        $db->delete_query("templates", "title='{$template_title}'");
    }
}

function twostepauth_is_installed()
{
    global $db;

    $result = $db->simple_select('settinggroups', 'gid', "name = 'twostepauth_settings'", array('limit' => 1));
    $group = $db->fetch_array($result);

    if (!empty($group['gid'])) return true;

    return false;
}

/**
 * SECRET/VERIFCATION SHIT
 */

function twostepauth_global_start() {
    global $templatelist;
    //template caching
    if(isset($templatelist))
    {
        $templatelist .= ',';
    }

    switch(THIS_SCRIPT) {
        case "usercp.php":
            $templatelist .= "usercp_twostepauth,usercp_twostepauth_row";
            break;
        case "member.php":
            $templatelist .= "twostepauth_authorize,twostepauth_authorize_email,twostepauth_email,twostepauth_authorize_email_from_link";
            break;
        default:
            $templatelist .= "twostepauth_hint";
    }
}

function twostepauth_global_end() {
    global $mybb, $plugins, $lang, $templates, $session, $headerinclude, $theme, $header, $footer, $navigation, $db;

    $lang->load("twostepauth");

    //hint injection
    if($mybb->user["uid"] && $mybb->settings["twostepauth_hint"] == '1' && $mybb->user["twostepauth_enabled"] == "0" && $mybb->user["twostepauth_hide_hint"] != "1" && THIS_SCRIPT != "usercp.php")
    {
        eval("\$hint = \"{$templates->get("twostepauth_hint")}\";");
        $templates->cache["header"] .= $hint;
        $header .= $hint;
    }

    //our evil member.php 'fake' hook
    if(THIS_SCRIPT == "member.php")
        twostepauth_member();

    if(!$mybb->user["uid"]) return;
    if(!isset($mybb->user["twostepauth_enabled"])) return;
    if($mybb->user["twostepauth_enabled"] != "1") return;

    //this function runs a query every time to see if this ip is still allowed to be on this uid. if not, it forces the user to gtfo.
    //resource expensive, but i sadly see no other way.

    if(!twostepauth_allowed($mybb->user["uid"])) {
        //gtfo time!

        my_unsetcookie("mybbuser");
        my_unsetcookie("sid");

        $time = TIME_NOW;
        $lastvisit = array(
            "lastactive" => $time-900,
            "lastvisit" => $time,
        );
        $db->update_query("users", $lastvisit, "uid='".$mybb->user['uid']."'");
        $db->delete_query("sessions", "sid='".$session->sid."'");

        $plugins->run_hooks("member_logout_end");

       error($lang->twostepauth_force_logged_out, $lang->twostepauth_force_logged_out_title);
    }

}

function twostepauth_member() {
    global $mybb, $footer, $header, $navigation, $headerinclude, $themes, $lang, $templates;
    $lang->load("twostepauth");
    if($mybb->input["action"] != "2stepauth_email")
        return false;
    if(ctype_digit($mybb->input["key"]) && strlen($mybb->input["key"]) == 10) {
        $activation_code = $mybb->input["key"];
        eval("output_page(\"{$templates->get("twostepauth_authorize_email_from_link")}\");");
        exit;
    }
}

function twostepauth_register()
{
    global $user_info, $db, $mybb;
    $google_auth = new PHPGangsta_GoogleAuthenticator();
    $sec = $google_auth->createSecret();
    $db->update_query("users", array("twostepauth_secret" => twostepauth_encrypt($sec), "twostepauth_enabled" => $mybb->settings["twostepauth_force"]), "uid = '{$user_info['uid']}'");
    twostepauth_authorize_ip(get_ip(), $user_info["uid"], $google_auth->getCode($sec));
}


function twostepauth_login()
{
    global $user, $db, $mybb, $footer, $header, $navigation, $headerinclude, $themes, $templates, $usercpnav, $lang, $session;

    if($user == null) return;
    $user_info = $db->fetch_array($db->simple_select("users", "*", "uid = ".$user["uid"]));
    if($user_info["twostepauth_enabled"] != "1") return;

    $lang->load("twostepauth");

    //atm the user has already logged in and mybb has set the set-cookie headers.
    //if this user actually is authorized, we'll let it pass, else we'll remove the set-cookies and ask for a code.
    $ip = get_ip();

    if(!twostepauth_allowed($user["uid"])) {
        //INTRUDER!

        //did he enter this form already?
        if(ctype_digit($mybb->input["twostepauth"]) && strlen($mybb->input["twostepauth"]) == 6 && $user_info["twostepauth_method"] == "1") {

            $gauth = new PHPGangsta_GoogleAuthenticator();
            $secret = twostepauth_decrypt($user_info["twostepauth_secret"]);
            if($mybb->input["twostepauth"] == $gauth->getCode($secret)) {
                //did we authorize this code already? (2 times the same code isn't allowed)
                $code_used = $db->fetch_field($db->simple_select("twostepauth_authorizations", "COUNT(*) as count", "uid = '{$user["uid"]}' AND code = '{$db->escape_string($mybb->input["twostepauth"])}'"), "count");
                if($code_used > 0)
                    $twostepauth_error = $lang->twostepauth_invalid_code;
                else {
                    twostepauth_authorize_ip($ip, $user["uid"], $mybb->input["twostepauth"]);
                    return;
                }
            } else $twostepauth_error = $lang->twostepauth_invalid_code;
        } elseif(ctype_digit($mybb->input["twostepauth_email"]) && strlen($mybb->input["twostepauth_email"]) == 10 && $user_info["twostepauth_method"] == "2") {
            $res = $db->fetch_array($db->simple_select("twostepauth_authorization_keys", "id", "uid = '{$user_info["uid"]}' AND `key` = '{$db->escape_string($mybb->input["twostepauth_email"])}'"));
            $dont_send_email = true;
            if($res == null) $twostepauth_error = $lang->twostepauth_invalid_code;
            else {
                $db->delete_query("twostepauth_authorization_keys", "id = {$res["id"]}");
                twostepauth_authorize_ip($ip, $user["uid"], $mybb->input["twostepauth_email"]);
                return;
            }
        } elseif(strlen($mybb->input["twostepauth_email"]) > 0) {
            $dont_send_email = true;
            $twostepauth_error = $lang->twostepauth_invalid_code;
        }

        if(!isset($twostepauth_error)) $twostepauth_error = "";


        //cancel log in
        $time = TIME_NOW;
        $user_data = array(
            "lastactive" => $time-900,
            "lastvisit" => $time,
            "loginkey" => generate_loginkey() //new loginkey, different than the one already send
        );
        $db->update_query("users", $user_data, "uid='".$user_info['uid']."'");
        $db->delete_query("sessions", "sid='".$session->sid."'");

        my_unsetcookie("mybbuser");
        my_unsetcookie("sid");

        //set params
        $username = $mybb->input["username"];
        $password = $mybb->input["password"];
        $redirect_url = $mybb->input["url"];

        //send email?
        if($user_info["twostepauth_method"] == "2" && !isset($dont_send_email)) {
            $ip = get_ip();
            $location = twostepauth_get_location($ip);
            $activation_code = my_rand(1000000000, 9999999999);
            $activation_link = $mybb->settings["bburl"]."/member.php?action=2stepauth_email&key=".$activation_code;
            eval("\$mail = \"{$templates->get("twostepauth_email")}\";");
            eval("\$mail_plain = \"{$templates->get("twostepauth_email_plain")}\";");

            my_mail(
                $user_info["email"],
                str_replace("{\$bbname}", $mybb->settings["bbname"], str_replace("{\$loc}", $location, $lang->twostepauth_email_subject)),
                $mail,
                "", "", "", false, "both", $mail_plain
            );

            $db->insert_query("twostepauth_authorization_keys", array("uid" => $user_info["uid"], "key" => $activation_code));
        }

        //dump page
        if($user_info["twostepauth_method"] == "1") eval("\$output = \"" . $templates->get("twostepauth_authorize") . "\";");
        else eval("\$output = \"" . $templates->get("twostepauth_authorize_email") . "\";");
        output_page($output);
        exit;
    }
}

/**
 * USERCP SHIT
 */

function twostepauth_usercp_menu()
{
    global $lang, $templates;
    $lang->load("twostepauth");
    eval("\$template = \"".$templates->get("usercp_nav_2stepauth")."\";");
    $src = '<tr><td class="trow1 smalltext"><a href="usercp.php?action=options';
    $templates->cache["usercp_nav_profile"] = str_replace('<tr><td class="trow1 smalltext"><a href="usercp.php?action=options', $template . $src, $templates->cache["usercp_nav_profile"]);
    //var_dump($templates->cache["usercp_nav_profile"], $templates->cache);
}

function twostepauth_usercp_start()
{
    global $db, $footer, $header, $navigation, $headerinclude, $themes, $mybb, $templates, $usercpnav, $lang;
    $lang->load("twostepauth");
    $auth = new PHPGangsta_GoogleAuthenticator();

    switch ($mybb->input["action"]) {
        case "2stepauth":
        case "do_2stepauth":
        case "do_2stepauth_delete":
            break;
        default:
            return;
    }

    if($mybb->input['action'] == "do_2stepauth" && $mybb->request_method == "post")
    {
        verify_post_check($mybb->input['my_post_key']);

        require_once MYBB_ROOT."inc/datahandlers/user.php";
        $userhandler = new UserDataHandler("update");
        $user = array(
            "uid" => $mybb->user["uid"],
            "twostepauth_enabled" => isset($mybb->input["twostepauth_enable"]) ? '1' : '0',
            "twostepauth_method" => $mybb->input["twostepauth_method"]
        );

        $userhandler->set_data($user);
        if(!$userhandler->validate_user()) {
            $errors = inline_error($userhandler->get_friendly_errors());
        }
        else {
            $errors = "";
            $userhandler->update_user();
            redirect("usercp.php", $lang->twostepauth_updated);
        }
    }

    if($mybb->input['action'] == "do_2stepauth_delete" && $mybb->request_method == "post") {
        verify_post_check($mybb->input['my_post_key']);

        $ip = $db->escape_string($mybb->input["ip"]);

        $db->delete_query("twostepauth_authorizations", "uid = '{$mybb->user["uid"]}' AND ip = '{$ip}'");
        redirect("usercp.php?action=2stepauth", $lang->twostepauth_updated);
    }

    if($mybb->input['hide_notice'] == '1' && isset($mybb->input["my_post_key"])) {
        verify_post_check($mybb->input["my_post_key"]);
        twostepauth_hide_hint();
        if($mybb->input["redir_back"] == "1" && isset($_SERVER["HTTP_REFERER"]) && substr($_SERVER["HTTP_REFERER"], 0, strlen($mybb->settings["bburl"])) == $mybb->settings["bburl"])
            redirect($_SERVER["HTTP_REFERER"], $lang->twostepauth_hint_hidden);
    }

    $rows = "";
    $query = $db->simple_select("twostepauth_authorizations", "ip,location", "uid = ".$mybb->user["uid"]);
    while($item = $db->fetch_array($query)) {
        $ip = $item["ip"];
        $location = $item["location"];
        eval("\$row = \"".$templates->get("usercp_twostepauth_row")."\";");
        $rows .= $row;
    }
    $method1_selected = $mybb->user["twostepauth_method"] == "1" ? " selected=\"selected\"" : "";
    $method2_selected = $mybb->user["twostepauth_method"] == "2" ? " selected=\"selected\"" : "";
    $twostepauth_enable = $mybb->user["twostepauth_enabled"] == '1' ? "checked=\"checked\"" : "";
    $options_show = $mybb->user["twostepauth_enabled"] != "1" ? " style=\"display:none\"" : "";
    $qr_show = $mybb->user["twostepauth_method"] != "1" ? " style=\"display:none\"" : "";
    //the iphone doesn't like whitespaces, so we just take them out of the bbname
    $qr = $auth->getQRCodeGoogleUrl($mybb->user["username"] . "@" . str_replace(" ", "", $mybb->settings["bbname"]), twostepauth_decrypt($mybb->user["twostepauth_secret"]));
    eval("\$output = \"" . $templates->get("usercp_twostepauth") . "\";");
    output_page($output);
    exit;
}

/**
 * @param $userhandler UserDataHandler
 */
function twostepauth_user_update($userhandler) {
    if(isset($userhandler->data["twostepauth_enabled"]))
        $userhandler->user_update_data["twostepauth_enabled"] = intval($userhandler->data["twostepauth_enabled"]);

    if(isset($userhandler->data["twostepauth_method"]))
        $userhandler->user_update_data["twostepauth_method"] = intval($userhandler->data["twostepauth_method"]);
}

/**
 * @param $userhandler UserDataHandler
 */
function twostepauth_user_validate($userhandler) {
    global $lang;
    if(isset($userhandler->data["twostepauth_method"]))
        switch($userhandler->data["twostepauth_method"])
        {
            case '1':
            case '2':
                return true;
            default;
                $userhandler->set_error($lang->twostepauth_invalid_method);
                return false;
        }
}
/**
 * HELPERS
 */

function twostepauth_allowed($uid,$ip=-1) {
    global $db;

    //this function checks if the user is allowed on this uid & ip
    if($ip==-1) $ip = get_ip();
    return $db->fetch_field($db->simple_select("twostepauth_authorizations", "COUNT(*) as count", "uid = {$uid} AND ip = '{$ip}'"), "count") == 1;
}

function twostepauth_set_up_rijndael()
{
    global $config;
    $cipher = new Crypt_Rijndael(CRYPT_RIJNDAEL_MODE_ECB);
    $cipher->setKeyLength(256);
    $cipher->setKey($config["2stepauth_secret_encryption_key"]);
    return $cipher;
}

function twostepauth_encrypt($string) {
    return base64_encode(twostepauth_set_up_rijndael()->encrypt($string));
}

function twostepauth_decrypt($string) {
    return twostepauth_set_up_rijndael()->decrypt(base64_decode($string));
}

function twostepauth_get_location($ip)
{
    global $mybb;
    if ($mybb->settings["twostepauth_geoplugin"] == '0') return "Unknown";
    $gpr = @file_get_contents("http://geoplugin.net/json.gp?ip={$ip}");
    if (($gp = json_decode($gpr, true)) == null) return "Unknown";
    if ($gp["geoplugin_status"] != 200) return "Unknown";
    if (!empty($gp["geoplugin_city"])) $loc = $gp["geoplugin_city"];
    else $loc = $gp["geoplugin_region"];

    $loc .= ", " . $gp["geoplugin_countryName"];
    return $loc;
}

function twostepauth_admin_error($title, $msg)
{
    global $page;
    $page->output_header("2StepAuth Error");
    $page->add_breadcrumb_item("2StepAuth Error", "index.php?module=config-plugins");
    $page->output_error("<p><em>{$title}</em></p><p>{$msg}</p>");
    $page->output_footer();
    exit;
}

function twostepauth_authorize_ip($ip, $uid, $code) {
    global $db;
    $loc = twostepauth_get_location($ip);
    $db->insert_query("twostepauth_authorizations", array("ip" => $ip, "location" => $loc, "code" => $code, "uid" => $uid));
}

function twostepauth_hide_hint() {
    global $db, $mybb;
    $db->update_query("users", array("twostepauth_hide_hint" => "1"), "uid = '{$mybb->user['uid']}'");
}

/**
 * PHP Class for handling Google Authenticator 2-factor authentication
 *
 * @author Michael Kliewe
 * @copyright 2012 Michael Kliewe
 * @license 1 BSD License
 * @link http://www.phpgangsta.de/
 */
class PHPGangsta_GoogleAuthenticator{protected $_codeLength=6;public function createSecret($secretLength=16){$validChars=$this->_getBase32LookupTable();unset($validChars[32]);$secret='';for($i=0;$i<$secretLength;$i++){$secret.=$validChars[array_rand($validChars)];}return $secret;}public function getCode($secret,$timeSlice=null){if($timeSlice===null){$timeSlice=floor(time()/30);}$secretkey=$this->_base32Decode($secret);$time=chr(0).chr(0).chr(0).chr(0).pack('N*',$timeSlice);$hm=hash_hmac('SHA1',$time,$secretkey,true);$offset=ord(substr($hm,-1))&0x0F;$hashpart=substr($hm,$offset,4);$value=unpack('N',$hashpart);$value=$value[1];$value=$value&0x7FFFFFFF;$modulo=pow(10,$this->_codeLength);return str_pad($value%$modulo,$this->_codeLength,'0',STR_PAD_LEFT);}public function getQRCodeGoogleUrl($name,$secret){$urlencoded=urlencode('otpauth://totp/'.$name.'?secret='.$secret.'');return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl='.$urlencoded.'';}public function verifyCode($secret,$code,$discrepancy=1){$currentTimeSlice=floor(time()/30);for($i=-$discrepancy;$i<=$discrepancy;$i++){$calculatedCode=$this->getCode($secret,$currentTimeSlice+$i);if($calculatedCode==$code){return true;}}return false;}public function setCodeLength($length){$this->_codeLength=$length;return $this;}protected function _base32Decode($secret){if(empty($secret))return'';$base32chars=$this->_getBase32LookupTable();$base32charsFlipped=array_flip($base32chars);$paddingCharCount=substr_count($secret,$base32chars[32]);$allowedValues=array(6,4,3,1,0);if(!in_array($paddingCharCount,$allowedValues))return false;for($i=0;$i<4;$i++){if($paddingCharCount==$allowedValues[$i]&&substr($secret,-($allowedValues[$i]))!=str_repeat($base32chars[32],$allowedValues[$i]))return false;}$secret=str_replace('=','',$secret);$secret=str_split($secret);$binaryString="";for($i=0;$i<count($secret);$i=$i+8){$x="";if(!in_array($secret[$i],$base32chars))return false;for($j=0;$j<8;$j++){$x.=str_pad(base_convert(@$base32charsFlipped[@$secret[$i+$j]],10,2),5,'0',STR_PAD_LEFT);}$eightBits=str_split($x,8);for($z=0;$z<count($eightBits);$z++){$binaryString.=(($y=chr(base_convert($eightBits[$z],2,10)))||ord($y)==48)?$y:"";}}return $binaryString;}protected function _base32Encode($secret,$padding=true){if(empty($secret))return'';$base32chars=$this->_getBase32LookupTable();$secret=str_split($secret);$binaryString="";for($i=0;$i<count($secret);$i++){$binaryString.=str_pad(base_convert(ord($secret[$i]),10,2),8,'0',STR_PAD_LEFT);}$fiveBitBinaryArray=str_split($binaryString,5);$base32="";$i=0;while($i<count($fiveBitBinaryArray)){$base32.=$base32chars[base_convert(str_pad($fiveBitBinaryArray[$i],5,'0'),2,10)];$i++;}if($padding&&($x=strlen($binaryString)%40)!=0){if($x==8)$base32.=str_repeat($base32chars[32],6);elseif($x==16)$base32.=str_repeat($base32chars[32],4);elseif($x==24)$base32.=str_repeat($base32chars[32],3);elseif($x==32)$base32.=$base32chars[32];}return $base32;}protected function _getBase32LookupTable(){return array('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','2','3','4','5','6','7','=');}}

/**
 * LICENSE: This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston,
 * MA  02111-1307  USA
 *
 * @category   Crypt
 * @package    Crypt_Rijndael
 * @author     Jim Wigginton <terrafrost@php.net>
 * @copyright  MMVIII Jim Wigginton
 * @license    http://www.gnu.org/licenses/lgpl.txt
 * @version    $Id: Rijndael.php,v 1.15 2010/09/26 05:02:10 terrafrost Exp $
 * @link       http://phpseclib.sourceforge.net
 */

define('CRYPT_RIJNDAEL_MODE_CTR',-1);define('CRYPT_RIJNDAEL_MODE_ECB',1);define('CRYPT_RIJNDAEL_MODE_CBC',2);define('CRYPT_RIJNDAEL_MODE_CFB',3);define('CRYPT_RIJNDAEL_MODE_OFB',4);define('CRYPT_RIJNDAEL_MODE_INTERNAL',1);define('CRYPT_RIJNDAEL_MODE_MCRYPT',2);
class Crypt_Rijndael{var $mode;var $key="\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";var $iv='';var $encryptIV='';var $decryptIV='';var $continuousBuffer=false;var $padding=true;var $changed=true;var $explicit_key_length=false;var $w;var $dw;var $block_size=16;var $Nb=4;var $key_size=16;var $Nk=4;var $Nr;var $c;var $t0;var $t1;var $t2;var $t3;var $dt0;var $dt1;var $dt2;var $dt3;var $paddable=false;var $enbuffer=array('encrypted'=>'','xor'=>'');var $debuffer=array('ciphertext'=>'');function Crypt_Rijndael($mode=CRYPT_RIJNDAEL_MODE_CBC){switch($mode){case  CRYPT_RIJNDAEL_MODE_ECB:case  CRYPT_RIJNDAEL_MODE_CBC:$this->paddable=true;$this->mode=$mode;break;case  CRYPT_RIJNDAEL_MODE_CTR:case  CRYPT_RIJNDAEL_MODE_CFB:case  CRYPT_RIJNDAEL_MODE_OFB:$this->mode=$mode;break;default:$this->paddable=true;$this->mode=CRYPT_RIJNDAEL_MODE_CBC;}$t3=&$this->t3;$t2=&$this->t2;$t1=&$this->t1;$t0=&$this->t0;$dt3=&$this->dt3;$dt2=&$this->dt2;$dt1=&$this->dt1;$dt0=&$this->dt0;$t3=array(0x6363A5C6,0x7C7C84F8,0x777799EE,0x7B7B8DF6,0xF2F20DFF,0x6B6BBDD6,0x6F6FB1DE,0xC5C55491,0x30305060,0x01010302,0x6767A9CE,0x2B2B7D56,0xFEFE19E7,0xD7D762B5,0xABABE64D,0x76769AEC,0xCACA458F,0x82829D1F,0xC9C94089,0x7D7D87FA,0xFAFA15EF,0x5959EBB2,0x4747C98E,0xF0F00BFB,0xADADEC41,0xD4D467B3,0xA2A2FD5F,0xAFAFEA45,0x9C9CBF23,0xA4A4F753,0x727296E4,0xC0C05B9B,0xB7B7C275,0xFDFD1CE1,0x9393AE3D,0x26266A4C,0x36365A6C,0x3F3F417E,0xF7F702F5,0xCCCC4F83,0x34345C68,0xA5A5F451,0xE5E534D1,0xF1F108F9,0x717193E2,0xD8D873AB,0x31315362,0x15153F2A,0x04040C08,0xC7C75295,0x23236546,0xC3C35E9D,0x18182830,0x9696A137,0x05050F0A,0x9A9AB52F,0x0707090E,0x12123624,0x80809B1B,0xE2E23DDF,0xEBEB26CD,0x2727694E,0xB2B2CD7F,0x75759FEA,0x09091B12,0x83839E1D,0x2C2C7458,0x1A1A2E34,0x1B1B2D36,0x6E6EB2DC,0x5A5AEEB4,0xA0A0FB5B,0x5252F6A4,0x3B3B4D76,0xD6D661B7,0xB3B3CE7D,0x29297B52,0xE3E33EDD,0x2F2F715E,0x84849713,0x5353F5A6,0xD1D168B9,0x00000000,0xEDED2CC1,0x20206040,0xFCFC1FE3,0xB1B1C879,0x5B5BEDB6,0x6A6ABED4,0xCBCB468D,0xBEBED967,0x39394B72,0x4A4ADE94,0x4C4CD498,0x5858E8B0,0xCFCF4A85,0xD0D06BBB,0xEFEF2AC5,0xAAAAE54F,0xFBFB16ED,0x4343C586,0x4D4DD79A,0x33335566,0x85859411,0x4545CF8A,0xF9F910E9,0x02020604,0x7F7F81FE,0x5050F0A0,0x3C3C4478,0x9F9FBA25,0xA8A8E34B,0x5151F3A2,0xA3A3FE5D,0x4040C080,0x8F8F8A05,0x9292AD3F,0x9D9DBC21,0x38384870,0xF5F504F1,0xBCBCDF63,0xB6B6C177,0xDADA75AF,0x21216342,0x10103020,0xFFFF1AE5,0xF3F30EFD,0xD2D26DBF,0xCDCD4C81,0x0C0C1418,0x13133526,0xECEC2FC3,0x5F5FE1BE,0x9797A235,0x4444CC88,0x1717392E,0xC4C45793,0xA7A7F255,0x7E7E82FC,0x3D3D477A,0x6464ACC8,0x5D5DE7BA,0x19192B32,0x737395E6,0x6060A0C0,0x81819819,0x4F4FD19E,0xDCDC7FA3,0x22226644,0x2A2A7E54,0x9090AB3B,0x8888830B,0x4646CA8C,0xEEEE29C7,0xB8B8D36B,0x14143C28,0xDEDE79A7,0x5E5EE2BC,0x0B0B1D16,0xDBDB76AD,0xE0E03BDB,0x32325664,0x3A3A4E74,0x0A0A1E14,0x4949DB92,0x06060A0C,0x24246C48,0x5C5CE4B8,0xC2C25D9F,0xD3D36EBD,0xACACEF43,0x6262A6C4,0x9191A839,0x9595A431,0xE4E437D3,0x79798BF2,0xE7E732D5,0xC8C8438B,0x3737596E,0x6D6DB7DA,0x8D8D8C01,0xD5D564B1,0x4E4ED29C,0xA9A9E049,0x6C6CB4D8,0x5656FAAC,0xF4F407F3,0xEAEA25CF,0x6565AFCA,0x7A7A8EF4,0xAEAEE947,0x08081810,0xBABAD56F,0x787888F0,0x25256F4A,0x2E2E725C,0x1C1C2438,0xA6A6F157,0xB4B4C773,0xC6C65197,0xE8E823CB,0xDDDD7CA1,0x74749CE8,0x1F1F213E,0x4B4BDD96,0xBDBDDC61,0x8B8B860D,0x8A8A850F,0x707090E0,0x3E3E427C,0xB5B5C471,0x6666AACC,0x4848D890,0x03030506,0xF6F601F7,0x0E0E121C,0x6161A3C2,0x35355F6A,0x5757F9AE,0xB9B9D069,0x86869117,0xC1C15899,0x1D1D273A,0x9E9EB927,0xE1E138D9,0xF8F813EB,0x9898B32B,0x11113322,0x6969BBD2,0xD9D970A9,0x8E8E8907,0x9494A733,0x9B9BB62D,0x1E1E223C,0x87879215,0xE9E920C9,0xCECE4987,0x5555FFAA,0x28287850,0xDFDF7AA5,0x8C8C8F03,0xA1A1F859,0x89898009,0x0D0D171A,0xBFBFDA65,0xE6E631D7,0x4242C684,0x6868B8D0,0x4141C382,0x9999B029,0x2D2D775A,0x0F0F111E,0xB0B0CB7B,0x5454FCA8,0xBBBBD66D,0x16163A2C);$dt3=array(0xF4A75051,0x4165537E,0x17A4C31A,0x275E963A,0xAB6BCB3B,0x9D45F11F,0xFA58ABAC,0xE303934B,0x30FA5520,0x766DF6AD,0xCC769188,0x024C25F5,0xE5D7FC4F,0x2ACBD7C5,0x35448026,0x62A38FB5,0xB15A49DE,0xBA1B6725,0xEA0E9845,0xFEC0E15D,0x2F7502C3,0x4CF01281,0x4697A38D,0xD3F9C66B,0x8F5FE703,0x929C9515,0x6D7AEBBF,0x5259DA95,0xBE832DD4,0x7421D358,0xE0692949,0xC9C8448E,0xC2896A75,0x8E7978F4,0x583E6B99,0xB971DD27,0xE14FB6BE,0x88AD17F0,0x20AC66C9,0xCE3AB47D,0xDF4A1863,0x1A3182E5,0x51336097,0x537F4562,0x6477E0B1,0x6BAE84BB,0x81A01CFE,0x082B94F9,0x48685870,0x45FD198F,0xDE6C8794,0x7BF8B752,0x73D323AB,0x4B02E272,0x1F8F57E3,0x55AB2A66,0xEB2807B2,0xB5C2032F,0xC57B9A86,0x3708A5D3,0x2887F230,0xBFA5B223,0x036ABA02,0x16825CED,0xCF1C2B8A,0x79B492A7,0x07F2F0F3,0x69E2A14E,0xDAF4CD65,0x05BED506,0x34621FD1,0xA6FE8AC4,0x2E539D34,0xF355A0A2,0x8AE13205,0xF6EB75A4,0x83EC390B,0x60EFAA40,0x719F065E,0x6E1051BD,0x218AF93E,0xDD063D96,0x3E05AEDD,0xE6BD464D,0x548DB591,0xC45D0571,0x06D46F04,0x5015FF60,0x98FB2419,0xBDE997D6,0x4043CC89,0xD99E7767,0xE842BDB0,0x898B8807,0x195B38E7,0xC8EEDB79,0x7C0A47A1,0x420FE97C,0x841EC9F8,0x00000000,0x80868309,0x2BED4832,0x1170AC1E,0x5A724E6C,0x0EFFFBFD,0x8538560F,0xAED51E3D,0x2D392736,0x0FD9640A,0x5CA62168,0x5B54D19B,0x362E3A24,0x0A67B10C,0x57E70F93,0xEE96D2B4,0x9B919E1B,0xC0C54F80,0xDC20A261,0x774B695A,0x121A161C,0x93BA0AE2,0xA02AE5C0,0x22E0433C,0x1B171D12,0x090D0B0E,0x8BC7ADF2,0xB6A8B92D,0x1EA9C814,0xF1198557,0x75074CAF,0x99DDBBEE,0x7F60FDA3,0x01269FF7,0x72F5BC5C,0x663BC544,0xFB7E345B,0x4329768B,0x23C6DCCB,0xEDFC68B6,0xE4F163B8,0x31DCCAD7,0x63851042,0x97224013,0xC6112084,0x4A247D85,0xBB3DF8D2,0xF93211AE,0x29A16DC7,0x9E2F4B1D,0xB230F3DC,0x8652EC0D,0xC1E3D077,0xB3166C2B,0x70B999A9,0x9448FA11,0xE9642247,0xFC8CC4A8,0xF03F1AA0,0x7D2CD856,0x3390EF22,0x494EC787,0x38D1C1D9,0xCAA2FE8C,0xD40B3698,0xF581CFA6,0x7ADE28A5,0xB78E26DA,0xADBFA43F,0x3A9DE42C,0x78920D50,0x5FCC9B6A,0x7E466254,0x8D13C2F6,0xD8B8E890,0x39F75E2E,0xC3AFF582,0x5D80BE9F,0xD0937C69,0xD52DA96F,0x2512B3CF,0xAC993BC8,0x187DA710,0x9C636EE8,0x3BBB7BDB,0x267809CD,0x5918F46E,0x9AB701EC,0x4F9AA883,0x956E65E6,0xFFE67EAA,0xBCCF0821,0x15E8E6EF,0xE79BD9BA,0x6F36CE4A,0x9F09D4EA,0xB07CD629,0xA4B2AF31,0x3F23312A,0xA59430C6,0xA266C035,0x4EBC3774,0x82CAA6FC,0x90D0B0E0,0xA7D81533,0x04984AF1,0xECDAF741,0xCD500E7F,0x91F62F17,0x4DD68D76,0xEFB04D43,0xAA4D54CC,0x9604DFE4,0xD1B5E39E,0x6A881B4C,0x2C1FB8C1,0x65517F46,0x5EEA049D,0x8C355D01,0x877473FA,0x0B412EFB,0x671D5AB3,0xDBD25292,0x105633E9,0xD647136D,0xD7618C9A,0xA10C7A37,0xF8148E59,0x133C89EB,0xA927EECE,0x61C935B7,0x1CE5EDE1,0x47B13C7A,0xD2DF599C,0xF2733F55,0x14CE7918,0xC737BF73,0xF7CDEA53,0xFDAA5B5F,0x3D6F14DF,0x44DB8678,0xAFF381CA,0x68C43EB9,0x24342C38,0xA3405FC2,0x1DC37216,0xE2250CBC,0x3C498B28,0x0D9541FF,0xA8017139,0x0CB3DE08,0xB4E49CD8,0x56C19064,0xCB84617B,0x32B670D5,0x6C5C7448,0xB85742D0);for($i=0;$i<256;$i++){$t2[$i<<8]=(($t3[$i]<<8)&0xFFFFFF00)|(($t3[$i]>>24)&0x000000FF);$t1[$i<<16]=(($t3[$i]<<16)&0xFFFF0000)|(($t3[$i]>>16)&0x0000FFFF);$t0[$i<<24]=(($t3[$i]<<24)&0xFF000000)|(($t3[$i]>>8)&0x00FFFFFF);$dt2[$i<<8]=(($this->dt3[$i]<<8)&0xFFFFFF00)|(($dt3[$i]>>24)&0x000000FF);$dt1[$i<<16]=(($this->dt3[$i]<<16)&0xFFFF0000)|(($dt3[$i]>>16)&0x0000FFFF);$dt0[$i<<24]=(($this->dt3[$i]<<24)&0xFF000000)|(($dt3[$i]>>8)&0x00FFFFFF);}}function setKey($key){$this->key=$key;$this->changed=true;}function setIV($iv){$this->encryptIV=$this->decryptIV=$this->iv=str_pad(substr($iv,0,$this->block_size),$this->block_size,chr(0));;}function setKeyLength($length){$length>>=5;if($length>8){$length=8;}else if($length<4){$length=4;}$this->Nk=$length;$this->key_size=$length<<2;$this->explicit_key_length=true;$this->changed=true;}function setBlockLength($length){$length>>=5;if($length>8){$length=8;}else if($length<4){$length=4;}$this->Nb=$length;$this->block_size=$length<<2;$this->changed=true;}function _generate_xor($length,&$iv){$xor='';$block_size=$this->block_size;$num_blocks=floor(($length+($block_size-1))/$block_size);for($i=0;$i<$num_blocks;$i++){$xor.=$iv;for($j=4;$j<=$block_size;$j+=4){$temp=substr($iv,-$j,4);switch($temp){case  "\xFF\xFF\xFF\xFF":$iv=substr_replace($iv,"\x00\x00\x00\x00",-$j,4);break;case  "\x7F\xFF\xFF\xFF":$iv=substr_replace($iv,"\x80\x00\x00\x00",-$j,4);break2;default:extract(unpack('Ncount',$temp));$iv=substr_replace($iv,pack('N',$count+1),-$j,4);break2;}}}return $xor;}function encrypt($plaintext){$this->_setup();if($this->paddable){$plaintext=$this->_pad($plaintext);}$block_size=$this->block_size;$buffer=&$this->enbuffer;$continuousBuffer=$this->continuousBuffer;$ciphertext='';switch($this->mode){case  CRYPT_RIJNDAEL_MODE_ECB:for($i=0;$i<strlen($plaintext);$i+=$block_size){$ciphertext.=$this->_encryptBlock(substr($plaintext,$i,$block_size));}break;case  CRYPT_RIJNDAEL_MODE_CBC:$xor=$this->encryptIV;for($i=0;$i<strlen($plaintext);$i+=$block_size){$block=substr($plaintext,$i,$block_size);$block=$this->_encryptBlock($block^$xor);$xor=$block;$ciphertext.=$block;}if($this->continuousBuffer){$this->encryptIV=$xor;}break;case  CRYPT_RIJNDAEL_MODE_CTR:$xor=$this->encryptIV;if(!empty($buffer)){for($i=0;$i<strlen($plaintext);$i+=$block_size){$block=substr($plaintext,$i,$block_size);$buffer.=$this->_encryptBlock($this->_generate_xor($block_size,$xor));$key=$this->_string_shift($buffer,$block_size);$ciphertext.=$block^$key;}}else{for($i=0;$i<strlen($plaintext);$i+=$block_size){$block=substr($plaintext,$i,$block_size);$key=$this->_encryptBlock($this->_generate_xor($block_size,$xor));$ciphertext.=$block^$key;}}if($this->continuousBuffer){$this->encryptIV=$xor;if($start=strlen($plaintext)%$block_size){$buffer=substr($key,$start).$buffer;}}break;case  CRYPT_RIJNDAEL_MODE_CFB:if(!empty($buffer['xor'])){$ciphertext=$plaintext^$buffer['xor'];$iv=$buffer['encrypted'].$ciphertext;$start=strlen($ciphertext);$buffer['encrypted'].=$ciphertext;$buffer['xor']=substr($buffer['xor'],strlen($ciphertext));}else{$ciphertext='';$iv=$this->encryptIV;$start=0;}for($i=$start;$i<strlen($plaintext);$i+=$block_size){$block=substr($plaintext,$i,$block_size);$xor=$this->_encryptBlock($iv);$iv=$block^$xor;if($continuousBuffer&&strlen($iv)!=$block_size){$buffer=array('encrypted'=>$iv,'xor'=>substr($xor,strlen($iv)));}$ciphertext.=$iv;}if($this->continuousBuffer){$this->encryptIV=$iv;}break;case  CRYPT_RIJNDAEL_MODE_OFB:$xor=$this->encryptIV;if(strlen($buffer)){for($i=0;$i<strlen($plaintext);$i+=$block_size){$xor=$this->_encryptBlock($xor);$buffer.=$xor;$key=$this->_string_shift($buffer,$block_size);$ciphertext.=substr($plaintext,$i,$block_size)^$key;}}else{for($i=0;$i<strlen($plaintext);$i+=$block_size){$xor=$this->_encryptBlock($xor);$ciphertext.=substr($plaintext,$i,$block_size)^$xor;}$key=$xor;}if($this->continuousBuffer){$this->encryptIV=$xor;if($start=strlen($plaintext)%$block_size){$buffer=substr($key,$start).$buffer;}}}return $ciphertext;}function decrypt($ciphertext){$this->_setup();if($this->paddable){$ciphertext=str_pad($ciphertext,(strlen($ciphertext)+$this->block_size-1)%$this->block_size,chr(0));}$block_size=$this->block_size;$buffer=&$this->debuffer;$continuousBuffer=$this->continuousBuffer;$plaintext='';switch($this->mode){case  CRYPT_RIJNDAEL_MODE_ECB:for($i=0;$i<strlen($ciphertext);$i+=$block_size){$plaintext.=$this->_decryptBlock(substr($ciphertext,$i,$block_size));}break;case  CRYPT_RIJNDAEL_MODE_CBC:$xor=$this->decryptIV;for($i=0;$i<strlen($ciphertext);$i+=$block_size){$block=substr($ciphertext,$i,$block_size);$plaintext.=$this->_decryptBlock($block)^$xor;$xor=$block;}if($this->continuousBuffer){$this->decryptIV=$xor;}break;case  CRYPT_RIJNDAEL_MODE_CTR:$xor=$this->decryptIV;if(strlen($buffer)){for($i=0;$i<strlen($ciphertext);$i+=$block_size){$block=substr($ciphertext,$i,$block_size);$buffer.=$this->_encryptBlock($this->_generate_xor($block_size,$xor));$key=$this->_string_shift($buffer,$block_size);$plaintext.=$block^$key;}}else{for($i=0;$i<strlen($ciphertext);$i+=$block_size){$block=substr($ciphertext,$i,$block_size);$key=$this->_encryptBlock($this->_generate_xor($block_size,$xor));$plaintext.=$block^$key;}}if($this->continuousBuffer){$this->decryptIV=$xor;if($start=strlen($ciphertext)%$block_size){$buffer=substr($key,$start).$buffer;}}break;case  CRYPT_RIJNDAEL_MODE_CFB:if(!empty($buffer['ciphertext'])){$plaintext=$ciphertext^substr($this->decryptIV,strlen($buffer['ciphertext']));$buffer['ciphertext'].=substr($ciphertext,0,strlen($plaintext));if(strlen($buffer['ciphertext'])==$block_size){$xor=$this->_encryptBlock($buffer['ciphertext']);$buffer['ciphertext']='';}$start=strlen($plaintext);$block=$this->decryptIV;}else{$plaintext='';$xor=$this->_encryptBlock($this->decryptIV);$start=0;}for($i=$start;$i<strlen($ciphertext);$i+=$block_size){$block=substr($ciphertext,$i,$block_size);$plaintext.=$block^$xor;if($continuousBuffer&&strlen($block)!=$block_size){$buffer['ciphertext'].=$block;$block=$xor;}else if(strlen($block)==$block_size){$xor=$this->_encryptBlock($block);}}if($this->continuousBuffer){$this->decryptIV=$block;}break;case  CRYPT_RIJNDAEL_MODE_OFB:$xor=$this->decryptIV;if(strlen($buffer)){for($i=0;$i<strlen($ciphertext);$i+=$block_size){$xor=$this->_encryptBlock($xor);$buffer.=$xor;$key=$this->_string_shift($buffer,$block_size);$plaintext.=substr($ciphertext,$i,$block_size)^$key;}}else{for($i=0;$i<strlen($ciphertext);$i+=$block_size){$xor=$this->_encryptBlock($xor);$plaintext.=substr($ciphertext,$i,$block_size)^$xor;}$key=$xor;}if($this->continuousBuffer){$this->decryptIV=$xor;if($start=strlen($ciphertext)%$block_size){$buffer=substr($key,$start).$buffer;}}}return $this->paddable?$this->_unpad($plaintext):$plaintext;}function _encryptBlock($in){$state=array();$words=unpack('N*word',$in);$w=$this->w;$t0=$this->t0;$t1=$this->t1;$t2=$this->t2;$t3=$this->t3;$Nb=$this->Nb;$Nr=$this->Nr;$c=$this->c;$i=0;foreach($words as $word){$state[]=$word^$w[0][$i++];}$temp=array();for($round=1;$round<$Nr;$round++){$i=0;$j=$c[1];$k=$c[2];$l=$c[3];while($i<$this->Nb){$temp[$i]=$t0[$state[$i]&0xFF000000]^$t1[$state[$j]&0x00FF0000]^$t2[$state[$k]&0x0000FF00]^$t3[$state[$l]&0x000000FF]^$w[$round][$i];$i++;$j=($j+1)%$Nb;$k=($k+1)%$Nb;$l=($l+1)%$Nb;}for($i=0;$i<$Nb;$i++){$state[$i]=$temp[$i];}}for($i=0;$i<$Nb;$i++){$state[$i]=$this->_subWord($state[$i]);}$i=0;$j=$c[1];$k=$c[2];$l=$c[3];while($i<$this->Nb){$temp[$i]=($state[$i]&0xFF000000)^($state[$j]&0x00FF0000)^($state[$k]&0x0000FF00)^($state[$l]&0x000000FF)^$w[$Nr][$i];$i++;$j=($j+1)%$Nb;$k=($k+1)%$Nb;$l=($l+1)%$Nb;}$state=$temp;array_unshift($state,'N*');return call_user_func_array('pack',$state);}function _decryptBlock($in){$state=array();$words=unpack('N*word',$in);$num_states=count($state);$dw=$this->dw;$dt0=$this->dt0;$dt1=$this->dt1;$dt2=$this->dt2;$dt3=$this->dt3;$Nb=$this->Nb;$Nr=$this->Nr;$c=$this->c;$i=0;foreach($words as $word){$state[]=$word^$dw[$Nr][$i++];}$temp=array();for($round=$Nr-1;$round>0;$round--){$i=0;$j=$Nb-$c[1];$k=$Nb-$c[2];$l=$Nb-$c[3];while($i<$Nb){$temp[$i]=$dt0[$state[$i]&0xFF000000]^$dt1[$state[$j]&0x00FF0000]^$dt2[$state[$k]&0x0000FF00]^$dt3[$state[$l]&0x000000FF]^$dw[$round][$i];$i++;$j=($j+1)%$Nb;$k=($k+1)%$Nb;$l=($l+1)%$Nb;}for($i=0;$i<$Nb;$i++){$state[$i]=$temp[$i];}}$i=0;$j=$Nb-$c[1];$k=$Nb-$c[2];$l=$Nb-$c[3];while($i<$Nb){$temp[$i]=$dw[0][$i]^$this->_invSubWord(($state[$i]&0xFF000000)|($state[$j]&0x00FF0000)|($state[$k]&0x0000FF00)|($state[$l]&0x000000FF));$i++;$j=($j+1)%$Nb;$k=($k+1)%$Nb;$l=($l+1)%$Nb;}$state=$temp;array_unshift($state,'N*');return call_user_func_array('pack',$state);}function _setup(){static $rcon=array(0,0x01000000,0x02000000,0x04000000,0x08000000,0x10000000,0x20000000,0x40000000,0x80000000,0x1B000000,0x36000000,0x6C000000,0xD8000000,0xAB000000,0x4D000000,0x9A000000,0x2F000000,0x5E000000,0xBC000000,0x63000000,0xC6000000,0x97000000,0x35000000,0x6A000000,0xD4000000,0xB3000000,0x7D000000,0xFA000000,0xEF000000,0xC5000000,0x91000000);if(!$this->changed){return;}if(!$this->explicit_key_length){$length=strlen($this->key)>>2;if($length>8){$length=8;}else if($length<4){$length=4;}$this->Nk=$length;$this->key_size=$length<<2;}$this->key=str_pad(substr($this->key,0,$this->key_size),$this->key_size,chr(0));$this->encryptIV=$this->decryptIV=$this->iv=str_pad(substr($this->iv,0,$this->block_size),$this->block_size,chr(0));$this->Nr=max($this->Nk,$this->Nb)+6;switch($this->Nb){case 4:case 5:case 6:$this->c=array(0,1,2,3);break;case 7:$this->c=array(0,1,2,4);break;case 8:$this->c=array(0,1,3,4);}$key=$this->key;$w=array_values(unpack('N*words',$key));$length=$this->Nb*($this->Nr+1);for($i=$this->Nk;$i<$length;$i++){$temp=$w[$i-1];if($i%$this->Nk==0){$temp=(($temp<<8)&0xFFFFFF00)|(($temp>>24)&0x000000FF);$temp=$this->_subWord($temp)^$rcon[$i/$this->Nk];}else if($this->Nk>6&&$i%$this->Nk==4){$temp=$this->_subWord($temp);}$w[$i]=$w[$i-$this->Nk]^$temp;}$temp=array();for($i=$row=$col=0;$i<$length;$i++,$col++){if($col==$this->Nb){if($row==0){$this->dw[0]=$this->w[0];}else{$j=0;while($j<$this->Nb){$dw=$this->_subWord($this->w[$row][$j]);$temp[$j]=$this->dt0[$dw&0xFF000000]^$this->dt1[$dw&0x00FF0000]^$this->dt2[$dw&0x0000FF00]^$this->dt3[$dw&0x000000FF];$j++;}$this->dw[$row]=$temp;}$col=0;$row++;}$this->w[$row][$col]=$w[$i];}$this->dw[$row]=$this->w[$row];$this->changed=false;}function _subWord($word){static $sbox0,$sbox1,$sbox2,$sbox3;if(empty($sbox0)){$sbox0=array(0x63,0x7C,0x77,0x7B,0xF2,0x6B,0x6F,0xC5,0x30,0x01,0x67,0x2B,0xFE,0xD7,0xAB,0x76,0xCA,0x82,0xC9,0x7D,0xFA,0x59,0x47,0xF0,0xAD,0xD4,0xA2,0xAF,0x9C,0xA4,0x72,0xC0,0xB7,0xFD,0x93,0x26,0x36,0x3F,0xF7,0xCC,0x34,0xA5,0xE5,0xF1,0x71,0xD8,0x31,0x15,0x04,0xC7,0x23,0xC3,0x18,0x96,0x05,0x9A,0x07,0x12,0x80,0xE2,0xEB,0x27,0xB2,0x75,0x09,0x83,0x2C,0x1A,0x1B,0x6E,0x5A,0xA0,0x52,0x3B,0xD6,0xB3,0x29,0xE3,0x2F,0x84,0x53,0xD1,0x00,0xED,0x20,0xFC,0xB1,0x5B,0x6A,0xCB,0xBE,0x39,0x4A,0x4C,0x58,0xCF,0xD0,0xEF,0xAA,0xFB,0x43,0x4D,0x33,0x85,0x45,0xF9,0x02,0x7F,0x50,0x3C,0x9F,0xA8,0x51,0xA3,0x40,0x8F,0x92,0x9D,0x38,0xF5,0xBC,0xB6,0xDA,0x21,0x10,0xFF,0xF3,0xD2,0xCD,0x0C,0x13,0xEC,0x5F,0x97,0x44,0x17,0xC4,0xA7,0x7E,0x3D,0x64,0x5D,0x19,0x73,0x60,0x81,0x4F,0xDC,0x22,0x2A,0x90,0x88,0x46,0xEE,0xB8,0x14,0xDE,0x5E,0x0B,0xDB,0xE0,0x32,0x3A,0x0A,0x49,0x06,0x24,0x5C,0xC2,0xD3,0xAC,0x62,0x91,0x95,0xE4,0x79,0xE7,0xC8,0x37,0x6D,0x8D,0xD5,0x4E,0xA9,0x6C,0x56,0xF4,0xEA,0x65,0x7A,0xAE,0x08,0xBA,0x78,0x25,0x2E,0x1C,0xA6,0xB4,0xC6,0xE8,0xDD,0x74,0x1F,0x4B,0xBD,0x8B,0x8A,0x70,0x3E,0xB5,0x66,0x48,0x03,0xF6,0x0E,0x61,0x35,0x57,0xB9,0x86,0xC1,0x1D,0x9E,0xE1,0xF8,0x98,0x11,0x69,0xD9,0x8E,0x94,0x9B,0x1E,0x87,0xE9,0xCE,0x55,0x28,0xDF,0x8C,0xA1,0x89,0x0D,0xBF,0xE6,0x42,0x68,0x41,0x99,0x2D,0x0F,0xB0,0x54,0xBB,0x16);$sbox1=array();$sbox2=array();$sbox3=array();for($i=0;$i<256;$i++){$sbox1[$i<<8]=$sbox0[$i]<<8;$sbox2[$i<<16]=$sbox0[$i]<<16;$sbox3[$i<<24]=$sbox0[$i]<<24;}}return $sbox0[$word&0x000000FF]|$sbox1[$word&0x0000FF00]|$sbox2[$word&0x00FF0000]|$sbox3[$word&0xFF000000];}function _invSubWord($word){static $sbox0,$sbox1,$sbox2,$sbox3;if(empty($sbox0)){$sbox0=array(0x52,0x09,0x6A,0xD5,0x30,0x36,0xA5,0x38,0xBF,0x40,0xA3,0x9E,0x81,0xF3,0xD7,0xFB,0x7C,0xE3,0x39,0x82,0x9B,0x2F,0xFF,0x87,0x34,0x8E,0x43,0x44,0xC4,0xDE,0xE9,0xCB,0x54,0x7B,0x94,0x32,0xA6,0xC2,0x23,0x3D,0xEE,0x4C,0x95,0x0B,0x42,0xFA,0xC3,0x4E,0x08,0x2E,0xA1,0x66,0x28,0xD9,0x24,0xB2,0x76,0x5B,0xA2,0x49,0x6D,0x8B,0xD1,0x25,0x72,0xF8,0xF6,0x64,0x86,0x68,0x98,0x16,0xD4,0xA4,0x5C,0xCC,0x5D,0x65,0xB6,0x92,0x6C,0x70,0x48,0x50,0xFD,0xED,0xB9,0xDA,0x5E,0x15,0x46,0x57,0xA7,0x8D,0x9D,0x84,0x90,0xD8,0xAB,0x00,0x8C,0xBC,0xD3,0x0A,0xF7,0xE4,0x58,0x05,0xB8,0xB3,0x45,0x06,0xD0,0x2C,0x1E,0x8F,0xCA,0x3F,0x0F,0x02,0xC1,0xAF,0xBD,0x03,0x01,0x13,0x8A,0x6B,0x3A,0x91,0x11,0x41,0x4F,0x67,0xDC,0xEA,0x97,0xF2,0xCF,0xCE,0xF0,0xB4,0xE6,0x73,0x96,0xAC,0x74,0x22,0xE7,0xAD,0x35,0x85,0xE2,0xF9,0x37,0xE8,0x1C,0x75,0xDF,0x6E,0x47,0xF1,0x1A,0x71,0x1D,0x29,0xC5,0x89,0x6F,0xB7,0x62,0x0E,0xAA,0x18,0xBE,0x1B,0xFC,0x56,0x3E,0x4B,0xC6,0xD2,0x79,0x20,0x9A,0xDB,0xC0,0xFE,0x78,0xCD,0x5A,0xF4,0x1F,0xDD,0xA8,0x33,0x88,0x07,0xC7,0x31,0xB1,0x12,0x10,0x59,0x27,0x80,0xEC,0x5F,0x60,0x51,0x7F,0xA9,0x19,0xB5,0x4A,0x0D,0x2D,0xE5,0x7A,0x9F,0x93,0xC9,0x9C,0xEF,0xA0,0xE0,0x3B,0x4D,0xAE,0x2A,0xF5,0xB0,0xC8,0xEB,0xBB,0x3C,0x83,0x53,0x99,0x61,0x17,0x2B,0x04,0x7E,0xBA,0x77,0xD6,0x26,0xE1,0x69,0x14,0x63,0x55,0x21,0x0C,0x7D);$sbox1=array();$sbox2=array();$sbox3=array();for($i=0;$i<256;$i++){$sbox1[$i<<8]=$sbox0[$i]<<8;$sbox2[$i<<16]=$sbox0[$i]<<16;$sbox3[$i<<24]=$sbox0[$i]<<24;}}return $sbox0[$word&0x000000FF]|$sbox1[$word&0x0000FF00]|$sbox2[$word&0x00FF0000]|$sbox3[$word&0xFF000000];}function enablePadding(){$this->padding=true;}function disablePadding(){$this->padding=false;}function _pad($text){$length=strlen($text);if(!$this->padding){if($length%$this->block_size==0){return $text;}else{user_error("The plaintext's length ($length) is not a multiple of the block size ({$this->block_size})",E_USER_NOTICE);$this->padding=true;}}$pad=$this->block_size-($length%$this->block_size);return str_pad($text,$length+$pad,chr($pad));}function _unpad($text){if(!$this->padding){return $text;}$length=ord($text[strlen($text)-1]);if(!$length||$length>$this->block_size){return false;}return substr($text,0,-$length);}function enableContinuousBuffer(){$this->continuousBuffer=true;}function disableContinuousBuffer(){$this->continuousBuffer=false;$this->encryptIV=$this->iv;$this->decryptIV=$this->iv;}function _string_shift(&$string,$index=1){$substr=substr($string,0,$index);$string=substr($string,$index);return $substr;}}