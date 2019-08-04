<?php


function block_bug($p_bug)
{
    block_bug_kind($p_bug, 'block_bug_delete_user');
    block_bug_kind($p_bug, 'block_bug_disable_user');
    block_bug_kind($p_bug, 'block_bug');
}


function block_bug_kind($p_bug, $p_config_name) 
{
    $query = 'SELECT value FROM ' . plugin_table('config') . " WHERE name='" . $p_config_name . "'";
    $result = db_query($query);
    $row = db_fetch_array($result);
    if (!$row) {
        return;
    }

    $t_value = $row['value'];
    $t_value = str_replace("\r\n", "", $t_value); # bbcodeplus will add CR
    $t_value = str_replace("\n", "", $t_value);

    $t_keywords = explode(",", $t_value);

    $t_disable_user = (strpos($p_config_name, "disable") !== false);
    $t_delete_user = (strpos($p_config_name, "delete") !== false);
    
    #
    # Convert keyword list to regex and apply to bug subject, notes, etc
    #
    if (count($t_keywords) > 0 && !is_blank($t_keywords[0]))
    {
        $t_regex = "/(";
        foreach ($t_keywords as $t_keyword) {
            $t_regex = $t_regex.$t_keyword.'|';
        }
        $t_regex = rtrim($t_regex, "|").")+/i";

        check_text($p_bug, $t_regex, $p_bug->summary, $t_disable_user, $t_delete_user);
        check_text($p_bug, $t_regex, $p_bug->description, $t_disable_user, $t_delete_user);
        check_text($p_bug, $t_regex, $p_bug->steps_to_reproduce, $t_disable_user, $t_delete_user);
        check_text($p_bug, $t_regex, $p_bug->additional_information, $t_disable_user, $t_delete_user);
    }
}


function check_text($p_bug, $p_regex, $p_text, $p_disable_user = false, $p_delete_user = false)
{
    if (!is_blank($p_text)) {
        preg_match_all( $p_regex, $p_text, $t_matches );
        foreach( $t_matches[0] as $t_substring ) 
        {
            $t_user_id = auth_get_current_user_id();
            $t_user_name = user_get_username($t_user_id);
            $t_user_email = user_get_email($t_user_id);

            if (!$p_disable_user && !$p_delete_user) 
            {
                log_securityextend_event($t_user_name, $t_user_email, 'block_bug', $p_bug->summary, $p_bug->description, $p_bug->steps_to_reproduce);
                trigger_error(ERROR_SPAM_SUSPECTED, ERROR);
            }
            else 
            {
                auth_logout();

                if ($p_disable_user) 
                {
                    user_set_field($t_user_id, 'enabled', 0);
                    log_securityextend_event($t_user_name, $t_user_email, 'block_bug_disable_user', $p_bug->summary, $p_bug->description, $p_bug->steps_to_reproduce);
                }
                else 
                {
                    user_delete( $t_user_id );
                    log_securityextend_event($t_user_name, $t_user_email, 'block_bug_delete_user', $p_bug->summary, $p_bug->description, $p_bug->steps_to_reproduce);
                }

                if (!plugin_config_get('show_bird_on_bug_block')) {
                    print_header_redirect('');
                }
                else {
                    print_header_redirect(plugin_page('thebird', true));
                }
            }
        }
    }
}


function get_mantis_base_url()
{
    return sprintf(
      "%s://%s/",
      isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
      $_SERVER['SERVER_NAME']
    );
}


function is_email_forbidden($p_email)
{
    $t_db_table = plugin_table('log');
    $t_query = "SELECT COUNT(*) FROM $t_db_table WHERE email='$p_email' AND action IN ('block_bug_disable_user', 'block_bug_delete_user')";
    $t_result = db_query($t_query);
    $t_row_count = db_result($t_result); 
    if ($t_row_count >= 1) {
        return true;
    }
    return false;
}


function log_securityextend_event($p_user, $p_email, $p_action, $p_xdata1 = '', $p_xdata2 = '', $p_xdata3 = '')
{
    $t_db_table = plugin_table('log');
    $t_query = "INSERT INTO $t_db_table (user, email, date, action, xdata1, xdata2, xdata3) VALUES (?, ?, NOW(), ?, ?, ?, ?)";
    db_query($t_query, array($p_user, $p_email, $p_action, $p_xdata1, $p_xdata2, $p_xdata3));
}


