<?php
/*
Plugin Name: Custom Credit
Description: A plugin to give credits to customer in WooCommerce.
Version: 2.5
Author: Baifumei
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Activation hook
register_activation_hook(__FILE__, 'create_customer_credits_table');

function create_customer_credits_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'customer_credits';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        user_id bigint(20) NOT NULL,
        credits decimal(15, 2) NOT NULL DEFAULT 0,
        total_earned_credits decimal(15, 2) NOT NULL DEFAULT 0,
        history longtext NULL,
        PRIMARY KEY (id),
        UNIQUE KEY user_id (user_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Deactivation hook (you can remove this if you only want to handle table removal on uninstall)
// register_deactivation_hook(__FILE__, 'drop_customer_credits_table');

// function drop_customer_credits_table() {
//     global $wpdb;
//     $table_name = $wpdb->prefix . 'customer_credits';
//     $sql = "DROP TABLE IF EXISTS $table_name;";
//     $wpdb->query($sql);
// }

// Uninstall hook
// register_uninstall_hook(__FILE__, 'uninstall_customer_credits_table');

// function uninstall_customer_credits_table() {
//     global $wpdb;
//     $table_name = $wpdb->prefix . 'customer_credits';
//     $sql = "DROP TABLE IF EXISTS $table_name;";
//     $wpdb->query($sql);
// }


add_action('user_register', 'baifumei_give_initial_credits', 10, 1);

function baifumei_give_initial_credits($user_id) {
    global $wpdb;

    // Check if user already has credits (to avoid duplicate entries)
    $existing_credits = $wpdb->get_var($wpdb->prepare(
        "SELECT credits FROM {$wpdb->prefix}user_credits WHERE user_id = %d",
        $user_id
    ));

    // If no existing credits record, insert initial credits
    if ($existing_credits === null) {
        $initial_credits = 5.00; // Initial credits to give
        $wpdb->insert(
            $wpdb->prefix . 'customer_credits',
            array(
                'user_id' => $user_id,
                'credits' => $initial_credits,
                'total_earned_credits' => $initial_credits
            ),
            array('%d', '%f', '%f')
        );
    }
}


function log_customer_credits_update($user_id, $updater, $old_credit_value, $new_credit_value, $note = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'customer_credits';

    // Make sure $note is always set, even if it's not provided
    $note = isset($note) && !empty($note) ? $note : '';

    $history_entry = [
        'date' => current_time('mysql'),
        'updater' => $updater,
        'old_credit' => $old_credit_value,  // This is the old value before the update
        'new_credit' => $new_credit_value,  // This is the new value after the update
        'amount_added' => $new_credit_value - $old_credit_value,  // The difference
        'note' => $note,
    ];

    $history = $wpdb->get_var($wpdb->prepare(
        "SELECT history FROM $table_name WHERE user_id = %d",
        $user_id
    ));

    $history = maybe_unserialize($history);
    if (!is_array($history)) {
        $history = [];
    }
    $history[] = $history_entry;

    // Update the user's credit history in the database
    $wpdb->update($table_name, ['history' => maybe_serialize($history)], ['user_id' => $user_id]);
}


add_action('woocommerce_order_status_changed', 'update_customer_credits_on_status_change', 10, 4);

function update_customer_credits_on_status_change($order_id, $old_status, $new_status, $order) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'customer_credits';
    

    if (!$user_id) {
        return; // Skip guest orders
    }

    // Fetch the current user's credit data before any changes
    $current_data = $wpdb->get_row($wpdb->prepare(
        "SELECT credits FROM $table_name WHERE user_id = %d",
        $user_id
    ));

    if (!$current_data) {
        log_error('Unable to retrieve user credits for user ID: ' . $user_id);
        return;
    }

    $old_credits = $current_data->credits; // Old credit value before deduction or addition

    if (in_array($new_status, ['on-hold', 'processing', 'completed'])) {
        if (WC()->session && method_exists(WC()->session, 'get_sessions')) {
            $discount_value = WC()->session->get('store_credit_discount', 0);

            if ($discount_value > 0 && $discount_value <= $old_credits) {
                $new_credits = $old_credits - $discount_value;
                global $wpdb;
                $wpdb->update($table_name, ['credits' => $new_credits]);

                // Log the deduction
                log_customer_credits_update($user_id, 'Order #' . $order_id, $old_credits, $new_credits, 'Credits deducted for order');

                // Clear the discount from session
                WC()->session->__unset('store_credit_discount');
            }
        } else {
            error_log('WC()->session not available at this stage.');
        }
    }

    // Handle when the order is cancelled after being processed
    if ($new_status == 'cancelled' && in_array($old_status, ['on-hold', 'processing', 'completed'])) {
        $discount_value = WC()->session->get('store_credit_discount', 0);

        if ($discount_value > 0) {
            $new_credits = $old_credits + $discount_value;
            $wpdb->update($table_name, ['credits' => $new_credits], ['user_id' => $user_id]);

            // Log the credit restoration
            log_customer_credits_update($user_id, 'Order #' . $order->get_order_number(), $old_credits, $new_credits, 'Order cancelled: Credits restored');
        }
    }
}







add_action('admin_menu', 'add_credit_value_menu');

function add_credit_value_menu() {
    // Main menu page for 'Customer Credits', restricted by custom capability 'access_customer_credits'
    add_menu_page(
        'Customer Credits',           // Page title
        'Customer Credits',           // Menu title
        'access_customer_credits',    // Custom capability required to access this menu item
        'customer-credits',           // Menu slug
        'customer_credits_page_callback', // Callback function to display the page content
        'dashicons-money-alt',        // Icon
        3                             // Position in the menu
    );

    // Submenu for 'Settings', restricted by the same custom capability
    add_submenu_page(
        'customer-credits',           // Parent slug (should match the slug used in add_menu_page)
        'Settings',                   // Page title
        'Settings',                   // Menu title
        'access_customer_credits',    // Custom capability required to access this submenu item
        'settings',                   // Menu slug
        'credit_settings_callback'    // Callback function to display the page content
    );

    // Submenu for 'About', also restricted by the custom capability
    add_submenu_page(
        'customer-credits',           // Parent slug
        'About',                      // Page title
        'About',                      // Menu title
        'access_customer_credits',    // Custom capability required to access this submenu item
        'about',                      // Menu slug
        'credit_about_callback'       // Callback function to display the page content
    );
}
add_action('admin_menu', 'add_credit_value_menu');

function credit_settings_callback() {
    ?>
    <h1>Settings</h1>
    <h1>Building</h1>
    <?php
}

function credit_about_callback() {
    ?>
    <h1>About</h1>
    <div class="wrap">
        <style>
            table {
                font-family: arial, sans-serif;
                border-collapse: collapse;
                width: 100%;
            }

            td, th {
                border: 1px solid #dddddd;
                text-align: left;
                padding: 8px;
            }

            tr:nth-child(even) {
                background-color: #dddddd;
            }
        </style>

        <h2>Exclusive License</h2>

        <table>
            <tr>
                <th>Company</th>
                <th>Expiry</th>
            </tr>
            <tr>
                <td>Baifumei</td>
                <td>Unlimited</td>
            </tr>
        </table>
    </div>
    <?php
}

// Callback function to display customer information or profile page
function customer_credits_page_callback() {
    if (isset($_GET['user_id'])) {
        display_customer_profile_page_credits(intval($_GET['user_id']));
    } else {
        display_customer_credits_table();
    }
}
























// Function to display the customer tier table
function display_customer_credits_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'customer_credits';

    // Handle search and pagination
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
    $per_page = 10; // Set the number of items per page
    $offset = ($paged - 1) * $per_page;

    // Construct SQL query with search and pagination
    $sql = "
        SELECT ot.user_id, ot.credits, ot.total_earned_credits, u.user_email
        FROM $table_name ot
        INNER JOIN {$wpdb->prefix}users u ON ot.user_id = u.ID
        WHERE u.user_email LIKE %s
        LIMIT %d OFFSET %d
    ";
    $results = $wpdb->get_results($wpdb->prepare($sql, '%' . $search . '%', $per_page, $offset));

    // Get total number of results for pagination calculation
    $total_items_sql = "
        SELECT COUNT(*)
        FROM $table_name ot
        INNER JOIN {$wpdb->prefix}users u ON ot.user_id = u.ID
        WHERE u.user_email LIKE %s
    ";
    $total_items = $wpdb->get_var($wpdb->prepare($total_items_sql, '%' . $search . '%'));
    $total_pages = ceil($total_items / $per_page);

    // Display search form
    echo '<div class="wrap">';
    echo '<h1>Total Credits</h1>';
    echo '<form method="GET">';
    echo '<input type="hidden" name="page" value="customer-credits">';
    echo '<input style="margin-bottom:15px;width:200px;margin-top:20px;" type="search" name="s" value="' . esc_attr($search) . '" placeholder="Search by email...">';
    echo '<input style="margin-top:20px;" type="submit" value="Search" class="button">';
    echo '</form>';

    // Display table
    echo '<table id="customerTable" class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>User ID</th><th>Full Name</th><th>Email</th><th>Credits</th><th>Total Earned Credits</th><th>Actions</th></tr></thead>';
    echo '<tbody>';

    if (!empty($results)) {
        foreach ($results as $row) {
            // Fetch full name using get_userdata
            $user_info = get_userdata($row->user_id);
            $full_name = $user_info ? $user_info->display_name : 'N/A';

            echo '<tr>';
            echo '<td>' . esc_html($row->user_id) . '</td>';
            echo '<td>' . esc_html($full_name) . '</td>';
            echo '<td>' . esc_html($row->user_email) . '</td>';
            echo '<td>' . wc_price($row->credits) . '</td>';
            echo '<td>' . esc_html($row->total_earned_credits) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=customer-credits&user_id=' . $row->user_id)) . '"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye-fill" viewBox="0 0 16 16"><path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8m8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7"/></svg></a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No results found.</td></tr>';
    }

    echo '</tbody></table>';

    // Pagination links
    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links(array(
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => $paged,
            'total'     => $total_pages,
            'prev_text' => __('&laquo; Previous'),
            'next_text' => __('Next &raquo;'),
        ));
        echo '</div></div>';
    }

    echo '</div>';
}






















// Function to display the customer profile page based on the tier
function display_customer_profile_page_credits($user_id) {
    global $wpdb;

    $user_info = get_userdata($user_id);
    $table_name = $wpdb->prefix . 'customer_credits';
    $current_data = $wpdb->get_row($wpdb->prepare("SELECT credits, total_earned_credits, history FROM $table_name WHERE user_id = %d", $user_id));
    $credits = $current_data->credits;
    $total_earned_credits = $current_data->total_earned_credits;
    $history = maybe_unserialize($current_data->history);


    echo '<div class="wrap">';
    
    // Back button
    echo '<a href="' . esc_url(admin_url('admin.php?page=customer-credits')) . '" class="button">Back</a>';

    echo '<h1>Customer Profile</h1>';

    if ($user_info) {
        echo '<table class="form-table">';
        echo '<tr><th>User ID</th><td>' . esc_html($user_info->ID) . '</td></tr>';
        echo '<tr><th>Full Name</th><td>' . esc_html($user_info->display_name) . '</td></tr>';
        echo '<tr><th>Email</th><td>' . esc_html($user_info->user_email) . '</td></tr>';
        echo '<tr><th>Credits</th><td>' . wc_price($credits) . '</td></tr>'; // Display credits as text
        echo '<tr><th>Total Earned Credits</th><td>' . esc_html($total_earned_credits) . '</td></tr>';
        echo '</table>';
        
        // Adjust Balance button
       
            // Show the button only if the current user IS an administrator
            // if ( current_user_can('administrator') ) {
                echo '<button id="adjustBalanceButton" class="button-primary" style="text-align:right;">Adjust Balance</button>';
            // }
      
        
         // Modal HTML with customer name and credit balance
        echo '<div id="adjustBalanceModal" style="display:none;">
        <div style="position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); z-index:1000;">
            <div style="position:relative; width:35%; margin:10% auto; background-color:#fff; padding:20px; border-radius:5px;">
                <h2>Adjust Customer\'s Credit Balance</h2>
                <p><strong>Customer:</strong> ' . esc_html($user_info->display_name) . '</p>
                <p><strong>Current Balance:</strong> ' . wc_price($credits) . '</p>
                <form id="adjustBalanceForm" action="' . esc_url(admin_url('admin-post.php')) . '" method="post">
                    <input type="hidden" name="action" value="update_customer_credits">
                    <input type="hidden" name="user_id" value="' . esc_attr($user_id) . '">
                    <label for="credits">Credit Adjustment:</label><br>
                    <input type="number" name="credits" step="0.01" value="0" required><br>

                    <label for="note">Note (optional):</label><br>
                    <textarea name="note" rows="3" cols="40" placeholder="Add a note..." style="width: 100%;"></textarea>

                    <p>Note: Use a negative sign (-) before credits to deduct credit from the customer\'s balance</p>
                    
                    <div style="display: flex; justify-content: right; margin-top: 20px;gap:10px;">
                        <input type="submit" class="button-primary" value="Submit">
                        <button id="closeModalButton" class="button">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        </div>';

        // JavaScript for modal functionality
        echo '<script>
                document.getElementById("adjustBalanceButton").addEventListener("click", function() {
                    document.getElementById("adjustBalanceModal").style.display = "block";
                });

                document.getElementById("closeModalButton").addEventListener("click", function() {
                    document.getElementById("adjustBalanceModal").style.display = "none";
                });
              </script>';

        if ($history) {
            echo '<h2>Update History</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Date</th><th>Updater</th><th>Old Credit</th><th>New Credit</th><th>Amount Adjusted</th><th>Notes</th></tr></thead>';
            echo '<tbody>';
            foreach ($history as $entry) {
                echo '<tr>';
                echo '<td>' . esc_html($entry['date']) . '</td>';
                echo '<td>' . esc_html($entry['updater']) . '</td>';
                echo '<td>' . wc_price($entry['old_credit']) . '</td>';
                echo '<td>' . wc_price($entry['new_credit']) . '</td>';
                echo '<td>' . wc_price($entry['amount_added']) . '</td>';
                echo '<td>' . esc_html(isset($entry['note']) ? $entry['note'] : ''); // Display the note
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No history found.</p>';
        }
    } else {
        echo '<p>User not found.</p>';
    }

    echo '</div>';
}




add_action('admin_post_update_customer_credits', 'manual_update_customer_credits');

function manual_update_customer_credits() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $user_id = intval($_POST['user_id']);
    $credit_change = floatval($_POST['credits']); // Get the input value which could be positive or negative

    // Ensure that the 'note' key exists in $_POST to avoid the undefined index warning
    $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : ''; // Check if note exists

    global $wpdb;
    $table_name = $wpdb->prefix . 'customer_credits';

    // Get current user's details (admin who is adjusting the credit balance)
    $current_user = wp_get_current_user();
    $updater = $current_user->display_name; // You can also use user_login or other details as needed

    // Get the current credits for the user
    $current_data = $wpdb->get_row($wpdb->prepare(
        "SELECT credits FROM $table_name WHERE user_id = %d",
        $user_id
    ));
    $old_credits = $current_data->credits;

    // Calculate the new credits value
    $new_credits = $old_credits + $credit_change;

    // Only update if there are changes
    if ($old_credits != $new_credits) {
        // Update the credits for the user
        $update_data = ['credits' => $new_credits];
        $wpdb->update($table_name, $update_data, ['user_id' => $user_id]);

        // Log the update, passing the updater's name
        log_customer_credits_update($user_id, $updater, $old_credits, $new_credits, $note);
    }

    wp_redirect(admin_url('admin.php?page=customer-credits&user_id=' . $user_id));
    exit;
}





/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////


// add_action( 'woocommerce_after_cart_totals', 'custom_html_and_css_after_cart_totals_credits' );

// function custom_html_and_css_after_cart_totals_credits() {


// // Call the wishlist shortcode function and store its output in a variable
// $discount_dropdown = do_shortcode('[discount_dropdown]');

// // Check if the output is not empty before displaying it
// if (!empty($discount_dropdown)) {
// // Display the wishlist output within HTML
// echo '<div class="discount-dropdown" style="padding:10px 0px;">' . $discount_dropdown . '</div>';
// } else {
// // Display a message if the wishlist is empty or user is not logged in
// echo '<p>No Credit.</p>';
// }


// }


function get_user_credits_from_db($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'customer_credits';

    $credits = $wpdb->get_var($wpdb->prepare(
        "SELECT credits FROM $table_name WHERE user_id = %d",
        $user_id
    ));

    return $credits;
}

function discount_dropdown_shortcode() {
    // Get the current user
    $current_user = wp_get_current_user();
    
    // Get user's available credit from the custom database table
    $current_credit = get_user_credits_from_db($current_user->ID);
    
    // Initialize the output
    $output = '';

    // Check if the discount fee has already been applied
    $credit_applied = false;
    foreach (WC()->cart->get_fees() as $fee) {
        if ($fee->name === __('Store Credit Discount', 'woocommerce')) {
            $credit_applied = true;
            break;
        }
    }

    // Output the dropdown field if user has credit and fee is not already applied
    if ($current_credit > 0 && !$credit_applied) {
        $output .= '<style>
            .discount_select {
                border: 1px solid black !important;
                border-radius: 50px !important;
                background-color: unset !important;
                padding: 5px 5px !important;
                text-align: center !important;
                color: black !important;
            }
        </style>';
        $output .= '<div id="discount-dropdown" class="px-[25px]">';
        $output .= '<select name="discount_option" class="discount_select" id="discount_option">';
        $output .= '<option value="0">' . __('Select option to use store credit', 'woocommerce') . '</option>';
        $output .= '<option value="' . esc_attr($current_credit) . '">' . sprintf(__('Use Available Credit ($%s)', 'woocommerce'), $current_credit) . '</option>';
        $output .= '</select>';
        $output .= '</div>';
        $output .= '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var discountSelect = document.getElementById("discount_option");
                discountSelect.addEventListener("change", function() {
                    console.log("Selected value:", this.value);
                    // You can add further actions here if needed
                });
            });
        </script>';
    } elseif ($current_credit == 0) {
        $output .= '<style>
            .discount_empty {
                border: 1px solid black !important;
                border-radius: 50px !important;
                background-color: unset !important;
                padding: 10px 10px !important;
                text-align: center !important;
                color: black !important;
            }
        </style>';
        // Output disabled dropdown with appropriate title
        $output .= '<div id="discount-dropdown">';
        $output .= '<select class="discount_empty" disabled>';
        $output .= '<option value="0">' . __('Empty Credit', 'woocommerce') . '</option>';
        $output .= '</select>';
        $output .= '</div>';
    } elseif ($credit_applied) {
        $output .= '<style>
            .discount_select {
                border: 1px solid black !important;
                border-radius: 50px !important;
                background-color: unset !important;
                padding: 10px 10px !important;
                text-align: center !important;
                color: black !important;
            }
        </style>';
        // Output disabled dropdown with appropriate title
        $output .= '<div id="discount-dropdown">';
        $output .= '<select class="discount_select" disabled>';
        $output .= '<option value="0">' . __('Credit Applied', 'woocommerce') . '</option>';
        $output .= '</select>';
        $output .= '</div>';
    }
    
    return $output;
}
// Register the shortcode
add_shortcode('discount_dropdown', 'discount_dropdown_shortcode');

add_action('wp_ajax_apply_discount', 'apply_discount');
add_action('wp_ajax_nopriv_apply_discount', 'apply_discount');

function apply_discount() {
    if (isset($_POST['discount_option']) && $_POST['discount_option'] > 0) {
        // Get user's available credit from the custom database table
        $current_user = wp_get_current_user();
        $current_credit = get_user_credits_from_db($current_user->ID);

        // Get the subtotal of the cart
        $subtotal = WC()->cart->subtotal;

        // Calculate discount based on the subtotal
        $discount_value = min((float) $subtotal, (float) $_POST['discount_option']);

        // Instead of updating the database, store the discount in the session
        WC()->session->set('store_credit_discount', $discount_value);

        // Return success response
        echo json_encode(array('success' => true));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Invalid discount option.'));
    }
    wp_die();
}

add_action('woocommerce_cart_calculate_fees', 'apply_store_credit_fee', 20);

function apply_store_credit_fee($cart) {
    // Product ID to exclude from store credit discount
    $excluded_product_id = 86458;

    // Check if a store credit discount is set in the session
    $discount_value = WC()->session->get('store_credit_discount', 0);

    $discount_value = -9999;

    if ($discount_value > 0) {
        $eligible_cart_total = 0;

        // Calculate the total price of items eligible for the discount
        foreach ($cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] != $excluded_product_id) {
                $eligible_cart_total += $cart_item['line_total'];
            }
        }

        // If there are no eligible products, do not apply the discount
        if ($eligible_cart_total == 0) {
            return;
        }

        // Limit the discount to the eligible products' total
        $applied_discount = min($discount_value, $eligible_cart_total);

        // Add the fee as a negative value (this acts as a discount)
        $cart->add_fee(__('Store Credit Discount', 'woocommerce'), -$applied_discount);
    }
}


function remove_all_fees_outside_cart_checkout() {
    // Check if we are NOT on the cart or checkout page and it's not an AJAX request
    if (!is_cart() && !is_checkout()) {
        // Get the discount value stored in the session
        $discount_value = WC()->session->get('store_credit_discount', 0);

        // If there's a discount stored in the session, proceed to remove it
        if ($discount_value > 0) {
            // Clear the session value to remove the fee
            WC()->session->__unset('store_credit_discount');

            // Ensure WooCommerce recalculates the cart
            WC()->cart->calculate_totals();

            // Debugging
            error_log('Store credit fee removed.');
        }
    }
}

add_action('template_redirect', 'remove_all_fees_outside_cart_checkout');



add_action('wp_footer', 'update_cart_totals_with_js');

function update_cart_totals_with_js() {
    if (is_cart()) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function initDiscountDropdown() {
                    $('#discount_option').change(function () {
                        var discount_option = $(this).val();
                        console.log('Discount option selected:', discount_option); // Add console log here
                        $.ajax({
                            type: 'POST',
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            data: {
                                action: 'apply_discount',
                                discount_option: discount_option
                            },
                            success: function (response) {
                                console.log(response); // Debugging
                                var responseObj = JSON.parse(response);
                                if(responseObj.success) {
                                    console.log('Discount applied successfully.');
                                    // Reload the cart fragments with updated contents
                                    location.reload();
                                } else {
                                    console.log('Error applying discount:', responseObj.message);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.log('Error applying discount:', xhr.responseText);
                            }
                        });
                    });
                }

                // Initialize discount dropdown
                initDiscountDropdown();

                // Reinitialize discount dropdown after cart fragments refresh
                $(document.body).on('updated_cart_totals', function() {
                    console.log('Cart fragments refreshed.'); // Debugging
                    initDiscountDropdown(); // Reinitialize discount dropdown after refresh
                });

                // Refresh cart fragments when quantity is changed
                $('div.woocommerce').on('change', '.quantity input', function () {
                    $('body').trigger('wc_fragment_refresh');
                });
            });
        </script>
        <?php
    }
}