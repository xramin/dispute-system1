<?php
/**
 * Dispute Settings – submenu + options + runtime hooks
 * Depends on WooCommerce and your constants in pl-disputes-admin.php
 *
 * @version 1.0.0
 */

if (!defined('ABSPATH')) exit;

if (!defined('PL_TICKET_FORM_ID')) {
  // Expect the main plugin to define these. Bail if not present.
  return;
}

/** ----------------------------------------------------------------
 *  Option keys
 * --------------------------------------------------------------- */
const PL_OPT_ALLOWED_STATUSES = 'pl_dispute_allowed_statuses';
const PL_OPT_AUTORENDER       = 'pl_dispute_autorender_on_order';



/** ----------------------------------------------------------------
 *  Register settings
 * --------------------------------------------------------------- */
add_action('admin_init', function () {

  // Register both options
  register_setting('pl_dispute_settings_group', PL_OPT_ALLOWED_STATUSES, [
    'type'              => 'array',
    'sanitize_callback' => function ($input) {
      // Keep only valid wc statuses
      $valid = array_keys( wc_get_order_statuses() );
      $input = is_array($input) ? array_values(array_intersect($input, $valid)) : [];
      return $input;
    },
    'default' => array_keys( wc_get_order_statuses() ), // default: all allowed
  ]);

  register_setting('pl_dispute_settings_group', PL_OPT_AUTORENDER, [
    'type'              => 'boolean',
    'sanitize_callback' => fn($v) => (int) !empty($v),
    'default'           => 0,
  ]);

  // Section
  add_settings_section(
    'pl_dispute_settings_section',
    __('General', 'pl-disputes'),
    function () { echo '<p>'.esc_html__('Configure who can open disputes and where the form is shown.', 'pl-disputes').'</p>'; },
    'pl-dispute-settings'
  );

  // Field: Allowed statuses
  add_settings_field(
    'pl_field_allowed_statuses',
    __('Allowed statuses for opening a dispute', 'pl-disputes'),
    'pl_field_allowed_statuses_cb',
    'pl-dispute-settings',
    'pl_dispute_settings_section'
  );

  // Field: Auto render
  add_settings_field(
    'pl_field_autorender',
    __('Auto-render dispute form on the order page', 'pl-disputes'),
    'pl_field_autorender_cb',
    'pl-dispute-settings',
    'pl_dispute_settings_section'
  );
});

/** Render: Allowed statuses checkboxes */
function pl_field_allowed_statuses_cb() {
  $all   = wc_get_order_statuses();                // ['wc-pending' => 'Pending payment', ...]
  $value = get_option(PL_OPT_ALLOWED_STATUSES, array_keys($all));
  $value = is_array($value) ? $value : [];

  echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;max-width:740px">';
  foreach ($all as $key => $label) {
    printf(
      '<label style="display:flex;gap:8px;align-items:center;padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;">
        <input type="checkbox" name="%1$s[]" value="%2$s" %3$s />
        <span>%4$s <code style="opacity:.6">%2$s</code></span>
      </label>',
      esc_attr(PL_OPT_ALLOWED_STATUSES),
      esc_attr($key),
      checked(in_array($key, $value, true), true, false),
      esc_html($label)
    );
  }
  echo '</div>';
  echo '<p class="description">'.esc_html__('Users will only be allowed to open a dispute if the order status matches one of the selected statuses.', 'pl-disputes').'</p>';
}

/** Render: Auto-render checkbox */
function pl_field_autorender_cb() {
  $value = (int) get_option(PL_OPT_AUTORENDER, 0);
  printf(
    '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
    esc_attr(PL_OPT_AUTORENDER),
    checked(1, $value, false),
    esc_html__('Show the dispute creation form automatically on the single order page (My Account → View Order).', 'pl-disputes')
  );
}

/** Settings page callback */
function pl_dispute_settings_page_cb() {
  ?>
  <div class="wrap">
    <h1><?php esc_html_e('Dispute System Settings', 'pl-disputes'); ?></h1>
    <form method="post" action="options.php">
      <?php
      settings_fields('pl_dispute_settings_group');
      do_settings_sections('pl-dispute-settings');
      submit_button(__('Save changes', 'pl-disputes'));
      ?>
    </form>
  </div>
  <?php
}

/** ----------------------------------------------------------------
 *  Runtime helper: check if an order status is allowed
 * --------------------------------------------------------------- */
function pl_dispute_is_order_status_allowed($order_id) : bool {
  if (!$order_id) return false;
  if (!function_exists('wc_get_order')) return true; // be permissive if WC unavailable

  $order = wc_get_order($order_id);
  if (!$order) return false;

  $allowed = get_option(PL_OPT_ALLOWED_STATUSES);
  if (!is_array($allowed) || empty($allowed)) {
    $allowed = array_keys( wc_get_order_statuses() ); // fallback: all allowed
  }

  // $order->get_status() returns e.g. 'completed' (without 'wc-')
  $current_key = 'wc-' . $order->get_status();

  return in_array($current_key, $allowed, true);
}

/** ----------------------------------------------------------------
 *  Autorender on the single order page (My Account → View Order)
 *  Hook fires on the order details template.
 * --------------------------------------------------------------- */
add_action('woocommerce_order_details_after_order_table', function ($order) {
  $enabled = (int) get_option(PL_OPT_AUTORENDER, 0);
  if (!$enabled) return;

  if (!is_a($order, \WC_Order::class)) return;

  $order_id = $order->get_id();

  // Respect your existing guards:
  // - Only render if current user is a party to the order (your function)
  // - Only render if the order status is allowed (new rule)
  $is_party  = function_exists('pl_dispute_current_user_is_party_for_order') ? pl_dispute_current_user_is_party_for_order() : true;
  $st_ok     = pl_dispute_is_order_status_allowed($order_id);

  if (!$is_party || !$st_ok) {
    // Optional: show a small notice instead of the form.
    if (!$st_ok) {
      $allowed = get_option(PL_OPT_ALLOWED_STATUSES, array_keys(wc_get_order_statuses()));
      $labels  = array_intersect_key( wc_get_order_statuses(), array_flip($allowed) );
      echo '<div class="woocommerce-info" style="margin-top:24px">';
      echo esc_html__('Dispute creation is unavailable for this order status.', 'pl-disputes') . ' ';
      echo esc_html__('Allowed statuses:', 'pl-disputes') . ' ';
      echo esc_html( implode(', ', array_values($labels)) );
      echo '</div>';
    }
    return;
  }

  // Make sure Gravity Forms can get the current order id (for dynamic population if needed)
  add_filter('gform_field_value_order_id', function ($val) use ($order_id) { return (string) $order_id; });

  echo '<div class="pl-dispute-form-wrap" style="margin-top:24px">';
  echo '<h3 style="margin-bottom:12px">'.esc_html__('Open a dispute', 'pl-disputes').'</h3>';
  if (function_exists('gravity_form')) {
    // Render your ticket form (no title/description). Ajax on, to feel native.
    gravity_form(PL_TICKET_FORM_ID, false, false, false, null, true);
  } else {
    echo '<p>'.esc_html__('Gravity Forms is not active.', 'pl-disputes').'</p>';
  }
  echo '</div>';

}, 20);
