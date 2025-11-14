<?php
/**
* Plugin Name: Dispute Resolution Center (Guards + Autofill)
* Description: Guards + Autofill for Dispute Ticketing system using Gravity Forms + GravityView.
* Author: BalootWP
* Author URI: https://balootwp.com/
* Version: 1.0.0
* Requires Gravity Forms + GravityView + WooCommerce + ACF 
* License: Proprietary (single-domain: peerloot.com)
*/

if (!defined('ABSPATH')) exit;




// other 

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pl-disputes-admin.php';
require_once __DIR__ . '/pl-disputes-settings.php';


/* =========================
 * HELPERS
 * ========================= */

// Function: pl_gf_post — Read a GF input value by field id from POST.
if (!function_exists('pl_gf_post')) {
  function pl_gf_post($fid) { return rgpost('input_' . $fid); }
}

// Function: pl_user_id — Return current WP user ID (0 if guest).
if (!function_exists('pl_user_id')) {
  function pl_user_id() { return get_current_user_id(); }
}

// Function: pl_req_int — Read an integer from GET/POST with priority control.
function pl_req_int(string $key, string $prefer = 'any'): int {
  $get  = isset($_GET[$key])  ? absint($_GET[$key])  : 0;
  $post = isset($_POST[$key]) ? absint($_POST[$key]) : 0;
  switch ($prefer) {
    case 'get':  return $get ?: $post;
    case 'post': return $post ?: $get;
    default:     return $get ?: $post;
  }
}

// Function: pl_req_str — Read a sanitized string from GET/POST with priority control.
function pl_req_str(string $key, string $prefer = 'any'): string {
  $get  = isset($_GET[$key])  ? sanitize_text_field(wp_unslash($_GET[$key]))  : '';
  $post = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
  switch ($prefer) {
    case 'get':  return $get ?: $post;
    case 'post': return $post ?: $get;
    default:     return $get ?: $post;
  }
}
// Function: pl_get_current_order_id — Resolve order_id from GET/POST or Woo endpoint once and cache.
function pl_get_current_order_id(string $prefer = 'any'): int {
  static $cached = null;
  if ($cached !== null) return $cached;
  $from_param = pl_req_int('order_id', $prefer);
  if ($from_param) return $cached = $from_param;
  $from_endpoint = absint(get_query_var('view-order'));
  return $cached = ($from_endpoint ?: 0);
}

// Function: pl_get_buyer_user_id — Resolve buyer_user_id from GET/POST.
function pl_get_buyer_user_id(string $prefer = 'any'): int {
  return pl_req_int('buyer_user_id', $prefer);
}

// Function: pl_get_seller_status — Resolve order_status from GET/POST (string).
function pl_get_seller_status(string $prefer = 'any'): string {
  return pl_req_str('order_status', $prefer);
}

// Function: pl_get_seller_user_id — Resolve seller_user_id from GET/POST.
function pl_get_seller_user_id(string $prefer = 'any'): int {
  return pl_req_int('seller_user_id', $prefer);
}

// Function: pl_get_seller_user_id_from_order — Read seller_user_id from vendoruser ACF field on the WC Order.
function pl_get_seller_user_id_from_order(WC_Order $order): int {
  // Get seller user ID from the 'vendoruser' ACF field
  $seller_user_id = get_field('vendoruser', $order->get_id());
  return (int) $seller_user_id;
}

// Function: pl_get_buyer_user_id_from_order — Read buyer_user_id from WC Order customer.
function pl_get_buyer_user_id_from_order(WC_Order $order): int {
  // Get buyer user ID from WooCommerce order customer
  return (int) $order->get_user_id();
}


// Optional: unified message for disallowed statuses
function pl_dispute_disallowed_status_message(): string {
  return __('Dispute creation is unavailable for this order status.', 'peerloot');
}



/* ===================================================================================
 * Ticket opener must be the buyer or the seller of the order.
 * Blocks at display-time (pre_render / pre_validation) and submit-time (validation).
 * =================================================================================== */

