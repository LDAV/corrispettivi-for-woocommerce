<?php
/**
 * Plugin Name: Corrispettivi for WooCommerce
 * Plugin URI: https://ldav.it/plugin/corrispettivi-for-woocommerce/
 * Description: An aid for the compilation of the Register of Payments from WooCommerce sales.
 * Version: 0.8.3
 * Author: laboratorio d'Avanguardia
 * Author URI: https://ldav.it/
 * Text Domain: corrispettivi-for-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 10.5.2
 * License: GPLv3 or later
 * License URI: http://www.opensource.org/licenses/gpl-license.php
*/
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( !defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( !class_exists( 'Corrispettivi_for_WooCommerce' ) ) :
class Corrispettivi_for_WooCommerce {
	public static $plugin_url;
	public static $plugin_path;
	public static $plugin_basename;
	public $version = '0.8.3';
	protected static $instance = null;

	private $is_wc_active;
	private $is_wcpdf_IT_active;
	private $is_wpo_wcpdf_active;
	private $tot_parz_s;
	private $tax_based_on_shipping;
	private $date_format;
	private $wc_status = ["wc-processing", "wc-on-hold", "wc-completed", "wc-refunded"];

	public static function instance() {
		if ( is_null( self::$instance ) ) self::$instance = new self();
		return self::$instance;
	}

	public function __construct() {
		self::$plugin_basename = plugin_basename(__FILE__);
		self::$plugin_url = plugin_dir_url(self::$plugin_basename);
		self::$plugin_path = trailingslashit(dirname(__FILE__));
		$this->init_hooks();
	}
	
	public function init() {
		load_plugin_textdomain( 'corrispettivi-for-woocommerce', false, dirname( self::$plugin_basename ) . "/languages" );
	}

	public function init_hooks() {
		add_action( 'init', [ $this, 'init' ], 0 );
		$this->check_active_plugins();
		if (!$this->is_wc_active) {
			add_action( 'admin_notices', [ $this, 'check_wc' ] );
		} else {
			$this->tax_based_on_shipping = get_option( 'woocommerce_tax_based_on' ) == "shipping";
			$this->date_format = get_option( 'date_format' );
			add_action( 'admin_menu', [ $this, 'add_admin_menus' ], 20 );
			add_action( 'wp_ajax_corrispettivi_for_woocommerce_dismiss_notice', [ $this, 'dismiss_notice' ] );
			register_deactivation_hook(__FILE__, __CLASS__ . '::corrispettivi_for_woocommerce_uninstall');
			register_uninstall_hook(__FILE__, __CLASS__ . '::corrispettivi_for_woocommerce_uninstall');
			$s = get_option( 'corrispettivi_for_woocommerce_wc_status' );
			$this->wc_status = $this->get_selected_wc_statuses( $s );
			update_option("corrispettivi_for_woocommerce_wc_status", $this->wc_status);
			add_action( 'before_woocommerce_init', function() {
				if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
				}
			} );
		}
	}

	private function get_default_wc_statuses() {
		$statuses = apply_filters( 'corrispettivi_for_woocommerce_default_wc_status', ["wc-processing", "wc-on-hold", "wc-completed", "wc-refunded"] );
		if ( ! is_array( $statuses ) ) {
			$statuses = [];
		}
		return ! empty( $statuses ) ? $statuses : [ 'wc-completed' ];
	}

	private function get_selected_wc_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			$statuses = [];
		}

		$allowed_statuses = $this->get_default_wc_statuses();

		$selected = [];
		foreach ( $statuses as $status ) {
			if ( ! is_scalar( $status ) ) {
				continue;
			}

			$status = (string) $status;
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$selected[] = $status;
			}
		}

		$selected = array_values( array_unique( $selected ) );
		if ( ! in_array( 'wc-completed', $selected, true ) ) {
			$selected[] = 'wc-completed';
		}

		return array_values( array_unique( $selected ) );
	}

	private function sanitize_month( $month ) {
		$month = is_scalar( $month ) ? sanitize_text_field( (string) $month ) : '';
		return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ? $month : gmdate( 'Y-m' );
	}

	private function get_nonce_action() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? $host : '';
		return "corrispettivi_for_woocommerce_send_nonce" . $host;
	}

	private function format_tax_rate_label( $rate ) {
		$rate_value = (float) $rate;
		$rate_label = rtrim( rtrim( number_format( $rate_value, 4, '.', '' ), '0' ), '.' );
		return sprintf( '%s %s%%', __( 'Tax rate', 'corrispettivi-for-woocommerce' ), $rate_label );
	}

	private function get_available_months( $statuses ) {
		global $wpdb;

		if ( ! is_array( $statuses ) || empty( $statuses ) ) {
			return [];
		}

		$is_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && OrderUtil::custom_orders_table_usage_is_enabled();
		$cache_group = 'corrispettivi_for_woocommerce';
		$cache_key = 'months_' . md5(
			wp_json_encode(
					[
						'statuses' => array_values( $statuses ),
						'hpos'     => $is_hpos ? 1 : 0,
						'blog_id'  => get_current_blog_id(),
					]
			)
		);

		$cached = wp_cache_get( $cache_key, $cache_group );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$results = $is_hpos ?
			$wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT YEAR(date_created_gmt) as anno, MONTH(date_created_gmt) as mese FROM {$wpdb->prefix}wc_orders WHERE status IN ($status_placeholders) ORDER BY date_created_gmt DESC",
				$statuses
			), ARRAY_A ) :
			$wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT YEAR(post_date) as anno, MONTH(post_date) as mese FROM {$wpdb->prefix}posts WHERE post_type='shop_order' and post_status IN ($status_placeholders) ORDER BY post_date DESC",
				$statuses
			), ARRAY_A );

		if ( ! is_array( $results ) ) {
			$results = [];
		}

		wp_cache_set( $cache_key, $results, $cache_group, 300 );
		return $results;
	}
	
	static function corrispettivi_for_woocommerce_uninstall(){
		delete_option("corrispettivi_for_woocommerce_dismiss_notice");
	}
	
	public function dismiss_notice(){
		$nonce = isset( $_REQUEST["_wpnonce"] ) ? sanitize_text_field( wp_unslash( $_REQUEST["_wpnonce"] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $this->get_nonce_action() ) ) {
			throw new Exception( esc_html__( 'Invalid nonce verification', 'corrispettivi-for-woocommerce' ) );
		} else {
			update_option("corrispettivi_for_woocommerce_dismiss_notice", 1);
		}
		wp_die();
	}
	
	public function check_active_plugins() {
		$active_plugins = get_site_option( 'active_plugins', []);
		$plugins = get_site_option( 'active_sitewide_plugins', []);
		$this->is_wc_active = (in_array('woocommerce/woocommerce.php', $active_plugins) || isset($plugins['woocommerce/woocommerce.php']));
		$this->is_wcpdf_IT_active = (in_array('woocommerce-italian-add-on/woocommerce-italian-add-on.php', $active_plugins) || isset($plugins['woocommerce-italian-add-on/woocommerce-italian-add-on.php']));
		$this->is_wpo_wcpdf_active = (in_array('woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php', $active_plugins) || isset($plugins['woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php']));
	}

		public function check_wc( $fields ) {
			$message = sprintf(
				/* translators: 1: opening link tag to WooCommerce plugin page, 2: closing link tag. */
				__( 'Corrispettivi for WooCommerce requires %1$sWooCommerce%2$s 3.0+ to be installed and activated!' , 'corrispettivi-for-woocommerce' ),
				'<a href="' . esc_url( 'https://wordpress.org/plugins/woocommerce/' ) . '">',
				'</a>'
			);
		printf( '<div class="error is-dismissible"><p>%s</p></div>', wp_kses_post( $message ) );
	}	
	
		public function check_wcpdf_IT( $fields ) {
			$message = sprintf(
				/* translators: 1: opening link tag for WooCommerce PDF Invoices Italian Add-on, 2: closing link tag, 3: opening link tag for WooCommerce Italian Add-on Plus, 4: closing link tag. */
				__( 'Corrispettivi for WooCommerce requires <strong>%1$sWooCommerce PDF Invoices Italian Add-on%2$s</strong> or <strong>%3$sWooCommerce Italian Add-on Plus%4$s</strong> to be installed and activated!' , 'corrispettivi-for-woocommerce' ),
				'<a href="' . esc_url( 'https://it.wordpress.org/plugins/woocommerce-pdf-invoices-italian-add-on/' ) . '">',
				'</a>',
			'<a href="' . esc_url( 'https://ldav.it/shop/plugin/woocommerce-italian-add-on/' ) . '">',
			'</a>'
		);
		printf( '<div class="error is-dismissible"><p>%s</p></div>', wp_kses_post( $message ) );
	}

	public function add_admin_menus() {
		add_submenu_page(
			'woocommerce',
			__( 'Payments', 'corrispettivi-for-woocommerce' ),
			__( 'Payments', 'corrispettivi-for-woocommerce' ),
			'manage_woocommerce',
			'corrispettivi_for_woocommerce_invoice_list',
			[ $this, 'invoice_list' ]
		);
	}
	
	private function add_js_and_fields( $script_data = [] ){
		wp_register_script( 'xlsx.core', self::$plugin_url.'js/xlsx.core.min.js', [], $this->version );
		wp_enqueue_script( 'xlsx.core' );
		wp_register_script(
			'corrispettivi-for-woocommerce-admin',
			self::$plugin_url . 'js/corrispettivi-for-woocommerce-admin.js',
			[ 'jquery', 'xlsx.core' ],
			$this->version,
			true
		);
		wp_enqueue_script( 'corrispettivi-for-woocommerce-admin' );
		wp_localize_script( 'corrispettivi-for-woocommerce-admin', 'corrispettiviForWooCommerceData', is_array( $script_data ) ? $script_data : [] );
	}

	public function invoice_list(){
		$has_select_request = isset( $_REQUEST['corrispettivi_for_woocommerce_select'] );
		$show_0_days = isset( $_REQUEST["corrispettivi_for_woocommerce_show_0_days"] );
		$select = $this->sanitize_month( $has_select_request ? wp_unslash( $_REQUEST['corrispettivi_for_woocommerce_select'] ) : gmdate( 'Y-m' ) );
		$nonce = wp_create_nonce( $this->get_nonce_action() );
		$ajax_url = admin_url('admin-ajax.php', 'relative');
?>
<div class="wrap woocommerce corrispettivi_for_woocommerce">
<h2><?php esc_html_e("Corrispettivi for WooCommerce", 'corrispettivi-for-woocommerce')?> <sup><?php echo esc_html($this->version) ?></sup></h2>
<?php
		if (!$this->is_wcpdf_IT_active && !$this->is_wpo_wcpdf_active) {
			$op = get_option("corrispettivi_for_woocommerce_dismiss_notice");
			if(!$op || $op != "1") {
?>
<div class="notice notice-warning is-dismissible ">
<p>
<?php
				$message = sprintf(
					/* translators: 1: opening link tag for WooCommerce PDF Invoices Italian Add-on, 2: closing link tag, 3: opening link tag for WooCommerce Italian Add-on Plus, 4: closing link tag. */
					__( 'For the invoices recognition, Corrispettivi for WooCommerce requires <strong>%1$sWooCommerce PDF Invoices Italian Add-on%2$s</strong> or <strong>%3$sWooCommerce Italian Add-on Plus%4$s</strong> to be installed and activated!' , 'corrispettivi-for-woocommerce' ),
					'<a href="' . esc_url( 'https://it.wordpress.org/plugins/woocommerce-pdf-invoices-italian-add-on/' ) . '">',
					'</a>',
				'<a href="' . esc_url( 'https://ldav.it/shop/plugin/woocommerce-italian-add-on/' ) . '">',
				'</a>'
			);
				echo wp_kses_post( $message );
?>
</p>
</div>
<?php
			}
		}
?>
<h2><?php esc_html_e("List of Payments", 'corrispettivi-for-woocommerce') ?></h2>
<form method="get" action="" id="corrispettivi_for_woocommerce_invoice_list">
<input type="hidden" name="page" value="corrispettivi_for_woocommerce_invoice_list">
	<p>
<?php
		if ( isset( $_REQUEST["corrispettivi_for_woocommerce_wc_status"] ) ) {
			$request_statuses = wp_unslash( (array) $_REQUEST["corrispettivi_for_woocommerce_wc_status"] );
			$this->wc_status = $this->get_selected_wc_statuses( $request_statuses );
			update_option("corrispettivi_for_woocommerce_wc_status", $this->wc_status);
		}

		$results = $this->get_available_months( $this->wc_status );
		if ( ! $has_select_request && $results ) {
			$select = sprintf("%d-%02d", $results[0]["anno"], $results[0]["mese"]);
		}
?>
	<select id="corrispettivi_for_woocommerce_select" name="corrispettivi_for_woocommerce_select">
<?php
		foreach($results as $rs){
			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( sprintf( '%d-%02d', (int) $rs["anno"], (int) $rs["mese"] ) ),
				esc_html( strtolower( wp_date( "F Y", strtotime($rs["anno"] . "-" . $rs["mese"] . "-01") ) ) )
			);
		}