function print_log_section($p_section_name, $p_current_tab)
{
    $t_block_id = 'plugin_SecurityExtend_log_'.$p_section_name;
    $t_collapse_block = is_collapsed($t_block_id);
    $t_block_css = $t_collapse_block ? 'collapsed' : '';
    $t_block_icon = $t_collapse_block ? 'fa-chevron-down' : 'fa-chevron-up';

    echo '
    <div id="' . $t_block_id . '" class="widget-box widget-color-blue2 no-border ' . $t_block_css . '">

        <div class="widget-header widget-header-small">
            <h4 class="widget-title lighter">
                <i class="ace-icon fa fa-file-alt"></i>
                ' . plugin_lang_get('management_log_'.$p_section_name.'_label') . '
            </h4>
            <div class="widget-toolbar">
                <a data-action="collapse" href="#">
                    <i class="ace-icon fa ' . $t_block_icon . ' bigger-125"></i>
                </a>
            </div>
        </div>

        <div class="widget-toolbox padding-8 clearfix">
            ' . plugin_lang_get('management_log_'.$p_section_name.'_description') . '<span class="pull-right"><a class="btn btn-xs btn-primary btn-white btn-round" href="' . 
                plugin_page('delete') . '&tab=' . urlencode($p_current_tab) . '&id=0&action=' . $p_section_name . '">' . plugin_lang_get('management_log_clear') . '</a></span>
        </div>

        <div class="widget-body">
            <div class="widget-main no-padding">
                <div class="form-container">
                    <div class="table-responsive">
                        <table class="table table-bordered table-condensed table-striped">';

    $t_user_has_edit_access = access_has_global_level(plugin_config_get('edit_threshold_level'));
    $t_query = 'SELECT id, user, email, date, xdata1, xdata2, xdata3 FROM ' . plugin_table('log') . ' WHERE action=? ORDER BY date DESC';
    $t_result = db_query($t_query, array($p_section_name));
    
    echo '                  <tr>
                                <td class="category" width="140">
                                    ' . lang_get('date_created') . '
                                </td>
                                <td class="category" width="140">
                                    ' . lang_get('username'). '
                                </td>
                                <td class="category" width="180">
                                    ' . lang_get('email') . '
                                </td>
                                <td class="category">
                                    ' . lang_get('summary')  . ' / ' . lang_get('description') . ' / ' . lang_get('steps_to_reproduce') . '
                                </td>
                                ' . ($t_user_has_edit_access ? '<td class="category"></td>' : '') . '
                            </tr>';

    if (db_num_rows($t_result) == 0)
    {
        echo '<tr><td colspan="6">0 events found</td></tr>';
    } 
    else
    {
        while ($t_row = db_fetch_array($t_result)) 
        {
            echo '          <tr ' . helper_alternate_class() . '>
                                <td>
                                    ' . $t_row['date'] . '
                                </td>
                                <td>
                                    ' . $t_row['user']. '
                                </td>
                                <td>
                                    ' . $t_row['email']. '
                                </td>
                                <td>
                                    ' . (!is_blank($t_row['xdata1']) ? '<font style="color:#5090c1">' . lang_get('summary') . '</font><br>' : '')  . '
                                    ' . (!is_blank($t_row['xdata1']) ? htmlspecialchars($t_row['xdata1']) : '')  . '
                                    ' . (!is_blank($t_row['xdata2']) ? '<br><font style="color:#5090c1">' . lang_get('description') . '</font><br>' : '')  . '
                                    ' . (!is_blank($t_row['xdata2']) ? htmlspecialchars($t_row['xdata2']) : '')  . '
                                    ' . (!is_blank($t_row['xdata3']) ? '<br><font style="color:#5090c1">' . lang_get('steps_to_reproduce') . '</font><br>': '')  . '
                                    ' . (!is_blank($t_row['xdata3']) ? htmlspecialchars($t_row['xdata3']) : '')  . '
                                </td>' . 
                                ($t_user_has_edit_access ? '<td width="55"><a class="btn btn-xs btn-primary btn-white btn-round" href="' . plugin_page('delete') . 
                                    '&tab=' . urlencode($p_current_tab) . '&id=' . $t_row['id'] . '&action=' . $p_section_name . '">' . lang_get('delete_link') . '</a></td>' : '') . '
                            </tr>';
        }
    }
    echo '              </table>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    <div class="space-10"></div>';
}


function print_save_button_footer()
{
    echo '<div class="widget-toolbox padding-8 clearfix">
        <input type="submit" class="btn btn-primary btn-white btn-round" value="' . lang_get('save') . '" />
    </div>';
}