/** Shared check: returns true if current user is buyer or seller for the resolved order. */
function pl_dispute_current_user_is_party_for_order(): bool {
  $uid      = pl_user_id();
  if (!$uid) return false;

  $order_id = pl_get_current_order_id(); // covers GET/POST/endpoint in your helpers
  if (!$order_id) return false;

  $buyer_uid  = pl_get_buyer_user_id();
  $seller_uid = pl_get_seller_user_id();

  if (!$buyer_uid || !$seller_uid) {
    $order = wc_get_order($order_id);
    if ($order instanceof WC_Order) {
      if (!$buyer_uid)  $buyer_uid  = pl_get_buyer_user_id_from_order($order);
      if (!$seller_uid) $seller_uid = pl_get_seller_user_id_from_order($order);
    }
  }

  return ($uid && ($uid === (int)$buyer_uid || $uid === (int)$seller_uid));
}

/** Display-time gate: replace form with a message if the user is not a party. */
add_filter('gform_pre_render_' . PL_FORM_TICKET_ID, 'pl_dispute_hide_ticket_form_if_not_party');
add_filter('gform_pre_validation_' . PL_FORM_TICKET_ID, 'pl_dispute_hide_ticket_form_if_not_party');
function pl_dispute_hide_ticket_form_if_not_party($form) {
  $order_id = pl_get_current_order_id();

  $status_block = false;
  if ($order_id && function_exists('pl_dispute_is_order_status_allowed')) {
    $status_block = !pl_dispute_is_order_status_allowed($order_id);
  }

  $party_block = !pl_dispute_current_user_is_party_for_order();

  if ($status_block || $party_block) {
    add_filter('gform_get_form_filter_' . PL_FORM_TICKET_ID, function ($html) use ($status_block, $party_block) {
      $msg_text = $status_block
        ? pl_dispute_disallowed_status_message()
        : __('You are not allowed to open a dispute for this order.', 'peerloot');

      $msg  = '<div class="gform_wrapper">';
      $msg .= '<div class="gform_validation_errors" style="margin:1rem 0">';
      $msg .= esc_html($msg_text);
      $msg .= '</div></div>';
      return $msg;
    }, 10, 1);
  }

  return $form;
}


/** Submit-time gate: reject POST if the user is not a party (server-side safeguard). */
add_filter('gform_validation_' . PL_FORM_TICKET_ID, function ($result) {
  $form     = $result['form'];
  $order_id = pl_get_current_order_id();

  $status_block = false;
  if ($order_id && function_exists('pl_dispute_is_order_status_allowed')) {
    $status_block = !pl_dispute_is_order_status_allowed($order_id);
  }

  $party_block = !pl_dispute_current_user_is_party_for_order();

  if ($status_block || $party_block) {
    foreach ($form['fields'] as &$field) {
      if ((int) $field->id === PL_F_T_ORDER_ID) {
        $field->failed_validation  = true;
        $field->validation_message = $status_block
          ? pl_dispute_disallowed_status_message()
          : __('You are not allowed to open a dispute for this order.', 'peerloot');
        break;
      }
    }
    $result['form']     = $form;
    $result['is_valid'] = false;
  }

  return $result;
});






/* ===================================================================================
 * Hide Ticket form if an active ticket already exists for this order_id.
 * This runs at display time (pre_render / pre_validation) and replaces the form HTML.
 * =================================================================================== */
add_filter('gform_pre_render_' . PL_FORM_TICKET_ID, 'pl_dispute_hide_ticket_form_if_duplicate');
add_filter('gform_pre_validation_' . PL_FORM_TICKET_ID, 'pl_dispute_hide_ticket_form_if_duplicate');
function pl_dispute_hide_ticket_form_if_duplicate($form) {
  $order_id = pl_get_current_order_id();
  if (!$order_id) return $form;

  $entries = GFAPI::get_entries(
    PL_FORM_TICKET_ID,
    array(
      'status'        => 'active',
      'field_filters' => array(
        array(
          'key'   => (string) PL_F_T_ORDER_ID,
          'value' => (string) $order_id,
        ),
        // If you only consider "open" as duplicate, add:
        // array('key' => (string) PL_F_T_STATUS, 'value' => 'open'),
      ),
    ),
    null,
    array('page_size' => 1)
  );

  if (!is_wp_error($entries) && !empty($entries)) {
    add_filter('gform_get_form_filter_' . PL_FORM_TICKET_ID, function ($html) {
      $msg  = '<div class="gform_wrapper">';
      $msg .= '<div class="gform_validation_errors" style="margin:1rem 0">';
      $msg .= esc_html__('A dispute already exists for this order. Please use the existing case.', 'peerloot');
      $msg .= '</div>';
      $msg .= '<div class="uagb-button__wrapper"><a class="uagb-buttons-repeater wp-block-button__link" aria-label="" href="https://www.peerloot.com/dispute/list/" rel="follow noopener" target="_self" role="button"><div class="uagb-button__link">Viwe</div></a></div>';      
      $msg .= '</div>';
      return $msg;
    }, 10, 1);
  }

  return $form;
}