?>
	</select>
	<input type="submit" class="button button-primary" value="<?php esc_attr_e( "Filter payments", 'corrispettivi-for-woocommerce' ) ?>">
	<label><input type="checkbox" id="corrispettivi_for_woocommerce_show_0_days" name="corrispettivi_for_woocommerce_show_0_days"><?php echo esc_html_e( "Show days without payments", 'corrispettivi-for-woocommerce' ) ?></label>
<label><strong style="margin-left: 1rem"><?php esc_html_e( "Order status", 'corrispettivi-for-woocommerce' ) ?>:</strong></label>
	<input type="hidden" name="corrispettivi_for_woocommerce_wc_status[]" value="wc-completed">
	<?php
		$statuses = wc_get_order_statuses();
		$allowed = $this->get_default_wc_statuses();
		foreach($statuses as $k => $v){
			if(!in_array($k, $allowed, true)) continue;
			$chk = in_array($k, $this->wc_status, true) ? " checked" : "";
			$chk .= ($k == "wc-completed" ? " disabled" : "");
			printf('<label><input type="checkbox" name="corrispettivi_for_woocommerce_wc_status[]" value="%1$s"%3$s>%2$s</label> ', esc_attr( $k ), esc_html( $v ), esc_html( $chk ) ) ;
		}