function print_section($p_section_name, $p_content, $p_fa_icon = 'fa-bug')
{
    $t_block_id = 'plugin_SecurityExtend_'.$p_section_name;
    $t_collapse_block = is_collapsed($t_block_id);
    $t_block_css = $t_collapse_block ? 'collapsed' : '';
    $t_block_icon = $t_collapse_block ? 'fa-chevron-down' : 'fa-chevron-up';

    echo '
    <div id="' . $t_block_id . '" class="widget-box widget-color-blue2  no-border ' . $t_block_css . '">

        <div class="widget-header widget-header-small">
            <h4 class="widget-title lighter">
                <i class="ace-icon fa ' . $p_fa_icon . '"></i>
                ' . plugin_lang_get('management_'.$p_section_name.'_label') . '
            </h4>
            <div class="widget-toolbar">
                <a data-action="collapse" href="#">
                    <i class="ace-icon fa ' . $t_block_icon . ' bigger-125"></i>
                </a>
            </div>
        </div>

        <div class="widget-toolbox padding-8 clearfix">
            ' . plugin_lang_get('management_'.$p_section_name.'_description') . '
        </div>

        <div class="widget-body">
            <div class="widget-main no-padding">
                <div class="form-container">
                    <div class="table-responsive">
                        <table class="table table-bordered table-condensed table-striped">
                            <fieldset>
                                <tr>
                                    <td>
                                        ' . $p_content . '
                                    </td>
                                </tr>
                            </fieldset>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
    </div>';
}


function print_failure_and_redirect($p_redirect_url, $p_message = '', $p_die = true)
{
    layout_page_header(null, $p_redirect_url);
    layout_page_begin();
    html_operation_failure($p_redirect_url, $p_message);
    layout_page_end();
    if ($p_die) {
        die();
    }
}


function print_success_and_redirect($p_redirect_url, $p_message = '', $p_die = false)
{
    layout_page_header(null, $p_redirect_url);
    layout_page_begin();
    html_operation_successful($p_redirect_url, $p_message);
    layout_page_end();
    if ($p_die) {
        die();
    }
}


function print_tab($p_tab_title, $p_current_tab_title)
{
    $t_tab_title = '';
    if ($p_tab_title == 'Info') {
        $t_tab_title = '<i class="blue ace-icon fa fa-info-circle"></i>';
    }
    else {
        $t_tab_title = $p_tab_title;
    }
    $menu_item = '<a href="' . plugin_page('securityextend') . '&tab=' . urlencode($p_tab_title) . '">' . $t_tab_title . '</a>';
    $active = $p_current_tab_title === $p_tab_title ? ' class="active"' : '';
    echo "<li{$active}>" . $menu_item . '</li>';
}


function print_tab_bar()
{
    $t_first_tab_title = plugin_lang_get('management_info_title');
    $t_current_tab = gpc_get_string('tab', null);
    $t_is_first_page = ($t_current_tab === null);
    if ($t_is_first_page) {
        $t_current_tab = $t_first_tab_title; 
    }

    echo '<ul class="nav nav-tabs padding-18" style="margin-top:5px;margin-left:5px;">' . "\n";
    
    print_tab($t_first_tab_title, $t_current_tab);
    print_tab(plugin_lang_get('management_block_bug_title'), $t_current_tab);
    print_tab(plugin_lang_get('management_block_domain_title'), $t_current_tab);
    print_tab(plugin_lang_get('management_log_title'), $t_current_tab);

    echo '</ul>' . "\n<br />";

    return $t_current_tab;
}


function print_textarea_section($p_field_name, $p_fa_icon = 'fa-bug')
{
    $t_value = '';
    $query = "SELECT value FROM " . plugin_table('config') . " WHERE name='$p_field_name'";
    $result = db_query($query);
    if ($row = db_fetch_array($result)) {
        if (!$row) {
            trigger_error(ERROR_FILE_NOT_FOUND, ERROR);
        }
        $t_value = $row['value'];
    }
    $t_field = '<textarea name="' . $p_field_name . '" rows="5" spellcheck="true" style="width:100%" />' . $t_value . '</textarea>';
    print_section($p_field_name, $t_field, $p_fa_icon);
}


function save_config_value($p_config_name, $p_config_value)
{
    $t_db_table = plugin_table('config');
    $t_query = "SELECT COUNT(*) FROM $t_db_table WHERE name='$p_config_name'";
    $t_result = db_query($t_query);
    $t_row_count = db_result($t_result); 
    if ($t_row_count < 1) {
        $t_query = "INSERT INTO $t_db_table (name, value) VALUES ('$p_config_name', ?)";
    }
    else {
        $t_query = "UPDATE $t_db_table SET value=? WHERE name='$p_config_name'";
    }
    db_query($t_query, array($p_config_value));
}
