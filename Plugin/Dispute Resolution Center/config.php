<?php
/* =========================
 * CONFIG
 * ========================= */

// Gravity Forms – form IDs
const PL_FORM_TICKET_ID = 59; // Dispute Ticket
const PL_FORM_REPLY_ID  = 60; // Dispute Reply

// GravityView – Router View
const PL_ROUTER_VIEW_ID = 4431;


// Ticket fields (Dispute Ticket form)
const PL_F_T_ORDER_ID     = 1; // hidden: order_id
const PL_F_T_BUYER_UID    = 3; // hidden: buyer_user_id
const PL_F_T_SELLER_UID   = 4; // hidden: seller_user_id
const PL_F_T_STATUS       = 7; // hidden/dropdown: status
const PL_F_T_ORDER_STATUS = 8; // hidden: order_status
const PL_F_SUBJECT      = 5;
const PL_F_DETAIL       = 6;
const PL_F_ATTACH       = 9;


// Reply fields (Dispute Reply form)
const PL_F_R_PARENT = 1; // parent_ticket
const PL_F_R_SENDER = 4; // sender_role
const PL_F_R_RECIP  = 7; // recipient_role
const PL_R_MESSAGE      = 5;
const PL_R_ATTACH       = 6;

?>