/* =====================================================
 * SECURITY GUARDS FOR REPLIES (Reply form)
 * ===================================================== */

// Filter: gform_validation_{ReplyForm} — Allow replies only by buyer/seller/admin with valid recipient.
add_filter('gform_validation_' . PL_FORM_REPLY_ID, function ($result) {
  $form = $result['form'];
  $parent_entry_id = absint(pl_gf_post(PL_F_R_PARENT));
  $current_uid     = pl_user_id();

  if (!$parent_entry_id || !$current_uid) {
    $result['is_valid'] = false;
    return $result;
  }

  $ticket = GFAPI::get_entry($parent_entry_id);
  if (is_wp_error($ticket) || (int)$ticket['form_id'] !== PL_FORM_TICKET_ID) {
    $result['is_valid'] = false;
    return $result;
  }

  $buyer_uid  = (int)$ticket[(string)PL_F_T_BUYER_UID];
  $seller_uid = (int)$ticket[(string)PL_F_T_SELLER_UID];

  $real_sender = 'guest';
  if     ($current_uid === $buyer_uid)  $real_sender = 'buyer';
  elseif ($current_uid === $seller_uid) $real_sender = 'seller';
  elseif (user_can($current_uid, 'manage_options') || in_array('administrator', (array) (get_userdata($current_uid)->roles ?? []), true)) {
    $real_sender = 'admin';
  }

  $recip = sanitize_text_field(pl_gf_post(PL_F_R_RECIP));
  $valid = true;

  if ($real_sender === 'buyer' || $real_sender === 'seller') {
    if ($recip !== 'admin') $valid = false;
  } elseif ($real_sender === 'admin') {
    if (!in_array($recip, array('buyer', 'seller'), true)) $valid = false;
  } else {
    $valid = false;
  }

  if (!$valid) {
    foreach ($form['fields'] as &$field) {
      if ((int)$field->id === PL_F_R_RECIP) {
        $field->failed_validation  = true;
        $field->validation_message = __('Invalid recipient for this reply.', 'peerloot');
        break;
      }
    }
    $result['form']     = $form;
    $result['is_valid'] = false;
    return $result;
  }

  // Anti-spoof: force sender role server-side
  $_POST['input_' . PL_F_R_SENDER] = $real_sender;

  return $result;
});










/* =====================================================
 * AUTOFILL Ticket fields (Woo → GF) + URL/POST overrides
 * ===================================================== */

// Filter: gform_field_value_order_id — Populate order_id dynamically.
add_filter('gform_field_value_order_id', function ($value) {
  $oid = pl_get_current_order_id();
  return $oid ?: $value;
});

// Filter: gform_field_value_order_status — Populate order_status (URL/POST first, then Woo).
add_filter('gform_field_value_order_status', function ($value) {
  $url_status = pl_get_seller_status();
  if (!empty($url_status)) return $url_status;

  $oid = pl_get_current_order_id();
  if (!$oid) return $value;

  $order = wc_get_order($oid);
  return ($order instanceof WC_Order) ? $order->get_status() : $value;
});

// Filter: gform_field_value_buyer_user_id — Populate buyer_user_id from WooCommerce order customer.
add_filter('gform_field_value_buyer_user_id', function ($value) {
  $url_buyer = pl_get_buyer_user_id();
  if ($url_buyer) return $url_buyer;

  $oid = pl_get_current_order_id();
  if (!$oid) return $value;

  $order = wc_get_order($oid);
  return ($order instanceof WC_Order) ? pl_get_buyer_user_id_from_order($order) : $value;
});

