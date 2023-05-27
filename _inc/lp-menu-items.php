<?php

require_once('lp-signers-admin.php');

function lp_admin_menu()
{
    if (!is_user_logged_in()) {
        return;
    }
    add_menu_page(
        __('Review Signers', 'textdomain'),
        'Review Signers',
        'moderate_comments',
        'lp-review-signers',
        'lp_review_signers',
        '', //plugins_url( 'myplugin/images/icon.png' ),
        null //6
    );
}

function lp_admin_bar_menu(WP_Admin_Bar $admin_bar)
{
    if (!is_user_logged_in()) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_signer';
    $query = "SELECT COUNT(*) AS count FROM `$table_name` WHERE status = 'Unreviewed'";
    $result = $wpdb->get_results($query);
    $count = $result[0]->count;

    $admin_bar->add_menu(array(
        'id'    => 'lp-review-new-signers',
        'parent' => null,
        'group'  => null,
        'title' => 'Review New Signers (' . $count . ')', //you can use img tag with image link. it will show the image icon Instead of the title.
        'href'  => admin_url('admin.php?page=lp-review-signers&Status=Unreviewed'),
        'meta' => [
            'title' => __('Approve new signers so they will become visible on the site', 'textdomain'), //This title will show on hover
        ]
    ));
}

/*
function add_menus() {
    // Check if the menu exists
    $menu_name   = 'My First Menu';
    $menu_exists = wp_get_nav_menu_object( $menu_name );

    // If it doesn't exist, let's create it.
    if ( ! $menu_exists ) {
        $menu_id = wp_create_nav_menu($menu_name);

        // Set up default menu items
        wp_update_nav_menu_item( $menu_id, 0, array(
            'menu-item-title'   =>  __( 'Home', 'textdomain' ),
            'menu-item-classes' => 'home',
            'menu-item-url'     => home_url( '/' ), 
            'menu-item-status'  => 'publish'
        ) );

        wp_update_nav_menu_item( $menu_id, 0, array(
            'menu-item-title'  =>  __( 'Custom Page', 'textdomain' ),
            'menu-item-url'    => home_url( '/custom/' ), 
            'menu-item-status' => 'publish'
        ) );
    }    
}*/
