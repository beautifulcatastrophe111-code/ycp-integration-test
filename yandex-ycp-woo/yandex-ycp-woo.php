<?php
/**
 * Plugin Name: YCP Checkout Купить в 1 клик
 * Plugin URI:  https://github.com/perfinn/YCP-Yandex-Commerce-Woocommerce
 * Description: Интеграция магазина с Яндекс Чекаутом по протоколу YCP.
 * Version:     1.1.0
 */

if (!defined('ABSPATH')) exit;

class YCP_Yandex_Commerce_Woo {
    const OPT_TOKEN = 'ycpy_token';
    const OPT_LOG   = 'ycpy_log';
    const OPT_DEBUG = 'ycpy_debug_logs';
    const OPT_WAREHOUSES = 'ycpy_warehouses';
    const OPT_SHOPS = 'ycpy_shops';
    const OPT_OFFER_ID_SOURCE = 'ycpy_offer_id_source';
    const ENDPOINT='ycp/api/v1';

    public static function init(): void {
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_init',[__CLASS__,'register_settings']);
        add_action('init',[__CLASS__,'register_endpoint']);
        add_action('parse_request',[__CLASS__,'handle_request'],1);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
    }

    public static function activate(): void {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('WooCommerce required.');
        }
        self::register_endpoint(); flush_rewrite_rules();
    }
    public static function menu(): void { add_management_page('YCP Yandex','YCP Yandex Commerce','manage_options','ycp-yandex',[__CLASS__,'render_page']); }
    public static function register_settings(): void {
        register_setting('ycpy', self::OPT_TOKEN, ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('ycpy', self::OPT_DEBUG, ['type'=>'boolean','sanitize_callback'=>'rest_sanitize_boolean']);
        register_setting('ycpy', self::OPT_OFFER_ID_SOURCE, ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('ycpy', self::OPT_WAREHOUSES, ['type'=>'array']);
        register_setting('ycpy', self::OPT_SHOPS, ['type'=>'array']);
    }
    public static function register_endpoint(): void {
        add_rewrite_rule('^ycp/api/v1/(.*)?$', 'index.php?ycp_yandex=1&ycp_yandex_action=$matches[1]', 'top');
        add_rewrite_tag('%ycp_yandex%','([0-9]+)'); add_rewrite_tag('%ycp_yandex_action%','([^&]+)');
    }

    public static function handle_request(\WP $wp): void {
        $action=(string)($wp->query_vars['ycp_yandex_action'] ?? '');
        if (!$action && !preg_match('~^/ycp/api/v1/~', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '')) return;
        if (!$action) { $action = trim(str_replace('/ycp/api/v1', '', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/'); }

        $method=strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        $allowed=[
            'warehouses'=>'GET','shops'=>'GET','checkout/basket/check'=>'POST','checkout'=>'POST','checkout/placed'=>'POST','checkout/cancel'=>'POST','order/cancel'=>'POST'
        ];
        if (!isset($allowed[$action])) self::send_ycp_error('not_found','Unknown endpoint',404);
        if ($method !== $allowed[$action]) self::send_ycp_error('method_not_allowed','Method not allowed',405);

        self::authorize();
        $body=[];
        if ($method==='POST') $body=self::read_json();

        $start=microtime(true);
        switch($action){
            case 'warehouses': self::respond(200,['warehouses'=>self::get_warehouses()]);
            case 'shops': self::respond(200,['shops'=>self::get_shops()]);
            case 'checkout/basket/check': self::handle_basket_check($body);
            case 'checkout': self::handle_checkout($body);
            case 'checkout/placed': self::handle_checkout_placed($body);
            case 'checkout/cancel': self::handle_checkout_cancel($body);
            case 'order/cancel': self::handle_order_cancel($body);
        }
        self::log_entry($method,$action,200,'', (int)((microtime(true)-$start)*1000));
    }

    private static function authorize(): void {
        $token=(string)get_option(self::OPT_TOKEN,''); if(!$token) self::send_ycp_error('invalid_token','Token not configured',401);
        $h=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $h=sanitize_text_field(wp_unslash($h));
        if(!preg_match('/Bearer\s+(.+)/i',$h,$m) || !hash_equals($token,trim($m[1]))) self::send_ycp_error('invalid_token','Invalid token',401);
    }
    private static function read_json(): array {
        $raw=(string)file_get_contents('php://input');
        $data=json_decode($raw,true);
        if (json_last_error()!==JSON_ERROR_NONE || !is_array($data)) self::send_ycp_error('invalid_json','Invalid JSON body',400,['json_error'=>json_last_error_msg()]);
        return $data;
    }

    private static function handle_basket_check(array $body): void {
        if (empty($body['items']) || !is_array($body['items'])) self::send_ycp_error('validation_error','items is required',400);
        $out=['items'=>[]];
        foreach ($body['items'] as $item) {
            $offer=(string)($item['offer_id'] ?? $item['id'] ?? ''); $qty=max(1,(int)($item['quantity']??1));
            $pid=self::resolve_product_id($offer); $p=$pid?wc_get_product($pid):null;
            if(!$p || $p->get_status()!=='publish') { $out['items'][]=['offer_id'=>$offer,'status'=>'unavailable','available_quantity'=>0,'reason'=>'product_not_found']; continue; }
            if(!$p->is_in_stock()){ $available=0; }
            elseif($p->managing_stock()){ $available=max(0,(int)$p->get_stock_quantity()); }
            else { $available=PHP_INT_MAX; }
            $status=($available==0 || $available<$qty)?'unavailable':'available';
            $out['items'][]=['offer_id'=>$offer,'status'=>$status,'available_quantity'=>$available===PHP_INT_MAX?null:$available,'price'=>(float)$p->get_price(),'currency'=>get_woocommerce_currency()];
        }
        self::respond(200,$out);
    }

    private static function handle_checkout(array $body): void {
        $session_id=(string)($body['checkout_session_id'] ?? $body['session_id'] ?? '');
        if(!$session_id) self::send_ycp_error('validation_error','checkout_session_id is required',400);
        $existing=self::find_order_by_meta('_ycp_session_id',$session_id);
        if($existing) self::respond(200,['checkout_session_id'=>$session_id,'wc_order_id'=>(string)$existing->get_id(),'status'=>'active']);
        update_option('ycp_session_'.md5($session_id), ['status'=>'active','payload_hash'=>md5(wp_json_encode($body)),'updated_at'=>time()], false);
        self::respond(201,['checkout_session_id'=>$session_id,'status'=>'active']);
    }
    private static function handle_checkout_placed(array $body): void {
        $session_id=(string)($body['checkout_session_id'] ?? $body['session_id'] ?? '');
        $ycp_order_id=(string)($body['order_id'] ?? '');
        if(!$session_id || !$ycp_order_id) self::send_ycp_error('validation_error','checkout_session_id and order_id are required',400);
        $existing=self::find_order_by_meta('_ycp_order_id',$ycp_order_id);
        if($existing) self::respond(200,['order_id'=>$ycp_order_id,'wc_order_id'=>(string)$existing->get_id(),'status'=>'placed']);
        $order=wc_create_order(['status'=>'pending']);
        if (is_wp_error($order)) self::send_ycp_error('internal_error','Unable to create order',500);
        foreach(($body['items']??[]) as $item){ $p=wc_get_product(self::resolve_product_id((string)($item['offer_id']??$item['id']??''))); if($p){$order->add_product($p,max(1,(int)($item['quantity']??1)));}}
        $order->update_meta_data('_ycp_session_id',$session_id); $order->update_meta_data('_ycp_order_id',$ycp_order_id); $order->update_meta_data('_ycp_request_id',(string)($body['request_id']??''));
        $order->calculate_totals(); $order->save();
        self::respond(201,['order_id'=>$ycp_order_id,'wc_order_id'=>(string)$order->get_id(),'status'=>'placed']);
    }
    private static function handle_checkout_cancel(array $body): void {
        $session_id=(string)($body['checkout_session_id'] ?? $body['session_id'] ?? ''); if(!$session_id) self::send_ycp_error('validation_error','checkout_session_id is required',400);
        $order=self::find_order_by_meta('_ycp_session_id',$session_id);
        if($order && !in_array($order->get_status(),['completed','processing'],true) && $order->get_status()!=='cancelled') $order->update_status('cancelled','YCP checkout cancelled');
        update_option('ycp_session_'.md5($session_id), ['status'=>'cancelled','updated_at'=>time()], false);
        self::respond(200,['checkout_session_id'=>$session_id,'status'=>'cancelled']);
    }
    private static function handle_order_cancel(array $body): void {
        $id=(string)($body['order_id'] ?? ''); if(!$id) self::send_ycp_error('validation_error','order_id is required',400);
        $order=self::find_order_by_meta('_ycp_order_id',$id); if(!$order) self::send_ycp_error('order_not_found','Order not found',404);
        if($order->get_status()!=='cancelled') $order->update_status('cancelled','YCP order cancelled');
        self::respond(200,['order_id'=>$id,'status'=>'cancelled']);
    }

    private static function get_warehouses(): array {
        $v=get_option(self::OPT_WAREHOUSES,[]); return is_array($v)&&$v?$v:[[ 'warehouse_id'=>'main','title'=>get_bloginfo('name').' main warehouse','is_active'=>true ]];
    }
    private static function get_shops(): array {
        $v=get_option(self::OPT_SHOPS,[]); return is_array($v)&&$v?$v:[[ 'shop_id'=>'main','title'=>get_bloginfo('name'),'is_active'=>true ]];
    }
    private static function resolve_product_id(string $id): int { if($id==='')return 0; $src=get_option(self::OPT_OFFER_ID_SOURCE,'sku'); if($src==='sku'){ $by=wc_get_product_id_by_sku($id); if($by)return (int)$by; } if(ctype_digit($id)) return (int)$id; return 0; }
    private static function find_order_by_meta(string $key,string $value){ $orders=wc_get_orders(['limit'=>1,'status'=>'any','meta_query'=>[['key'=>$key,'value'=>$value]]]); return $orders[0]??null; }

    private static function send_ycp_error(string $code,string $message,int $http_status,array $details=[]): void { self::respond($http_status,['error'=>['code'=>$code,'message'=>$message,'details'=>$details]]); }
    private static function respond(int $code,array $data): void { if(!headers_sent()){ status_header($code); nocache_headers(); header('Content-Type: application/json; charset=utf-8'); } echo wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
    private static function log_entry(string $method,string $action,int $status,string $error_code='',int $duration_ms=0): void {
        if(!(bool)get_option(self::OPT_DEBUG,false)) return; $log=(array)get_option(self::OPT_LOG,[]); $log[]=['time'=>current_time('c'),'method'=>$method,'endpoint'=>$action,'status'=>$status,'duration_ms'=>$duration_ms,'error_code'=>$error_code]; update_option(self::OPT_LOG,array_slice($log,-100),false);
    }

    public static function render_page(): void { ?>
    <div class="wrap"><h1>YCP Yandex</h1><form method="post" action="options.php"><?php settings_fields('ycpy'); ?>
    <table class="form-table"><tr><th>Bearer token</th><td><input type="text" name="<?php echo esc_attr(self::OPT_TOKEN); ?>" value="<?php echo esc_attr((string)get_option(self::OPT_TOKEN,'')); ?>" class="regular-text"/></td></tr>
    <tr><th>Debug logs</th><td><input type="checkbox" name="<?php echo esc_attr(self::OPT_DEBUG); ?>" value="1" <?php checked(get_option(self::OPT_DEBUG), '1'); ?>/></td></tr>
    <tr><th>offer_id mapping</th><td><select name="<?php echo esc_attr(self::OPT_OFFER_ID_SOURCE); ?>"><option value="sku" <?php selected(get_option(self::OPT_OFFER_ID_SOURCE,'sku'),'sku'); ?>>SKU</option><option value="product_id" <?php selected(get_option(self::OPT_OFFER_ID_SOURCE,''),'product_id'); ?>>Product ID</option></select></td></tr>
    </table><?php submit_button(); ?></form></div><?php }
}
YCP_Yandex_Commerce_Woo::init();