?>
	</p>
</form>
<?php
		$from = strtotime(sprintf("%s-01", $select));
		$to = strtotime( date("c", strtotime(sprintf("%s-01 +1 month", $select)) ));

		$args = [
			'date_created' => date("Y-m-d", $from) . "..." . date("Y-m-t", $from),
			'type' => 'shop_order',
			'status' => $this->wc_status,
			'limit' => -1,
		];
		$res = wc_get_orders($args);
		$rows = [];
		foreach($res as $order) {
			$order_id = $order->get_id();
			$date_created = $order->get_date_created();
			$rs = [
				"order_id" => $order_id,
				"num" => "",
				"date" => $date_created->date("Y-m-d"),
				"type" => "",
				"data" => [],
				"tot_parz_s" => [],
			];
			$data = $this->get_order_data($order);
			if($data){
				$rs["data"] = $data["data"];
				$rs["tot_parz_s"] = $data["tot_parz_s"];
				if(!empty($data["num"])) {
					$rs["type"] = "invoice";
					$rs["num"] = $data["num"];
				}
			}
			$rows[] = $rs;
		}
		
		$data = [];
		$tax_rates = [0 => 0, -1=> 0];
		foreach($rows as $rs){
			$dd = $rs["date"];
			if(empty($data[$dd])) $data[$dd] = ["date" => $dd];
			if(empty($data[$dd]["total"])) $data[$dd]["total"] = 0;
			foreach($rs["data"] as $s){
				if(!empty($s["num"]) && $s["type"] == "invoice") {
					if(empty($data[$dd]["min"]) || 
						 (!empty($data[$dd]["min"]) && $s["num"] < $data[$dd]["min"]) ){
						$data[$dd]["min"] = $s["num"];
					}
					if(empty($data[$dd]["max"]) || 
						 (!empty($data[$dd]["max"]) && $s["num"] > $data[$dd]["max"]) ){
						$data[$dd]["max"] = $s["num"];
					}
				}
			}
			foreach($rs["tot_parz_s"] as $tax_rate => $v){
				if(empty($data[$dd]["tax_rates"][$tax_rate])) $data[$dd]["tax_rates"][$tax_rate] = ["tax" => 0, "total" => 0];
				$tax_rates[$tax_rate] = 0;
				$data[$dd]["tax_rates"][$tax_rate]["tax"] += $v["tax"];
				$data[$dd]["tax_rates"][$tax_rate]["total"] += $v["total"];
				$data[$dd]["total"] += $v["total"];
			}
		}
		krsort($tax_rates, SORT_NUMERIC);

		if(!empty($data) && !empty($_REQUEST["corrispettivi_for_woocommerce_show_0_days"])){
			$rs_org = reset($data);
			foreach($rs_org as $k => $s){
				$rs_org[$k] = 0;
			}
			$rs_org["min"] = $rs_org["max"] = "";
			
			for($k = $from; $k < $to; $k += 86400){
				$dd = date("Y-m-d", $k);
				if(!isset($data[$dd])){
					$rs = $rs_org;
					$rs["date"] = $dd;
					$data[$dd] = $rs;
				}
			}
		}

		$dates = array_column($data, 'date');
		array_multisort($dates, SORT_ASC, $data);
			$export_columns = [
				[
					'key'   => 'date',
					'label' => __( 'Date', 'corrispettivi-for-woocommerce' ),
					'type'  => 'date',
				],
				[
					'key'   => 'total',
					'label' => __( 'Total daily payments', 'corrispettivi-for-woocommerce' ),
					'type'  => 'number',
				],
			];
			foreach ( $tax_rates as $k => $v ) {
				if ( $k > 0 ) {
					$export_columns[] = [
						'key'   => 'tax_rate_' . $k,
						'label' => $this->format_tax_rate_label( $k ),
						'type'  => 'number',
					];
				}
			}
			$export_columns[] = [
				'key'   => 'tax_rate_0',
				'label' => __( 'Non-taxable or exempt transactions', 'corrispettivi-for-woocommerce' ),
				'type'  => 'number',
			];
			$export_columns[] = [
				'key'   => 'tax_rate_-1',
				'label' => __( 'Transactions not subject to VAT registration', 'corrispettivi-for-woocommerce' ),
				'type'  => 'number',
			];
			$export_columns[] = [
				'key'   => 'invoice_number_from',
				'label' => __( 'Invoice from No.', 'corrispettivi-for-woocommerce' ),
				'type'  => 'string',
			];
			$export_columns[] = [
				'key'   => 'invoice_number_to',
				'label' => __( 'Invoice to No.', 'corrispettivi-for-woocommerce' ),
				'type'  => 'string',
			];
			$export_rows = [];
	?>
	<table id="corrispettivi_for_woocommerce_table" class="wp-list-table widefat fixed striped posts">
