<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

// ============== HOOKS ==============
$plugins->add_hook("newthread_start", "chronospost_newthread"); // EIGENER HOOK
$plugins->add_hook("newreply_start", "chronospost_newreply");   // EIGENER HOOK
$plugins->add_hook("datahandler_post_validate_thread", "chronospost_validate");
$plugins->add_hook("datahandler_post_validate_post", "chronospost_validate");
$plugins->add_hook("newthread_do_newthread_end", "chronospost_save_schedule");
$plugins->add_hook("newreply_do_newreply_end", "chronospost_save_schedule");

// NEUER HOOK: Löst die Veröffentlichung bei jedem Aufruf der Index-Seite aus
$plugins->add_hook("index_end", "chronospost_publish_posts");


// ============== PLUGIN-INFORMATIONEN ==============
function chronospost_info()
{
    global $lang;
    $lang->load('chronospost', true); // Admin-Sprachdatei laden

    return array(
        "name"          => "ChronosPost",
        "description"   => "Hiermit kannst Du planen, wann Deine Posts und Themen gesendet werden",
        "website" 		=> "https://shadow.or.at/index.php",
        "author" 		=> "Dani",
        "authorsite" 	=> "https://github.com/ShadowOfDestiny",
        "version"       => "1.0",
        "guid"          => "",
        "compatibility" => "18*"
    );
}

// ============== INSTALLATION / DEINSTALLATION ==============
function chronospost_install()
{
    global $db, $cache;

    // 1. DB-Tabelle erstellen
    if (!$db->table_exists("chronospost_schedule")) {
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . "chronospost_schedule (
                `sid` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `pid` INT(10) UNSIGNED NOT NULL,
                `tid` INT(10) UNSIGNED NOT NULL,
                `uid` INT(10) UNSIGNED NOT NULL,
                `publish_dateline` BIGINT(30) NOT NULL,
                `type` VARCHAR(10) NOT NULL,
                PRIMARY KEY (`sid`)
            ) ENGINE=InnoDB " . $db->build_create_table_collation() . ";"
        );
    }
    
    // 2. Einstellungen erstellen (bleibt wie in v0.1)
    $setting_group = array(
        'name'          => 'chronospost',
        'title'         => 'ChronosPost Einstellungen',
        'description'   => 'Einstellungen für das ChronosPost Plugin zur Inhaltsplanung.',
        'disporder'     => 50, 'isdefault' => 0
    );
    $gid = $db->insert_query("settinggroups", $setting_group);

    $setting_array = array(
        'chronospost_enabled' 		=> array(
            'title' 				=> 'Plugin aktivieren?',
			'description' 			=> 'Wenn auf "Ja" gesetzt, ist die Planungsfunktion global aktiv.',
            'optionscode' 			=> 'yesno',
			'value' 				=> 1,
			'disporder' 			=> 1
        ),
		'chronospost_area' 			=> array(
			'title' 				=> 'Erlaubte Areas',
			'description' 			=> 'Areas auswählen',
			'optionscode' 			=> 'forumselect',
			'value'					=> '',
			'disporder'				=> '2'
		),
        'chronospost_usergroups' 	=> array(
            'title' 				=> 'Erlaubte Benutzergruppen',
			'description' 			=> 'Welche Benutzergruppen dürfen Inhalte planen?',
            'optionscode' 			=> 'groupselect',
			'value' 				=> '4',
			'disporder' 			=> 3
        )
    );

    foreach($setting_array as $name => $setting) {
        $setting['name'] = $name;
        $setting['gid'] = $gid;
        $db->insert_query("settings", $setting);
    }

    rebuild_settings();
}

function chronospost_is_installed()
{
    global $db;
    return $db->table_exists("chronospost_schedule");
}

function chronospost_uninstall()
{
    global $db;
    if ($db->table_exists("chronospost_schedule")) {
        $db->drop_table("chronospost_schedule");
    }
    $db->delete_query('settings', "name LIKE 'chronospost_%'");
    $db->delete_query('settinggroups', "name = 'chronospost'");
    rebuild_settings();
}