// Filter: gform_field_value_seller_user_id — Populate seller_user_id from vendoruser ACF field.
add_filter('gform_field_value_seller_user_id', function ($value) {
  $url_seller = pl_get_seller_user_id();
  if ($url_seller) return $url_seller;

  $oid = pl_get_current_order_id();
  if (!$oid) return $value;

  $order = wc_get_order($oid);
  if (!($order instanceof WC_Order)) return $value;

  $seller = pl_get_seller_user_id_from_order($order);
  return $seller ?: $value;
});

// Filter: gform_pre_render/validation_{TicketForm} — Set defaults/fallbacks and initial ticket status.
add_filter('gform_pre_render_'     . PL_FORM_TICKET_ID, 'pl_dispute_ticket_defaults');
add_filter('gform_pre_validation_' . PL_FORM_TICKET_ID, 'pl_dispute_ticket_defaults');
function pl_dispute_ticket_defaults($form) {
  // Function: pl_dispute_ticket_defaults — Apply URL overrides and Woo fallbacks for ticket fields.

  $ovr_order_id   = pl_get_current_order_id();
  $ovr_status     = pl_get_seller_status();
  $ovr_buyer_uid  = pl_get_buyer_user_id();
  $ovr_seller_uid = pl_get_seller_user_id();

  $order = $ovr_order_id ? wc_get_order($ovr_order_id) : null;

  foreach ($form['fields'] as &$field) {
    switch ((int) $field->id) {

      case PL_F_T_ORDER_ID:
        if (empty($field->defaultValue) && $ovr_order_id) {
          $field->defaultValue = $ovr_order_id;
        }
        break;

      case PL_F_T_ORDER_STATUS:
        if (empty($field->defaultValue)) {
          if (!empty($ovr_status)) {
            $field->defaultValue = $ovr_status;
          } elseif ($order instanceof WC_Order) {
            $field->defaultValue = $order->get_status();
          }
        }
        break;

      case PL_F_T_BUYER_UID:
        if (empty($field->defaultValue)) {
          if ($ovr_buyer_uid) {
            $field->defaultValue = (int) $ovr_buyer_uid;
          } elseif ($order instanceof WC_Order) {
            $field->defaultValue = pl_get_buyer_user_id_from_order($order);
          }
        }
        break;

      case PL_F_T_SELLER_UID:
        if (empty($field->defaultValue)) {
          if ($ovr_seller_uid) {
            $field->defaultValue = (int) $ovr_seller_uid;
          } elseif ($order instanceof WC_Order) {
            $seller = pl_get_seller_user_id_from_order($order);
            if ($seller) $field->defaultValue = (int) $seller;
          }
        }
        break;

      case PL_F_T_STATUS:
        if (empty($field->defaultValue)) {
          $field->defaultValue = 'open';
        }
        break;
    }
  }

  return $form;
}



/**
 * Sync Woo order status -> GF Ticket field (PL_F_T_ORDER_STATUS) on status change
 */
add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status, $order) {
    if (!class_exists('GFAPI')) return;

    $search_criteria = array(
        'status'        => 'active',
        'field_filters' => array(
            'mode' => 'all',
            array(
                'key'   => (string) PL_F_T_ORDER_ID,
                'value' => (string) $order_id,
            ),
        ),
    );

    $sorting = null;
    $paging  = array('offset' => 0, 'page_size' => 200);

    do {
        $entries = GFAPI::get_entries(PL_FORM_TICKET_ID, $search_criteria, $sorting, $paging);
        if (is_wp_error($entries) || empty($entries)) {
            break;
        }

        foreach ($entries as $entry) {
            $entry_id = (int) rgar($entry, 'id');

            GFAPI::update_entry_field($entry_id, PL_F_T_ORDER_STATUS, $new_status);
        }

        $paging['offset'] += $paging['page_size'];
    } while (count($entries) === $paging['page_size']);
}, 10, 4);
