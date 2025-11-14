<?php

add_action('admin_init', function () {
    if (!class_exists('GFAPI')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>PL Disputes Admin:</strong> Gravity Forms is required.</p></div>';
        });
    }
});

add_action('admin_menu', function () {
    add_menu_page(
        'Disputes',
        'Disputes',
        'manage_options',
        'pl-disputes',
        'pl_disputes_admin_list_page',
        'dashicons-megaphone',
        56
    );

    add_submenu_page(
        'pl-disputes',                                // parent slug
        __('Dispute System Settings', 'pl-disputes'), // page title
        __('Settings', 'pl-disputes'),                // menu title
        'manage_options',                             // capability
        'pl-dispute-settings',                        // menu slug
        'pl_dispute_settings_page_cb'                 // callback (در pl-disputes-settings.php)
    );
});


function plgfv($entry, $fid) {
    $k = (string) $fid;
    return isset($entry[$k]) ? $entry[$k] : '';
}
function pl_is_recipient($v) {
    return in_array($v, ['buyer','seller'], true) ? $v : 'buyer';
}
function pl_parse_attachments($raw) {
    if (empty($raw)) return [];
    if (is_array($raw)) return $raw;
    $raw = trim((string)$raw);
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
    if (strpos($raw,'|') !== false) return array_filter(array_map('trim', explode('|',$raw)));
    if (strpos($raw,',') !== false) return array_filter(array_map('trim', explode(',',$raw)));
    return [$raw];
}
function pl_user_display($uid) {
    $u = get_user_by('id', (int)$uid);
    return $u ? esc_html($u->display_name) . ' (#'.(int)$uid.')' : ($uid ? 'User #'.(int)$uid : '—');
}
function pl_get_gf_field_choices($form_id, $field_id) {
    $form = GFAPI::get_form($form_id);
    if (!$form || empty($form['fields'])) return [];
    foreach ($form['fields'] as $field) {
        if ((int)$field->id === (int)$field_id && !empty($field->choices)) {
            $out = [];
            foreach ($field->choices as $ch) {
                $out[] = [
                    'text'  => isset($ch['text']) ? (string)$ch['text'] : '',
                    'value' => isset($ch['value']) ? (string)$ch['value'] : (string)$ch['text'],
                ];
            }
            return $out;
        }
    }
    return [];
}