<thead>
	<tr>
		<th scope="col" id="day" class="manage-column tableexport-string" style="text-align:right; vertical-align: bottom"><span><?php esc_html_e("Date", 'corrispettivi-for-woocommerce') ?></span></th>
		<th scope="col" id="total" class="manage-column" style="text-align:right; vertical-align: bottom"><span><?php esc_html_e("Total daily payments", 'corrispettivi-for-woocommerce') ?></span></th>
<?php
		foreach($tax_rates as $k => $v){
			if($k > 0){
?>
		<th scope="col" id="tax_rate_<?php echo esc_attr($k)?>" class="manage-column" style="text-align:right; vertical-align: bottom"><span><?php echo esc_html( $this->format_tax_rate_label( $k ) ); ?></span></th>
<?php
			}
		}
?>
		<th scope="col" id="tax_rate_0" class="manage-column" style="text-align:right; vertical-align: bottom"><span><?php esc_html_e("Non-taxable or exempt transactions", 'corrispettivi-for-woocommerce') ?></span></th>
		<th scope="col" id="tax_rate_-1" class="manage-column" style="text-align:right; vertical-align: bottom"><span><?php esc_html_e("Transactions not subject to VAT registration", 'corrispettivi-for-woocommerce') ?></span></th>
		<th scope="col" id="invoice_number_from" class="manage-column" style="vertical-align: bottom"><span><?php esc_html_e("Invoice from No.", 'corrispettivi-for-woocommerce') ?></span></th>
		<th scope="col" id="invoice_number_to" class="manage-column" style="vertical-align: bottom"><span><?php esc_html_e("Invoice to No.", 'corrispettivi-for-woocommerce') ?></span></th>
	</tr>