// ============== AKTIVIERUNG / DEAKTIVIERUNG ==============
function chronospost_activate()
{
    global $db;
    require_once MYBB_ROOT."/inc/adminfunctions_templates.php";
    require_once MYBB_ROOT."/inc/functions_task.php";

    // 1. Template erstellen
    $template = '
    <br />
    <table border="0" cellspacing="0" cellpadding="5" class="tborder">
        <thead>
            <tr><td class="thead"><strong>{$lang->chronospost_schedule}</strong></td></tr>
        </thead>
        <tbody>
            <tr>
                <td class="trow1">
                    <input type="checkbox" name="schedule_active" id="schedule_active" value="1" {$schedule_checked} /> 
                    <label for="schedule_active">{$lang->chronospost_schedule_desc}</label>
                </td>
            </tr>
            <tr id="schedule_options" style="{$schedule_display}">
                <td class="trow1">
                    {$lang->chronospost_publish_on}: 
                    <input type="date" name="schedule_date" value="{$sdate}" class="textbox" />
                    &nbsp; {$lang->chronospost_at} &nbsp;
                    <input type="time" name="schedule_time" value="{$stime}" class="textbox" />
                </td>
            </tr>
        </tbody>
    </table>
    <script type="text/javascript">
        $(document).ready(function() {
            $("#schedule_active").change(function() {
                if($(this).is(":checked")) {
                    $("#schedule_options").show();
                } else {
                    $("#schedule_options").hide();
                }
            }).change();
        });
    </script>';

    $insert_array = array(
        'title'        => 'chronospost_form',
        'template'    => $db->escape_string($template),
        'sid'        => '-1',
        'version'    => '1.0',
        'dateline'    => TIME_NOW
    );
    $db->insert_query("templates", $insert_array);

    // Template in newthread/newreply einfügen - JETZT MIT GETRENNTEN VARIABLEN
    find_replace_templatesets("newthread", "#".preg_quote('{$attachbox}')."#i", '{$attachbox}{$chronospost_form_newthread}');
    find_replace_templatesets("newreply", "#".preg_quote('{$attachbox}')."#i", '{$attachbox}{$chronospost_form_newreply}');
    
}

function chronospost_deactivate()
{
    global $db;
    require_once MYBB_ROOT."/inc/adminfunctions_templates.php";
    
    // BEIDE Template-Variablen entfernen
    find_replace_templatesets("newthread", "#".preg_quote('{$chronospost_form_newthread}')."#i", '', 0);
    find_replace_templatesets("newreply", "#".preg_quote('{$chronospost_form_newreply}')."#i", '', 0);
    $db->delete_query("templates", "title = 'chronospost_form'");
}


// ============== KERNFUNKTIONEN ==============

// FINALE Veröffentlichungs-Funktion. Ändert nur die Sichtbarkeit und erhält IDs.
function chronospost_publish_posts()
{
    global $db;

    // Lade die notwendigen MyBB-Funktionssammlungen
    require_once MYBB_ROOT."/inc/functions_rebuild.php";
    require_once MYBB_ROOT."/inc/functions_post.php";

    $query = $db->simple_select("chronospost_schedule", "*", "publish_dateline <= " . TIME_NOW);
    
    while($scheduled_post = $db->fetch_array($query))
    {
        $pid = (int)$scheduled_post['pid'];
        $tid = (int)$scheduled_post['tid'];
        $uid = (int)$scheduled_post['uid'];
        $publish_time = (int)$scheduled_post['publish_dateline']; // Geplante Zeit holen

        // =========================================================================
        // KORREKTUR: Setze nicht nur 'visible', sondern auch 'dateline'
        // =========================================================================
        $db->update_query("posts", array("visible" => 1, "dateline" => $publish_time), "pid = '{$pid}'");
        
        if($scheduled_post['type'] == 'thread')
        {
            // Bei neuen Themen auch die Thread-Zeit und die "Letzter Beitrag"-Zeit anpassen
            $db->update_query("threads", array(
                "visible" => 1, 
                "dateline" => $publish_time,
                "lastpost" => $publish_time 
            ), "tid = '{$tid}'");
        }

        $thread = get_thread($tid);
        update_thread_data($tid); 
        rebuild_forum_counters($thread['fid']);
        $db->write_query("UPDATE ".TABLE_PREFIX."users SET postnum = postnum + 1 WHERE uid = '{$uid}'");

        $db->delete_query("chronospost_schedule", "sid = '{$scheduled_post['sid']}'");
    }
}

