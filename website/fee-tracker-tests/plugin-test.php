<?php
// Deterministic WordPress API stubs; this does not install or activate anything.
define('ABSPATH',__DIR__);
$admin=false;$options=[];$actions=[];
function get_option($key,$default=false){global $options;return $options[$key]??$default;}
function current_user_can($cap){global $admin;return $admin;}
function add_shortcode(...$args){}
function add_action($hook,$fn){global $actions;$actions[$hook][]=$fn;}
function register_deactivation_hook($path,$fn){global $deactivate;$deactivate=$fn;}
function wp_enqueue_style(...$args){}
function wp_enqueue_script_module(...$args){}
function plugins_url($path,$file){return 'https://mignonelabs.com/wp-content/plugins/carryaware-fee-tracker/'.$path;}
function esc_attr($value){return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function delete_option($key){global $options;unset($options[$key]);}
function do_action(...$args){}
require __DIR__.'/../plugins/carryaware-fee-tracker/carryaware-fee-tracker.php';
function check($yes,$label){if(!$yes){throw new Exception($label);}echo "PASS $label\n";}
check(!cft_can_render(),'anonymous private default');
check(!str_contains(cft_shortcode(),'data-feed-url'),'no dataset exposed in anonymous preview');
$admin=true;check(cft_can_render(),'administrator preview');
$html=cft_shortcode();check(str_contains($html,CFT_FEED_URL),'fixed canonical feed');
check(str_contains($html,'New Jersey Municipal'),'real tracker shell');
$admin=false;$options['cft_public_enabled']=true;check(cft_can_render(),'explicit public mode');
$deactivate();check(!cft_can_render(),'deactivation resets public mode');
check(str_contains($html,'support@mignonelabs.com'),'correction address');