</thead>
<tbody>
<?php
		$tot = $tax_rates;
		$tot["tot"] = $tot[-1] = $tot[0] = 0;
			foreach($data as $dd => $rs) {
				$total = $rs["total"];
				$tot["tot"] += $total;
				$export_row = [
					'date' => $dd,
					'total' => (float) $total,
				];
	?>
<tr>
<td style="text-align:right" class="tableexport-string"><?php echo esc_attr(wp_date($this->date_format, strtotime($dd))) ?></td>
<td style="text-align:right"><?php echo esc_attr(number_format_i18n($total, 2)) ?></td>
<?php
				foreach($tax_rates as $k => $v){
					$val = isset($rs["tax_rates"][$k]) ? $rs["tax_rates"][$k]["total"] : 0;
					$export_row['tax_rate_' . $k] = (float) $val;
	?>
<td style="text-align:right"><?php echo esc_attr(number_format_i18n($val, 2)) ?></td>
<?php
				$tot[$k] += $val;
			}
				$val = isset($rs["min"]) ? $rs["min"] : "";
				$export_row['invoice_number_from'] = (string) $val;
	?>
<td class="tableexport-string"><?php echo esc_attr($val) ?></td>
<?php
				$val = isset($rs["max"]) ? $rs["max"] : "";
				$export_row['invoice_number_to'] = (string) $val;
				$export_rows[] = $export_row;
	?>
<td class="tableexport-string"><?php echo esc_attr($val) ?></td>
</tr>
<?php
		}
