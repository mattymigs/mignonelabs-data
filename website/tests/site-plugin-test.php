<?php
// Deterministic unit tests with WordPress API stubs. Not a real-site integration/restore test.
error_reporting(E_ALL);
set_error_handler(static function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('ABSPATH', __DIR__); define('MINUTE_IN_SECONDS', 60);
class WP_Post { public $ID=32; public $post_status='publish'; public $post_excerpt='Clear & useful information.'; public $post_name='carryaware-release-notes'; public $post_title='Release Notes'; }
class WP_Error {}
class WP_REST_Response { public function __construct($data) {$this->data=$data;} public $data; public function header($a,$b) {} }
$GLOBALS['options']=array('date_format'=>'F j, Y', 'page_on_front'=>9);
$GLOBALS['transients']=array(); $GLOBALS['actions']=array(); $GLOBALS['filters']=array();
$GLOBALS['admin']=false; $GLOBALS['is_page']=true; $GLOBALS['nonce_ok']=false; $GLOBALS['remote_calls']=0;
function esc_html($v) {return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');}
function esc_attr($v) {return esc_html($v);} function esc_url($v){return esc_html($v);} function esc_url_raw($v){return $v;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['options'][$key]);}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;$GLOBALS['ttl'][$key]=$ttl;}
function wp_safe_remote_get($url,$args){$GLOBALS['remote_calls']++;$GLOBALS['request']=array($url,$args);return $GLOBALS['remote'];}
function is_wp_error($r){return $r instanceof WP_Error;}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function shortcode_atts($defaults,$attributes,$tag){return array_merge($defaults,$attributes);}
function wp_date($f,$t){return gmdate($f,$t);}
function ml_get_carryaware_app_data(){return $GLOBALS['app'];}
function is_admin(){return false;} function is_page($id){return $GLOBALS['is_page'];}
function add_filter($h,$cb,$priority=10,$accepted=1){$GLOBALS['filters'][$h][]=$cb;}
function add_action($h,$cb,$priority=10,$accepted=1){$GLOBALS['actions'][$h][]=$cb;}
function do_action($h,...$args){foreach($GLOBALS['actions'][$h]??array() as $cb){$cb(...$args);}}
function add_shortcode($name,$cb){$GLOBALS['shortcodes'][$name]=$cb;}
function register_deactivation_hook($file,$cb){$GLOBALS['deactivate']=$cb;}
function current_user_can($cap){return $GLOBALS['admin'];}
function wp_verify_nonce($nonce,$action){return $GLOBALS['nonce_ok'];}
function wp_unslash($s){return $s;} function sanitize_text_field($s){return strip_tags($s);}
function nocache_headers(){$GLOBALS['nocache']=true;}
function register_rest_route($ns,$route,$args){$GLOBALS['route']=$args;}
function wp_strip_all_tags($s){return strip_tags($s);} function strip_shortcodes($s){return preg_replace('/\[[^\]]+\]/','',$s);}
function get_permalink($p){return 'https://mignonelabs.com/'.$p->post_name.'/';}
function get_the_title($p){return $p->post_title;}
function get_bloginfo($v){return 'Mignone Labs';} function get_locale(){return 'en_US';}
function get_post_thumbnail_id($id){return in_array($id,array(9,80))?75:0;}
function get_page_by_path($slug){$p=new WP_Post();if($slug==='carryawarenj'){$p->ID=80;$p->post_name=$slug;}return $p;}
function wp_get_attachment_image_src($id,$s){return array('https://mignonelabs.com/icon.jpg',512,512);}
function get_post_meta($id,$key,$single){return 'Official app icon';} function absint($i){return abs((int)$i);}
require dirname(__DIR__).'/plugins/mignone-labs-site/mignone-labs-site.php';
$tests=0;
function check($condition,$name){global $tests;$tests++;if(!$condition){throw new RuntimeException('FAIL: '.$name);}echo 'PASS '.$name."\n";}
$app=array('trackId'=>6762150416,'version'=>'1.0.5','currentVersionReleaseDate'=>'2026-09-09T01:12:17Z','releaseNotes'=>"New features\n\n* One\n• Two\n- Three\n\nThanks.");
$GLOBALS['app']=$app;
$archive=array('app'=>array('slug'=>'carryawarenj','appleId'=>'6762150416'),'releases'=>array(
array('version'=>'1.0.4','releaseDate'=>'2026-08-31','releaseNotes'=>'Older notes.'),
array('version'=>'1.0.5','releaseDate'=>'2026-09-09','releaseNotes'=>'Current notes.'),
array('version'=>'1.0.6','releaseDate'=>'2026-09-10','releaseNotes'=>'Not public yet.')));
$GLOBALS['remote']=array('code'=>200,'body'=>json_encode($archive));
check(Mignone_Labs_Site::notes("Intro\n\n* A\n• B\n- C\n\nEnd") === '<p>Intro</p><ul><li>A</li><li>B</li><li>C</li></ul><p>End</p>','semantic bullets preserve paragraph order');
check(Mignone_Labs_Site::notes("A\r\nB") === '<p>A<br>B</p>','CRLF line breaks');
check(Mignone_Labs_Site::notes('') === '', 'empty notes');
check(!str_contains(Mignone_Labs_Site::notes('* <script>alert(1)</script>'),'<script>'),'script escaped in notes');
check(Mignone_Labs_Site::notes(str_repeat('x',30001))==='','oversized notes rejected');
check(Mignone_Labs_Site::notes("\xFF")==='','invalid UTF-8 rejected');
check(Mignone_Labs_Site::valid_version('1.0.5') && !Mignone_Labs_Site::valid_version('dev') && !Mignone_Labs_Site::valid_version("1.0\n"),'numeric version validation');
check(Mignone_Labs_Site::valid_archive($archive),'valid archive accepted');
$bad=$archive;$bad['app']['appleId']='wrong';check(!Mignone_Labs_Site::valid_archive($bad),'wrong app rejected');
$bad=$archive;$bad['releases'][0]['releaseDate']='2026-02-30';check(!Mignone_Labs_Site::valid_archive($bad),'impossible date rejected');
$bad=$archive;$bad['releases'][0]['version']='1.0.5.0';check(!Mignone_Labs_Site::valid_archive($bad),'equivalent duplicate version rejected');
$bad=$archive;$bad['releases'][0]['releaseNotes']='';check(!Mignone_Labs_Site::valid_archive($bad),'missing notes rejected');
$bad=$archive;$bad['releases']=array_fill(0,201,$archive['releases'][0]);check(!Mignone_Labs_Site::valid_archive($bad),'oversized archive rejected');
check(Mignone_Labs_Site::format_current('original','unrelated')==='original','unrelated shortcode unchanged');
$current=Mignone_Labs_Site::format_current('','carryaware_current_release');
check(str_contains($current,'Version 1.0.5') && str_contains($current,'<ul><li>One</li>'),'current release uses semantic renderer');
check(str_contains($current,'<time datetime="2026-09-09T01:12:17+00:00">'),'release timestamp retained');
$GLOBALS['app']=array();$fallback=Mignone_Labs_Site::format_current('','carryaware_current_release');
check(str_contains($fallback,'Last known public release') && str_contains($fallback,'Version 1.0.5'),'Apple failure uses explicitly labeled last-good record');
$GLOBALS['app']=$app;$GLOBALS['app']['version']='1.0.3';
check(str_contains(Mignone_Labs_Site::format_current('','carryaware_current_release'),'Version 1.0.5'),'older Apple response cannot regress current release');
$GLOBALS['app']=$app;
$history=Mignone_Labs_Site::history_shortcode();
check(str_contains($history,'Version 1.0.4')&&!str_contains($history,'Version 1.0.5')&&!str_contains($history,'Version 1.0.6'),'archive excludes current and future versions');
check($GLOBALS['request'][1]['redirection']===0 && $GLOBALS['request'][1]['limit_response_size']===524288,'remote archive redirects disabled and response bounded');
$n=$GLOBALS['remote_calls'];Mignone_Labs_Site::history_shortcode();check($GLOBALS['remote_calls']===$n,'successful archive cached');
$GLOBALS['transients']=array();$GLOBALS['remote']=new WP_Error();
check(str_contains(Mignone_Labs_Site::history_shortcode(),'cached history') && str_contains(Mignone_Labs_Site::history_shortcode(),'1.0.4'),'archive outage uses labeled last-good history');
$GLOBALS['transients']=array();unset($GLOBALS['options']['mls_history_last_good']);Mignone_Labs_Site::history_shortcode();$n=$GLOBALS['remote_calls'];Mignone_Labs_Site::history_shortcode();
check($GLOBALS['remote_calls']===$n,'archive failure without history is negatively cached');
check(Mignone_Labs_Site::history_shortcode(array('app'=>'../../etc/passwd'))==='','unconfigured archive slug rejected');
check(Mignone_Labs_Site::insert_archive('No slot')==='No slot','other content unchanged');
$p=new WP_Post();$meta=Mignone_Labs_Site::metadata_html($p);
check(str_contains($meta,'name="description"')&&str_contains($meta,'property="og:description"')&&str_contains($meta,'name="twitter:description"'),'server-rendered metadata');
check(str_contains($meta,'og:image:alt')&&str_contains($meta,'twitter:image:alt'),'CarryAware image and alt metadata');
$p->post_name='flockaware';check(!str_contains(Mignone_Labs_Site::metadata_html($p),'og:image'),'FlockAware does not inherit CarryAware icon');
$p->post_excerpt='"><script>alert(1)</script>';check(!str_contains(Mignone_Labs_Site::metadata_html($p),'<script>'),'metadata escaped');
$p->post_excerpt='';check(Mignone_Labs_Site::metadata_html($p)==='','empty excerpt skipped');
$p->post_excerpt='private';$p->post_status='draft';check(Mignone_Labs_Site::metadata_html($p)==='','unpublished page skipped');
$_GET=array();do_action('wp');check(empty($GLOBALS['filters']['the_content']),'activation is preview-only by default');
$_GET=array('mls_preview'=>'bad');do_action('wp');check(empty($GLOBALS['filters']['the_content']),'anonymous preview request rejected');
$GLOBALS['admin']=true;do_action('wp');check(empty($GLOBALS['filters']['the_content']),'administrator still needs valid preview nonce');
$GLOBALS['nonce_ok']=true;do_action('wp');check(!empty($GLOBALS['filters']['the_content'])&&!empty($GLOBALS['nocache']),'authorized preview enables hooks without page caching');
do_action('rest_api_init');$permission=$GLOBALS['route']['permission_callback'];$GLOBALS['admin']=false;check(!$permission(),'REST preview requires administrator');$GLOBALS['admin']=true;check($permission(),'REST preview permits administrator');
$GLOBALS['options']['mls_live']=true;$callback=$GLOBALS['deactivate'];$callback();check(!get_option('mls_live',false),'deactivation resets future activation to preview-only');
echo "$tests tests passed. WordPress APIs stubbed; site verification still required.\n";