// Alle anderen Funktionen bleiben wie in der letzten Version
function chronospost_newthread()
{
    global $mybb, $lang, $templates, $post_errors, $chronospost_form_newthread, $forum;
    $is_allowed = chronospost_is_forum_allowed(); if(!$is_allowed) return;
    $allowed_groups = explode(',', $mybb->settings['chronospost_usergroups']);
    if(!is_member($allowed_groups) || $mybb->settings['chronospost_enabled'] == 0) return;
    $lang->load('chronospost');
    list($schedule_checked, $schedule_display, $sdate, $stime) = chronospost_get_form_values($post_errors);
    eval("\$chronospost_form_newthread = \"".$templates->get("chronospost_form")."\";");
}
function chronospost_newreply()
{
    global $mybb, $lang, $templates, $post_errors, $chronospost_form_newreply, $forum;
    $is_allowed = chronospost_is_forum_allowed(); if(!$is_allowed) return;
    $allowed_groups = explode(',', $mybb->settings['chronospost_usergroups']);
    if(!is_member($allowed_groups) || $mybb->settings['chronospost_enabled'] == 0) return;
    $lang->load('chronospost');
    list($schedule_checked, $schedule_display, $sdate, $stime) = chronospost_get_form_values($post_errors);
    eval("\$chronospost_form_newreply = \"".$templates->get("chronospost_form")."\";");
}
function chronospost_is_forum_allowed()
{
    global $mybb, $forum;
    $allowed_forums_setting = $mybb->settings['chronospost_area'];
    if(empty($allowed_forums_setting)) return false;
    if($allowed_forums_setting == '-1') return true;
    $allowed_forums = explode(',', $allowed_forums_setting);
    $parent_list = "," . $forum['parentlist'] . ",";
    foreach($allowed_forums as $allowed_fid) {
        if(preg_match("/,(".(int)$allowed_fid."),/i", $parent_list)) { return true; }
    }
    return false;
}
function chronospost_get_form_values($post_errors)
{
    global $mybb;
    $schedule_checked = ''; $schedule_display = 'display: none;';
    $sdate = date('Y-m-d'); $stime = date('H:i');
    if(isset($mybb->input['previewpost']) || $post_errors) {
        if($mybb->get_input('schedule_active', MyBB::INPUT_INT) == 1) {
            $schedule_checked = 'checked="checked"'; $schedule_display = '';
            $sdate = htmlspecialchars_uni($mybb->get_input('schedule_date'));
            $stime = htmlspecialchars_uni($mybb->get_input('schedule_time'));
        }
    }
    return array($schedule_checked, $schedule_display, $sdate, $stime);
}
function chronospost_validate($dh)
{
    global $mybb, $lang;
    if($mybb->get_input('schedule_active', MyBB::INPUT_INT) == 1) {
        $lang->load('chronospost');
        if(!$mybb->get_input('savedraft')) { $dh->set_error($lang->chronospost_error_draft); return; }
        $sdate_str = $mybb->get_input('schedule_date') . ' ' . $mybb->get_input('schedule_time');
        $publish_dateline = strtotime($sdate_str);
        if(empty($mybb->get_input('schedule_date')) || empty($mybb->get_input('schedule_time'))) { $dh->set_error($lang->chronospost_error_nodate); }
        if($publish_dateline < TIME_NOW) { $dh->set_error($lang->chronospost_error_past); }
        $dh->post_insert_data['visible'] = -2;
    }
}
function chronospost_save_schedule()
{
    global $mybb, $db, $tid, $pid;
    if($mybb->get_input('schedule_active', MyBB::INPUT_INT) == 1) {
        $sdate_str = $mybb->get_input('schedule_date') . ' ' . $mybb->get_input('schedule_time');
        $publish_dateline = strtotime($sdate_str);
        if(empty($pid)) { $thread = get_thread($tid); $pid = $thread['firstpost']; }
        $new_schedule = array( "pid" => (int)$pid, "tid" => (int)$tid, "uid" => (int)$mybb->user['uid'], "publish_dateline" => (int)$publish_dateline, "type" => (THIS_SCRIPT == 'newthread.php') ? 'thread' : 'reply' );
        $db->insert_query("chronospost_schedule", $new_schedule);
    }
}