?>
	</tbody>
	<tfoot>
		<tr><td></td>
		<td style="text-align:right"><?php echo esc_attr(number_format_i18n($tot["tot"], 2)) ?></td>
<?php
			foreach($tax_rates as $k => $v){
	?>
			<td style="text-align:right"><?php echo esc_attr(number_format_i18n($tot[$k], 2)) ?></td>
<?php
			}
?><td></td><td></td>
			</tr>
		</tfoot>
		</table>
	<div class="tableexport-caption">
		<button type="button" class="button-default" id="corrispettivi_for_woocommerce_export_xlsx"><?php esc_html_e( 'Export to Excel', 'corrispettivi-for-woocommerce' ); ?></button>
		<button type="button" class="button-default" id="corrispettivi_for_woocommerce_export_csv"><?php esc_html_e( 'Export to CSV', 'corrispettivi-for-woocommerce' ); ?></button>
	</div>

	</div>
<?php
			$export_total_row = [
				'date' => '',
				'total' => (float) $tot['tot'],
				'invoice_number_from' => '',
				'invoice_number_to' => '',
			];
			foreach ( $tax_rates as $k => $v ) {
				$export_total_row['tax_rate_' . $k] = (float) $tot[ $k ];
			}
			$export_rows[] = $export_total_row;
			$export_payload = [
				'columns'  => $export_columns,
				'rows'     => $export_rows,
				'filename' => sanitize_file_name( 'corrispettivi-' . $select ),
				'sheet'    => 'Corrispettivi',
			];
			$this->add_js_and_fields(
				[
					'ajax_url'        => esc_url_raw( $ajax_url ),
					'dismiss_nonce'   => $nonce,
					'selected_month'  => $select,
					'show_zero_days'  => $show_0_days,
					'export_payload'  => $export_payload,
				]
			);