function pl_disputes_admin_list_page() {
    if (!class_exists('GFAPI')) {
        echo '<div class="wrap"><h1>Disputes</h1><p>Gravity Forms required.</p></div>';
        return;
    }

    // route to single (handle) view early
if (isset($_GET['action']) && $_GET['action'] === 'handle') {
    pl_disputes_admin_handle_page();
    return;
}


    // ----- read filters -----
    $f_creator      = isset($_GET['f_creator']) ? sanitize_text_field($_GET['f_creator']) : ''; // '', buyer, seller
    $f_order_id     = isset($_GET['f_order_id']) ? sanitize_text_field($_GET['f_order_id']) : '';
    $f_order_status = isset($_GET['f_order_status']) ? sanitize_text_field($_GET['f_order_status']) : '';
    $f_ticket_status= isset($_GET['f_ticket_status']) ? sanitize_text_field($_GET['f_ticket_status']) : '';
    $f_from         = isset($_GET['f_created_from']) ? sanitize_text_field($_GET['f_created_from']) : '';
    $f_to           = isset($_GET['f_created_to']) ? sanitize_text_field($_GET['f_created_to']) : '';

    // ----- build GF search criteria -----
    $search_criteria = [
        'status'        => 'active',
        'field_filters' => ['mode' => 'all']
    ];

    if ($f_order_id !== '') {
        $search_criteria['field_filters'][] = [
            'key'      => (string) PL_F_T_ORDER_ID,
            'operator' => 'contains',
            'value'    => $f_order_id
        ];
    }
    if ($f_order_status !== '') {
        $search_criteria['field_filters'][] = [
            'key'   => (string) PL_F_T_ORDER_STATUS,
            'value' => $f_order_status
        ];
    }
    if ($f_ticket_status !== '') {
        $search_criteria['field_filters'][] = [
            'key'   => (string) PL_F_T_STATUS,
            'value' => $f_ticket_status
        ];
    }
    if ($f_from !== '') {
        // expects Y-m-d (Gravity Forms accepts many formats but this is safest)
        $search_criteria['start_date'] = $f_from;
    }
    if ($f_to !== '') {
        $search_criteria['end_date'] = $f_to;
    }

    $sorting = ['key' => 'date_created', 'direction' => 'DESC', 'is_numeric' => false];

    // We first fetch a large page and then apply "Creator" (derived) filter in PHP.
    // Adjust the cap if needed.
    $cap = 2000;
    $total_ignored = 0;
    $all = GFAPI::get_entries(PL_FORM_TICKET_ID, $search_criteria, $sorting, ['offset' => 0, 'page_size' => $cap], $total_ignored);

    // post-filter by creator (buyer/seller)
    if (in_array($f_creator, ['buyer','seller'], true) && is_array($all)) {
        $all = array_values(array_filter($all, function($e) use ($f_creator) {
            $buyerId   = (int) plgfv($e, PL_F_T_BUYER_UID);
            $sellerId  = (int) plgfv($e, PL_F_T_SELLER_UID);
            $createdBy = (int) $e['created_by'];
            if ($f_creator === 'buyer')  return ($createdBy && $createdBy === $buyerId);
            if ($f_creator === 'seller') return ($createdBy && $createdBy === $sellerId);
            return true;
        }));
    }

    // ----- pagination -----
    $paged   = max(1, (int)($_GET['paged'] ?? 1));
    $perpage = 20;
    $total_count = is_array($all) ? count($all) : 0;
    $rows = array_slice($all ?: [], ($paged-1)*$perpage, $perpage);

    // helper for preserving query args
    $base_query = [
        'page'            => 'pl-disputes',
        'f_creator'       => $f_creator,
        'f_order_id'      => $f_order_id,
        'f_order_status'  => $f_order_status,
        'f_ticket_status' => $f_ticket_status,
        'f_created_from'  => $f_from,
        'f_created_to'    => $f_to,
    ];

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">Disputes</h1>';
    echo '<hr class="wp-header-end" />';

    // ----- filter bar -----
    $order_status_choices  = pl_get_gf_field_choices(PL_FORM_TICKET_ID, PL_F_T_ORDER_STATUS);
    $ticket_status_choices = pl_get_gf_field_choices(PL_FORM_TICKET_ID, PL_F_T_STATUS);

    echo '<div class="tablenav top"><div class="alignleft actions">';

    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'">';
    echo '<input type="hidden" name="page" value="pl-disputes" />';

    // Creator
    echo '<label class="screen-reader-text" for="f_creator">Creator</label>';
    echo '<select name="f_creator" id="f_creator">';
    echo '<option value="">All creators</option>';
    echo '<option value="buyer" '.selected($f_creator,'buyer',false).'>Buyer</option>';
    echo '<option value="seller" '.selected($f_creator,'seller',false).'>Seller</option>';
    echo '</select> ';

    // Order ID
    echo '<label class="screen-reader-text" for="f_order_id">Order ID</label>';
    echo '<input type="text" name="f_order_id" id="f_order_id" value="'.esc_attr($f_order_id).'" placeholder="Order ID" /> ';

    // Created date from/to
    echo '<label class="screen-reader-text" for="f_created_from">From</label>';
    echo '<input type="date" name="f_created_from" id="f_created_from" value="'.esc_attr($f_from).'" /> ';
    echo '<label class="screen-reader-text" for="f_created_to">To</label>';
    echo '<input type="date" name="f_created_to" id="f_created_to" value="'.esc_attr($f_to).'" /> ';

    // Order status
    // echo '<label class="screen-reader-text" for="f_order_status">Order Status</label>';
    // echo '<select name="f_order_status" id="f_order_status">';
    // echo '<option value="">All order statuses</option>';
    // foreach ($order_status_choices as $c) {
    //     $val = (string)$c['value'];
    //     $txt = $c['text'] !== '' ? $c['text'] : $val;
    //     echo '<option value="'.esc_attr($val).'" '.selected($val, $f_order_status, false).'>'.esc_html($txt).'</option>';
    // }
    // echo '</select> ';

    // Ticket status
    echo '<label class="screen-reader-text" for="f_ticket_status">Ticket Status</label>';
    echo '<select name="f_ticket_status" id="f_ticket_status">';
    echo '<option value="">All ticket statuses</option>';
    foreach ($ticket_status_choices as $c) {
        $val = (string)$c['value'];
        $txt = $c['text'] !== '' ? $c['text'] : $val;
        echo '<option value="'.esc_attr($val).'" '.selected($val, $f_ticket_status, false).'>'.esc_html($txt).'</option>';
    }
    echo '</select> ';

    submit_button('Filter', 'secondary', '', false);
    $reset_url = add_query_arg(['page' => 'pl-disputes'], admin_url('admin.php'));
    echo ' <a class="button" href="'.esc_url($reset_url).'">Reset</a>';

    echo '</form>';

    echo '</div><br class="clear" /></div>';

    // ----- list table -----
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:60px;">#</th>';
    echo '<th>Creator</th>';
    echo '<th>Order</th>';
    echo '<th>Created</th>';
    echo '<th>Order Status</th>';
    echo '<th>Ticket Status</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    if ($rows) {
        $rownum = ($paged - 1) * $perpage + 1;
        foreach ($rows as $e) {
            $eid       = (int)$e['id'];
            $created   = esc_html(get_date_from_gmt($e['date_created'], 'Y-m-d H:i'));
            $orderId   = esc_html(plgfv($e, PL_F_T_ORDER_ID));
            $ost       = esc_html(plgfv($e, PL_F_T_ORDER_STATUS));
            $tst       = esc_html(plgfv($e, PL_F_T_STATUS));
            $buyerId   = (int)plgfv($e, PL_F_T_BUYER_UID);
            $sellerId  = (int)plgfv($e, PL_F_T_SELLER_UID);
            $createdBy = (int)$e['created_by'];
            $creatorRole = ($createdBy && $createdBy === $buyerId) ? 'Buyer' : (($createdBy && $createdBy === $sellerId) ? 'Seller' : '—');

            $handle_url = admin_url('admin.php?page=pl-disputes&action=handle&entry='.$eid.'&recipient=buyer&_wpnonce='.wp_create_nonce('pl-handle-'.$eid));

            echo '<tr>';
            echo '<td>'.(int)$rownum++.'</td>';
            echo '<td>'.$creatorRole.'</td>';
            echo '<td>'.($orderId ? esc_html($orderId) : '—').'</td>';
            echo '<td>'.$created.'</td>';
            echo '<td>'.$ost.'</td>';
            echo '<td>'.$tst.'</td>';
            echo '<td><a class="button button-primary" href="'.esc_url($handle_url).'">Handle</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7">No disputes found.</td></tr>';
    }
    echo '</tbody></table>';

    // ----- pagination with filters preserved -----
    $total_pages = (int)ceil($total_count / $perpage);
    if ($total_pages > 1) {
        $page_base = add_query_arg(array_merge($base_query, ['paged' => '%#%']), admin_url('admin.php'));
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base'      => $page_base,
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => $total_pages,
            'current'   => $paged,
        ]);
        echo '</div></div>';
    }

    echo '</div>';
}

