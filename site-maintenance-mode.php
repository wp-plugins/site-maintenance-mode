<?php
/*
Plugin Name: Site maintenance mode
Plugin URI: http://blog.kplus.pro/wp/plugin-site-maintenance-mode.html
Description: Add feature "Site closed for maintenance"
Author: Nikolay Samoylov
Version: 0.1.3
*/

/*
LICENSE:
DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
 
Everyone is permitted to copy and distribute verbatim or modified
copies of this license document, and changing it is allowed as long
as the name is changed.
 
DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION
 
0. You just DO WHAT THE FUCK YOU WANT TO. -->
*/

if(!defined('ABSPATH')) exit;

if(!class_exists('site_maintenance_mode')) {
  class site_maintenance_mode {
    var $prefix = 'mm_';
    var $lng_domain = 'site-maintenance-mode';
    function __construct() {
      // Add link to settings in plugins page
      add_filter('plugin_action_links_'.plugin_basename(__FILE__), array($this, 'add_settings_link'));
      
      // Add widget to WP dashboard
      add_action('admin_init', array($this, 'init_dashboard'), 10);
      
      // Setup default values on plugin first activate
      register_activation_hook(__FILE__, array($this, 'check_install'));
      
      // Register (add) settings page
      add_action('admin_menu', array($this, 'add_settings_page'), 5);
      
      // Add maintenance page hook
      add_action('template_redirect', array($this, 'redirect'));
      
      // Register uninstall hook
      register_uninstall_hook(__FILE__, array($this, 'uninstall'));
      
      // Load localization
      add_action('plugins_loaded', array($this, 'load_lang'));
    }

    // Maintenance mode is enabled?
    function is_enabled() {
      return (bool) get_option($this->prefix.'enabled');
    }
    
    // Return roles array
    function get_roles(){
      if(!function_exists('get_editable_roles')) require_once(ABSPATH.'/wp-admin/includes/user.php');
      $result = get_editable_roles(); // init roles array
      $result['nobody'] = array('name' => 'nobody'); // Add 'nobody' role
      unset($result['administrator']); // remove 'administrator' roles from list
      return $result;
    }
    
    // Return user role
    function get_active_role(){
      global $current_user;
      if(!empty($current_user->roles[0])) return $current_user->roles[0];
      return 'nobody';
    }

    // Add link to settings in plugins page
    function add_settings_link($links) {
      $links[] = '<a href="'.esc_url(get_admin_url(null, 'options-general.php?page='.basename(__FILE__))).'">'.__('Settings').'</a>';
      return $links;
    }


    // Load localization
    function load_lang() {
      load_plugin_textdomain($this->lng_domain, false, dirname(plugin_basename(__FILE__)).'/lang');
    }
    
    // Add widget to WP dashboard
    function add_dashboard_widget(){
      wp_add_dashboard_widget('dashboard_widget', __('Maintenance mode', $this->lng_domain), array($this, 'dashboard_widget_data'));
    }
    // Dashboard widget JavaScript
    function maintenance_mode_change_state_javascript() { ?>
      <script type="text/javascript">
        (function($){$(function(){
          var mm_button = $('#<?php echo($this->prefix); ?>activate'),
              mm_toolbar = $('#<?php echo($this->prefix); ?>toolbar_notify');
          mm_button.on('click', function(){
            mm_button.blur();
            $.post(ajaxurl, {'action': 'maintenance_mode_change_state', 'enabled': mm_button.attr('data-action')}, function(response){
              try {
                response = JSON.parse(response);
                console.log(response);
                if(response.success){
                  mm_button
                    .attr('value', '<?php _e('Turn off maintenance mode', $this->lng_domain); ?>')
                    .removeClass('button-secondary').addClass('button-primary')
                    .attr('data-action', 'false');
                  mm_toolbar.show();
                } else {
                  mm_button
                    .attr('value', '<?php _e('Turn on maintenance mode', $this->lng_domain); ?>')
                    .removeClass('button-primary').addClass('button-secondary')
                    .attr('data-action', 'true');
                  mm_toolbar.hide();
                }
                mm_button.blur();
              } catch (err) {
                mm_button.attr('value', '<?php _e('Invalid server response', $this->lng_domain); ?>').attr('disabled','disabled');
              }
            });
          });
        })}(jQuery));
      </script>
    <?php }
    // Dashboard widget HTML code
    function dashboard_widget_data(){ 
      $enabled = $this->is_enabled();
      echo '<p align="center">'.
             '<input type="button" id="'.$this->prefix.'activate" class="'.($enabled ? 'button-primary' : 'button-secondary').'" value="'.($enabled ? __('Turn off maintenance mode', $this->lng_domain) : __('Turn on maintenance mode', $this->lng_domain)).'" data-action="'.($enabled ? 'false' : 'true').'" />'.
           '</p>';
    }
    // AJAX answer generate
    function maintenance_mode_change_state_callback(){
      $result = array();
      if(isset($_POST['enabled'])) {
        update_option($this->prefix.'enabled', ($_POST['enabled'] === 'true' ? true : false));
        $result['success'] = $this->is_enabled();
      }
      echo(json_encode($result)); wp_die();
    }
    // Show notify label in administrator bar
    function toolbar_notify() {
      global $wp_admin_bar; // TODO: При изменении настроек со страницы настроек плагина - обновляется после того, как статус уже изменился
      $wp_admin_bar->add_node(array(
        'id' => $this->prefix.'toolbar_notify',
        'title' => '<div id="'.$this->prefix.'toolbar_notify" style="display:'.($this->is_enabled() ? 'block' : 'none').'; background-color:#f00; color:#fff; padding: 0 15px; cursor:default">'.__('Maintenance mode enabled', $this->lng_domain).'</div>',
      ));
    }
    // Init dashboard actions
    function init_dashboard(){
      if(current_user_can('administrator')) {
        add_action('admin_footer', array($this, 'maintenance_mode_change_state_javascript'));
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        add_action('wp_ajax_maintenance_mode_change_state', array($this, 'maintenance_mode_change_state_callback'));
      }
      add_action('admin_bar_menu', array($this, 'toolbar_notify'), 990);
    }


    // Setup default values
    function set_defaults(){
      // Generate css keyframes (Transform+rotate_Function)
      function tf($class_name, $from_value, $to_value) {
        $prefixes = array('-webkit-', '-moz-', '-o-', '');
        foreach($prefixes as $keyprefix) {
          $result .= '@'.$keyprefix.'keyframes '.$class_name.'{0%{';
          foreach($prefixes as $prefix) $result .= $prefix.'transform:rotate('.$from_value.');'; $result .= '}100%{';
          foreach($prefixes as $prefix) $result .= $prefix.'transform:rotate('.$to_value.');';   $result .= '}}';
        }
        return $result."\n";
      }
      // Array with default values
      $defaults = array(
        $this->prefix.'enabled'     => false,
        $this->prefix.'roles'       => 'subscriber,nobody',
        $this->prefix.'head_title'  => wp_strip_all_tags(__('Site closed for maintenance', $this->lng_domain)),
        $this->prefix.'head_styles' => wp_strip_all_tags("@import url(//fonts.googleapis.com/css?family=PT+Sans&subset=cyrillic-ext,latin);\nhtml,body{background-color: #fddd34; margin:0; padding:0; color: #360505; font-family: 'PT Sans',Helvetica Neue,Helvetica,Arial,sans-serif; overflow: hidden}\n.rotation{-webkit-animation-duration:10s;-webkit-animation-iteration-count:infinite;-webkit-animation-timing-function:linear;-moz-animation-duration:10s;-moz-animation-iteration-count:infinite;-moz-animation-timing-function:linear;-o-animation-duration:10s;-o-animation-iteration-count:infinite;-o-animation-timing-function:linear;animation-duration:10s;animation-iteration-count:infinite;animation-timing-function:linear}\n.rotation.forward{-webkit-animation-name:forward_rotation;-moz-animation-name:forward_rotation;-o-animation-name:forward_rotation;animation-name:forward_rotation}\n.rotation.backward{-webkit-animation-name:backward_rotation;-moz-animation-name:backward_rotation;-o-animation-name:backward_rotation;animation-name:backward_rotation}\n".tf('forward_rotation', '0deg', '360deg').tf('backward_rotation', '342deg', '-18deg').".icon_gear{background:url(//habrastorage.org/files/cd8/693/a09/cd8693a09e8c49f29130aa78d14311b1.png) no-repeat center center;background-size: 100%}\n.icon_gear.large{width: 192px; height: 192px}\n.icon_gear.medium{width: 144px; height: 144px}\n.icon_gear.small{width: 96px; height: 96px}\na{color: #950505; text-decoration: none}\na:hover{color: #c60606; text-decoration: underline}\n.wrap{position:absolute;width:800px;height:300px;left:50%;top:50%;margin-left:-400px;margin-top:-150px}\n.wrap .texts{padding-right:330px;font-size:120%}\n.wrap .texts p{font-size:90%;letter-spacing:-1px}\n.gear{position:absolute;top:0;right:0}\n#gear1{top:100px}\n#gear2{right:140px;top:10px}\n#gear3{right:230px;top:133px}\n@media screen and (max-width:820px){\n.wrap{position:relative; width:auto; height:auto; margin:0; padding: 0 30px; left: 0}\n.wrap .texts{padding-right: 0}\n.gears{display:none}\n}"),
        $this->prefix.'body_html' => "<div class=\"wrap\">\n  <div class=\"texts\">\n    <h1>".__('Site temporarily unavailable', $this->lng_domain)."</h1>\n    <h2>".__('Currently on the site are technical works', $this->lng_domain)."</h2>\n    <p>".__('We apologise for any inconvenience', $this->lng_domain)."</p>\n    <p><a href=\"".wp_login_url()."\">".__('Login for Administrators', $this->lng_domain)."</a></p>\n  </div>\n  <div class=\"gears\">\n    <div id=\"gear1\" class=\"gear icon_gear large rotation forward\"></div>\n    <div id=\"gear2\" class=\"gear icon_gear medium rotation backward\"></div>\n    <div id=\"gear3\" class=\"gear icon_gear small rotation forward\"></div>\n  </div>\n</div>",
      );
      // Setup values
      foreach($defaults as $key => $value) update_option($key, $value);
    }
    // Setup default values on plugin first activate
    function check_install(){
      if((get_option($this->prefix.'body_html') == '') || (get_option($this->prefix.'roles') == ''))
        $this->set_defaults();
    }

    
    // Settings page CSS styles
    function settings_page_styles(){ ?>
      <style type="text/css">
        form div.settings input[type='text']{width:99.9%;}
        div.settings-error{margin: 5px 10px 15px 0 !important;}
        dl.user_roles{margin-top: 6px; margin-bottom: 0;}
        dl.user_roles dd{margin-left: 0;}
        dl.user_roles input{margin-right: 6px;}
      </style>
    <? }
    // Settings page data
    function settings_page(){
      // For settings page - get min and max textarea rows size
      function textarea_autoheight($miltiline_string, $min = 3, $max = 10){
        $lines_count = substr_count($miltiline_string, "\n");
        $result = $lines_count > $min ? $lines_count + 1 : $min; // auto height textarea..
        $result = $result < $max ? $result : $max; // ..but not more then this value
        return intval($result);
      }
      
      if(isset($_POST['save'])){ // Save settings in page
        $roles4save = array();
        foreach($this->get_roles() as $role => $role_data) if(isset($_POST['users_role_'.$role])) array_push($roles4save, $role);
        
        if(isset($_POST['enabled']))     update_option($this->prefix.'enabled',     ($_POST['enabled'] == 'true' ? true : false));
        if(isset($_POST['head_title']))  update_option($this->prefix.'head_title',  wp_strip_all_tags($_POST['head_title']));
        if(isset($_POST['head_styles'])) update_option($this->prefix.'head_styles', wp_strip_all_tags($_POST['head_styles']));
        if(isset($_POST['body_html']))   update_option($this->prefix.'body_html',   stripslashes($_POST['body_html']));
        if(!empty($roles4save))          update_option($this->prefix.'roles',       implode(',', $roles4save));
        
        add_settings_error('settings_updated', esc_attr('settings_updated'), __('Settings saved', $this->lng_domain), 'updated');
      }
      if(isset($_POST['reset'])){ // Maintenance mode
        $this->set_defaults();
        add_settings_error('settings_reseted', esc_attr('settings_reseted'), __('Settings reseted', $this->lng_domain), 'updated');
      }
      ?>
    <div class="wrap">
      <h2><?php echo(esc_html(__('Settings').' "'.__('maintenance mode', $this->lng_domain).'"')); ?></h2>
      <?php settings_errors(); ?>
      <form method="post">
        <div class="settings">
          <table class="form-table">
            <tbody>
              <tr>
                <th scope="row">
                  <?php _e('Mode active', $this->lng_domain); ?>
                </th><td>
                  <label>
                    <input type="radio" name="enabled" <?php checked(true, get_option($this->prefix.'enabled')); ?> value="true" /><?php _e('Yes'); ?>
                  </label>&nbsp;&nbsp;&nbsp;
                  <label>
                    <input type="radio" name="enabled" <?php checked(false, get_option($this->prefix.'enabled')); ?> value="false" /><?php _e('No'); ?>
                  </label>
                </td>
              </tr>
              <tr>
                <th scope="row">
                  <?php _e('Title'); ?>
                </th><td>
                  <input name="head_title" type="text" id="head_title" value="<?php echo(stripcslashes(get_option($this->prefix.'head_title'))); ?>" class="regular-text" />
                  <p class="description" id="tagline-description"><?php printf(__('This title will be specified in the tag %s', $this->lng_domain), '&lt;title&gt;'); ?></p>
                </td>
              </tr>
              <tr>
                <th scope="row">
                  <?php _e('CSS-styles', $this->lng_domain); ?>
                </th><td>
                  <?php
                    wp_editor(stripcslashes(get_option($this->prefix.'head_styles')), 'head_styles', array('tinymce' => false, 'textarea_rows' => textarea_autoheight(get_option($this->prefix.'head_styles'), 3, 30), 'quicktags' => false, 'media_buttons' => false, 'drag_drop_upload' => false));
                  ?>
                  <p class="description" id="tagline-description"><?php printf(__('These styles will be inserted into the tag %s', $this->lng_domain), '&lt;style&gt;'); ?></p>
                </td>
              </tr>
              <tr>
                <th scope="row">
                  <?php _e('Text to be displayed', $this->lng_domain); ?>
                </th><td>
                  <?php
                    wp_editor(html_entity_decode(get_option($this->prefix.'body_html')), 'body_html', array('tinymce' => false, 'textarea_rows' => textarea_autoheight(get_option($this->prefix.'body_html'), 10, 30), 'teeny' => true, 'media_buttons' => false, 'drag_drop_upload' => false));
                  ?>
                  <p class="description" id="tagline-description"><?php printf(__('These text is inserted into the tag %s (HTML code is allowed)', $this->lng_domain), '&lt;body&gt;'); ?></p>
                </td>
              </tr>
              <tr>
                <th scope="row">
                  <?php _e('Close access to the site for', $this->lng_domain) ?>
                </th><td>
                  <dl class="user_roles">
                  <?php
                    $roles_setting = explode(',', get_option($this->prefix.'roles'));
                    foreach($this->get_roles() as $role => $role_data){
                  ?>
                    <dd><label for="users_role_<?php echo($role); ?>"><input name="users_role_<?php echo($role); ?>" type="checkbox" id="users_role_<?php echo($role); ?>" value="0" <?php checked('1', in_array($role, $roles_setting)); ?> /><?php
                      switch ($role){
                        case 'author':      _e('Authors', $this->lng_domain); break;
                        case 'contributor': _e('Contributors', $this->lng_domain); break;
                        case 'editor':      _e('Editors', $this->lng_domain); break;
                        case 'subscriber':  _e('Subscribers', $this->lng_domain); break;
                        case 'nobody':      _e('Visitors', $this->lng_domain); break;
                        default: if(!empty($role_data['name'])) _e($role_data['name']); else _e($role);
                      }
                    ?></label>
                    </dd>
                  <?php } ?>
                  </dl>
                  <p class="description" id="tagline-description"><?php _e('Select a users groups for which the maintenance mode will be displayed instead of the contents of the site', $this->lng_domain); ?></p>
                </td>
              </tr>
              <tr>
                <th scope="row">
                  <input name="save" type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
                </th><td>
                  <input name="reset" type="submit" class="button-secondary" value="<?php _e('Reset settings', $this->lng_domain); ?>" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </form>
    </div>
    <?php }
    // Register (add) settings page
    function add_settings_page(){
      add_options_page(__('Maintenance mode', $this->lng_domain), __('Maintenance mode', $this->lng_domain), 'manage_options', basename(__FILE__), array($this, 'settings_page'));
      add_action('admin_head', array($this, 'settings_page_styles'));
      return;
    }


    // Check - page is exception?
    function is_exception_page(){
      return in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));
    }
    // Add maintenance page hook
    function redirect(){
      if($this->is_exception_page()) return;
      if(!$this->is_enabled()) return;
      if(in_array($this->get_active_role(), explode(',', get_option($this->prefix.'roles')))) {
        header('HTTP/1.1 503 Service Unavailable');
        exit('<!doctype html><html xmlns="http://www.w3.org/1999/xhtml">'.PHP_EOL.
             '  <head>'.PHP_EOL.
             '  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />'.PHP_EOL.
             '  <style media="all" type="text/css">'.stripcslashes(get_option($this->prefix.'head_styles')).'</style>'.PHP_EOL.
             '  <title>'.stripcslashes(get_option($this->prefix.'head_title')).'</title>'.PHP_EOL.
             '</head><body>'.html_entity_decode(get_option($this->prefix.'body_html')).'</body></html>');
      }
      return;
    }

    
    // Uninstall function
    function uninstall(){
      delete_option($this->prefix.'enabled');
      delete_option($this->prefix.'roles');
      delete_option($this->prefix.'head_title');
      delete_option($this->prefix.'head_styles');
      delete_option($this->prefix.'body_html');
    }
  }
  $GLOBALS['site_maintenance_mode'] = new site_maintenance_mode();
}