?>
<style>
.tableexport-caption{text-align: left; margin-top: 20px}
.tableexport-caption .button-default{background: #2271b1; border-color: #2271b1; color: #fff; text-decoration: none; text-shadow: none; display: inline-block; font-size: 13px; line-height: 2.15384615; min-height: 30px; margin: 0 10px 0 0; padding: 0 10px; cursor: pointer; border-width: 1px; border-style: solid; -webkit-appearance: none; border-radius: 3px; white-space: nowrap; box-sizing: border-box;}
</style>
<?php
		}

		public function get_order_data($order){
			$order_data = [];
			$this->tot_parz_s = [];
			$res = $this->get_document_data($order);
			if($res) $order_data[] = $res;
			$order_refunds = $order->get_refunds();
			foreach($order_refunds as $refund) {
				$res = $this->get_document_data($refund, $order);
				if($res) $order_data[] = $res;
			}
			$res = ["data" => $order_data, "tot_parz_s" => $this->tot_parz_s];
			return $res;
		}

		public function get_document_data( $order, $parent = null ) {
			$document_type = $parent ? "credit_note" : "invoice";
			if ( $document_type == "invoice" ) {
				$numbering_enabled = class_exists( "WooCommerce_Italian_add_on_plus" ) && function_exists( "WCPDF_IT" ) && WCPDF_IT()->settings->numerazione_settings->invoice_numbering_enabled;
				$wcpdf_document_type = "invoice";
				$wcpdf_exists = function_exists( "wcpdf_get_document" );
			} else {
				$numbering_enabled = class_exists( "WooCommerce_Italian_add_on_plus" ) && function_exists( "WCPDF_IT" ) && WCPDF_IT()->settings->numerazione_settings->credit_note_numbering_enabled;
				$wcpdf_document_type = "credit-note";
				$wcpdf_exists = class_exists( "WooCommerce_PDF_IPS_Pro" );
			}
			$wcpdf_document = ( $wcpdf_exists && function_exists( "wcpdf_get_document" ) ) ? wcpdf_get_document( $wcpdf_document_type, $order ) : "";

			$number_formatted = "";
			$date_formatted = "";
			$date = "";

			$parent = $parent ? $parent : $order;

			if ( !$numbering_enabled && $wcpdf_exists && $wcpdf_document ) { //WooCommerce PDF Invoice & Packing Slips
				if ( $number = $wcpdf_document->get_number( $wcpdf_document_type ) ) {
					$number_formatted = isset( $number->formatted_number ) ? $number->formatted_number : "";
				}
				$date = $wcpdf_document->get_date( $wcpdf_document_type );
				if ( $date instanceof DateTime ) {
					$date = $date->format( "Y-m-d" );
				} elseif ( get_date_from_gmt( $date, "Y-m-d" ) ) {
					$date = get_date_from_gmt( $date, "Y-m-d" );
				} else {
					$date = "";
				}
			} else {
				$document_data = $order->get_meta( '_wcpdf_IT_document_data', true );
				if ( is_array( $document_data ) && !empty( $document_data ) ) {
					$number_formatted = isset( $document_data["number_formatted"] ) ? $document_data["number_formatted"] : "";
					$date = isset( $document_data["date"] ) ? $document_data["date"] : "";
				} else {
					$number_formatted = $parent->get_meta( 'woo_pdf_' . $document_type . '_id', true ); //
					if ( !empty( $number_formatted ) ) {
						$date = $parent->get_meta( 'woo_pdf_' . $document_type . '_date', true );
					}
				}
			}

			if ( $document_type == "credit_note" ) {
				$items = $order->get_items( 'line_item' );
				if ( !$items ) $items = [];
				$fee = $order->get_items( 'fee' );
				if ( $fee ) $items = array_merge( $items, $fee );
				$shipping = $order->get_items( 'shipping' );
				if ( $shipping ) $items = array_merge( $items, $shipping );
				if ( empty( $items ) && ( abs( $order->get_total() ) == $parent->get_total() ) ) {
					$items = $parent->get_items( [ 'line_item', 'fee', 'shipping' ] );
				}
				$country = ( $this->tax_based_on_shipping && !empty( $parent->get_shipping_last_name() ) ) ? $parent->get_shipping_country() : $parent->get_billing_country();
			} else {
				$items = $order->get_items( [ 'line_item', 'fee', 'shipping' ] );
				$country = ( $this->tax_based_on_shipping && !empty( $parent->get_shipping_last_name() ) ) ? $order->get_shipping_country() : $order->get_billing_country();
			}

			if ( $items ) {
				foreach ( $items as $item ) {
					if ( $item->get_type() == "fee" && $item->get_name() == __( 'Withholding tax', 'woocommerce-italian-add-on-plus' ) ) {
						continue;
					}
					if ( $item->get_type() == "fee" && $item->get_name() == "Imposta di bollo" ) {
						$impostabollo = $item->get_total();
						if ( !isset( $this->tot_parz_s[ -1 ] ) ) $this->tot_parz_s[ -1 ] = [ "tax" => 0, "total" => 0 ];
						$this->tot_parz_s[ -1 ]["tax"] += 0;
						$this->tot_parz_s[ -1 ]["total"] += $impostabollo;
						continue;
					}
					$line_total = (float) $item->get_total();
					$tax_total = (float) $item->get_total_tax();
					$tax_rate = 0.0;
					$tax_class = "";
					if ( wc_tax_enabled() ) {
						$tax_class = $item->get_tax_class();
						$item_taxes = $item->get_taxes();
						$taxes = ( is_array( $item_taxes ) && isset( $item_taxes["total"] ) && is_array( $item_taxes["total"] ) ) ? $item_taxes["total"] : [];
						$taxes = array_filter(
							$taxes,
							function ( $v ) {
								return !is_null( $v ) && $v !== '';
							}
						);
						$rate_values = [];
						foreach ( $taxes as $tax_id => $tax_amount_raw ) {
							$tax_amount = (float) $tax_amount_raw;
							if ( $tax_amount == 0.0 && $tax_total != 0.0 ) {
								continue;
							}
							$rate_value = (float) ( $tax_id ? WC_Tax::get_rate_percent_value( $tax_id ) : 0 );
							if ( $rate_value > 0 ) {
								$rate_values[] = $rate_value;
							}
						}
						if ( count( $rate_values ) > 1 ) {
							rsort( $rate_values, SORT_NUMERIC );
							$tax_rate = (float) array_shift( $rate_values );
							foreach ( $rate_values as $extra_rate ) {
								$tax_rate *= ( 1 + ( (float) $extra_rate / 100 ) );
							}
						} elseif ( count( $rate_values ) === 1 ) {
							$tax_rate = (float) $rate_values[0];
						}
					}
					if ( $tax_rate == 0.0 ) {
						if ( $line_total != 0.0 && $tax_total != 0.0 ) {
							$tax_rate = round( $tax_total / $line_total * 100, 4 );
						} elseif ( wc_tax_enabled() ) {
							$calculate_tax_for = [
								'country' => $country,
								'tax_class' => $tax_class == "inherit" ? "" : $tax_class,
							];
							$tax_rates = WC_Tax::find_rates( $calculate_tax_for );
							$tax_rate_data = is_array( $tax_rates ) ? reset( $tax_rates ) : false;
							$tax_rate = (float) ( is_array( $tax_rate_data ) && isset( $tax_rate_data["rate"] ) ? $tax_rate_data["rate"] : 0 );
						}
					}
					$tax_key = ( (float) $tax_rate === 0.0 ) ? 0 : number_format( (float) $tax_rate, 4, '.', '' );
					if ( !isset( $this->tot_parz_s[ $tax_key ] ) ) $this->tot_parz_s[ $tax_key ] = [ "tax" => 0, "total" => 0 ];
					$this->tot_parz_s[ $tax_key ]["tax"] += $tax_total;
					$this->tot_parz_s[ $tax_key ]["total"] += ( $line_total + $tax_total );
				}
			}

			$res = [];
			if ( $number_formatted ) {
				$date_formatted = empty( $date ) ? "" : date( "Y-m-d", strtotime( $date ) );
				$res = [ "num" => $number_formatted, "data" => $date_formatted, "type" => $document_type ];
			}
			return ( $res );
		}

	}
endif;

$Corrispettivi_for_WooCommerce = new Corrispettivi_for_WooCommerce();

?>