function pl_disputes_admin_handle_page() {
    if (!class_exists('GFAPI')) return;

    $eid = (int)($_GET['entry'] ?? 0);
    $nonce_ok = isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'pl-handle-' . $eid);
    if (!$eid || !$nonce_ok) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Invalid entry or nonce.</p></div></div>';
        return;
    }

    $entry = GFAPI::get_entry($eid);
    if (is_wp_error($entry) || !$entry) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Entry not found.</p></div></div>';
        return;
    }

    $subject  = esc_html(plgfv($entry, PL_F_SUBJECT));
    $status   = esc_html(plgfv($entry, PL_F_T_STATUS));
    $orderId  = esc_html(plgfv($entry, PL_F_T_ORDER_ID));
    $orderSt  = esc_html(plgfv($entry, PL_F_T_ORDER_STATUS));
    $detail   = wp_kses_post(nl2br(plgfv($entry, PL_F_DETAIL)));
    $buyerId  = (int)plgfv($entry, PL_F_T_BUYER_UID);
    $sellerId = (int)plgfv($entry, PL_F_T_SELLER_UID);
    $files    = pl_parse_attachments(plgfv($entry, PL_F_ATTACH));

    $recipient = pl_is_recipient($_GET['recipient'] ?? 'buyer');

    // Handle status update (POST)
    if (isset($_POST['pl_update_status']) && check_admin_referer('pl_update_status_' . $eid)) {
        $new_status = sanitize_text_field($_POST['pl_ticket_status'] ?? '');
        $choices = pl_get_gf_field_choices(PL_FORM_TICKET_ID, PL_F_T_STATUS);
        $allowed = array_map(function($c){ return (string)$c['value']; }, $choices);
        if (in_array($new_status, $allowed, true)) {
            GFAPI::update_entry_field($eid, PL_F_T_STATUS, $new_status);
            // Rebuild self URL with nonce and keep current recipient
            $base_args = ['page'=>'pl-disputes','action'=>'handle','entry'=>$eid,'recipient'=>$recipient];
            $self_url  = wp_nonce_url(add_query_arg($base_args, admin_url('admin.php')), 'pl-handle-'.$eid);
            wp_redirect(add_query_arg('updated_status', 1, $self_url));
            exit;
        } else {
            echo '<div class="wrap"><div class="notice notice-error is-dismissible"><p>Invalid status value.</p></div></div>';
        }
    }

    $base_args = ['page'=>'pl-disputes','action'=>'handle','entry'=>$eid];
    $switch_b = wp_nonce_url(add_query_arg(array_merge($base_args, ['recipient'=>'buyer']),  admin_url('admin.php')), 'pl-handle-'.$eid);
    $switch_s = wp_nonce_url(add_query_arg(array_merge($base_args, ['recipient'=>'seller']), admin_url('admin.php')), 'pl-handle-'.$eid);

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">Handle Dispute</h1>';
    echo ' <a href="'.esc_url(admin_url('admin.php?page=pl-disputes')).'" class="page-title-action">Back to list</a>';
    echo '<hr class="wp-header-end" />';

    if (!empty($_GET['updated_status'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Ticket status updated.</p></div>';
    }

    echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';

    echo '<div id="post-body-content">';

    echo '<div class="postbox"><h2 class="hndle"><span>Summary</span></h2><div class="inside">';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Subject</th><td>'.$subject.'</td></tr>';
    echo '<tr><th scope="row">Ticket Status</th><td>'.esc_html(plgfv($entry, PL_F_T_STATUS)).'</td></tr>';
    echo '<tr><th scope="row">Order ID</th><td>'.($orderId ?: '—').'</td></tr>';
    echo '<tr><th scope="row">Order Status</th><td>'.$orderSt.'</td></tr>';
    echo '<tr><th scope="row">Buyer</th><td>'.pl_user_display($buyerId).'</td></tr>';
    echo '<tr><th scope="row">Seller</th><td>'.pl_user_display($sellerId).'</td></tr>';
    echo '</tbody></table>';
    echo '</div></div>';

    echo '<div class="postbox"><h2 class="hndle"><span>Initial Message</span></h2><div class="inside">';
    echo $detail ? '<p>'.$detail.'</p>' : '<p><em>No message.</em></p>';
    if (!empty($files)) {
        echo '<p><strong>Attachments:</strong></p><ul>';
        foreach ($files as $f) {
            $url = esc_url($f);
            $basename = esc_html(wp_basename($f));
            echo '<li><a href="'.$url.'" target="_blank" rel="noreferrer">'.$basename.'</a></li>';
        }
        echo '</ul>';
    }
    echo '</div></div>';

    echo '<h2 class="nav-tab-wrapper">';
    echo '<a class="nav-tab '.($recipient==='buyer'?'nav-tab-active':'').'" href="'.$switch_b.'">Buyer thread</a>';
    echo '<a class="nav-tab '.($recipient==='seller'?'nav-tab-active':'').'" href="'.$switch_s.'">Seller thread</a>';
    echo '</h2>';

    echo '<div class="postbox"><h2 class="hndle"><span>Thread History – '.ucfirst($recipient).'</span></h2><div class="inside">';
    pl_render_thread_history($eid, $recipient);
    echo '</div></div>';

    echo '<div class="postbox"><h2 class="hndle"><span>Send Message to '.ucfirst($recipient).'</span></h2><div class="inside">';
    echo '<div style="margin:8px 0 12px;">';
    echo '<label><input type="radio" name="pl_recipient" value="buyer" data-url="'.esc_url($switch_b).'" '.checked($recipient,'buyer',false).'> Buyer</label> &nbsp; ';
    echo '<label><input type="radio" name="pl_recipient" value="seller" data-url="'.esc_url($switch_s).'" '.checked($recipient,'seller',false).'> Seller</label>';
    echo '</div>';

    $params = http_build_query([
        'parent_ticket'  => $eid,
        'sender_role'    => 'admin',
        'recipient_role' => $recipient,
    ]);
    $short = sprintf(
        '[gravityform id="%d" ajax="true" title="false" description="false" field_values="%s"]',
        PL_FORM_REPLY_ID,
        esc_attr($params)
    );
    echo do_shortcode($short);

    echo '</div></div>';

    echo '</div>';

    // Right column: status control
    echo '<div id="postbox-container-1" class="postbox-container">';

    echo '<div class="postbox"><h2 class="hndle"><span>Ticket Status</span></h2><div class="inside">';
    $choices = pl_get_gf_field_choices(PL_FORM_TICKET_ID, PL_F_T_STATUS);
    echo '<form method="post">';
    wp_nonce_field('pl_update_status_' . $eid);
    echo '<select name="pl_ticket_status">';
    $current = plgfv($entry, PL_F_T_STATUS);
    foreach ($choices as $c) {
        $val = (string)$c['value'];
        $txt = (string)$c['text'];
        echo '<option value="'.esc_attr($val).'" '.selected($val, $current, false).'>'.esc_html($txt ?: $val).'</option>';
    }
    echo '</select> ';
    submit_button('Update Status', 'primary', 'pl_update_status', false);
    echo '</form>';
    echo '</div></div>';

    echo '<div class="postbox"><h2 class="hndle"><span>Tools</span></h2><div class="inside"><p><em>No tools yet.</em></p></div></div>';

    echo '</div>';

    echo '</div></div>';
    echo '</div>';
}

function pl_render_thread_history($parent_entry_id, $party) {
    $filters1 = [
        'status'        => 'active',
        'field_filters' => [
            'mode' => 'all',
            ['key' => (string)PL_F_R_PARENT,      'value' => (string)$parent_entry_id],
            ['key' => (string)PL_F_R_SENDER, 'value' => $party],
        ],
    ];
    $filters2 = [
        'status'        => 'active',
        'field_filters' => [
            'mode' => 'all',
            ['key' => (string)PL_F_R_PARENT,      'value' => (string)$parent_entry_id],
            ['key' => (string)PL_F_R_RECIP,  'value' => $party],
        ],
    ];
    $sorting = ['key'=>'date_created','direction'=>'ASC'];

    $total1 = 0; $r1 = GFAPI::get_entries(PL_FORM_REPLY_ID, $filters1, $sorting, ['offset'=>0,'page_size'=>200], $total1);
    $total2 = 0; $r2 = GFAPI::get_entries(PL_FORM_REPLY_ID, $filters2, $sorting, ['offset'=>0,'page_size'=>200], $total2);

    $map = [];
    foreach ([$r1,$r2] as $set) {
        if (is_array($set)) {
            foreach ($set as $row) { $map[$row['id']] = $row; }
        }
    }
    $replies = array_values($map);
    usort($replies, function($a,$b){ return strcmp($a['date_created'],$b['date_created']); });

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th style="width:120px;">When</th><th style="width:120px;">Sender</th><th>Message</th><th style="width:180px;">Attachments</th></tr></thead><tbody>';

    if ($replies) {
        foreach ($replies as $r) {
            $when   = esc_html(get_date_from_gmt($r['date_created'], 'Y-m-d H:i'));
            $sender = ucfirst(esc_html(plgfv($r, PL_F_R_SENDER)));
            $msg    = wp_kses_post(nl2br(plgfv($r, PL_R_MESSAGE)));
            $atts   = pl_parse_attachments(plgfv($r, PL_R_ATTACH));

            echo '<tr>';
            echo '<td>'.$when.'</td>';
            echo '<td>'.$sender.'</td>';
            echo '<td>'.$msg.'</td>';
            echo '<td>';
            if ($atts) {
                foreach ($atts as $a) {
                    $url = esc_url($a);
                    echo '<div><a href="'.$url.'" target="_blank" rel="noreferrer">'.esc_html(wp_basename($a)).'</a></div>';
                }
            } else {
                echo '—';
            }
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="4">No messages.</td></tr>';
    }
    echo '</tbody></table>';
}

add_action('load-toplevel_page_pl-disputes', function () {
    if (isset($_GET['action']) && $_GET['action']==='handle') {
        add_action('admin_notices', function () {});
    }
});

add_action('admin_print_footer_scripts', function () {
    if (empty($_GET['page']) || $_GET['page'] !== 'pl-disputes') return;
    if (empty($_GET['action']) || $_GET['action'] !== 'handle') return;
    $form_id = (int) PL_FORM_REPLY_ID;
    ?>
    <script>
    (function() {
      document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'pl_recipient') {
          var url = e.target.getAttribute('data-url');
          if (url) { window.location.href = url; }
        }
      });
      document.addEventListener('gform_confirmation_loaded', function (ev) {
        try {
          if (ev.detail && Number(ev.detail.formId) === <?php echo $form_id; ?>) {
            window.location.reload();
          }
        } catch(e){}
      });
      if (window.jQuery) {
        jQuery(document).on('gform_confirmation_loaded', function (e, formId) {
          if (Number(formId) === <?php echo $form_id; ?>) {
            window.location.reload();
          }
        });
        jQuery(document).on('gformAjaxSuccess', function (e, data) {
          try {
            if (data && Number(data.formId) === <?php echo $form_id; ?>) {
              window.location.reload();
            }
          } catch(err){}
        });
      }
    })();
    </script>
    <?php
});
