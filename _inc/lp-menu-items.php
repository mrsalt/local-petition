<?php

require_once('lp-signers-admin.php');
require_once('lp-review-messages-admin.php');

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
    add_menu_page(
        __('Route Map', 'textdomain'),
        'Route Map',
        'read',
        'lp-route-map',
        'lp_route_map_redirect',
        plugins_url('local-petition/images/map-icon-28x22.png'),
        null //6
    );
    add_menu_page(
        __('Review Messages', 'textdomain'),
        'Review Messages',
        'moderate_comments',
        'lp-review-messages',
        'lp_review_messages',
        plugins_url('local-petition/images/mail-25x25.png'),
        null //6
    );
}

function lp_admin_bar_menu(WP_Admin_Bar $admin_bar)
{
    if (!is_user_logged_in()) {
        return;
    }

    if (current_user_can('moderate_comments')) {
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

        $table_name = $wpdb->prefix . 'lp_contact_request';
        $query = "SELECT COUNT(*) AS count FROM `$table_name` WHERE status = 'Unread'";
        $result = $wpdb->get_results($query);
        $count = $result[0]->count;

        $admin_bar->add_menu(array(
            'id'    => 'lp-review-messages',
            'parent' => null,
            'group'  => null,
            'title' => 'Unread Messages (' . $count . ')', //you can use img tag with image link. it will show the image icon Instead of the title.
            'href'  => admin_url('admin.php?page=lp-review-messages&Status=Unread'),
            'meta' => [
                'title' => __('Review messages submitted via Contact Us', 'textdomain'), //This title will show on hover
            ]
        ));
    }

    $admin_bar->add_menu(array(
        'id'    => 'lp-route-map-admin-bar',
        'parent' => null,
        'group'  => null,
        'title' => '<img style="top: 4px; position: relative" src="' . plugins_url('local-petition/images/map-icon-28x22.png') . '"/> Route Map', //you can use img tag with image link. it will show the image icon Instead of the title.
        'href'  => esc_url('/west-boise/route-map'),
        'meta' => [
            'title' => __('Go to route map to record visits', 'textdomain'), //This title will show on hover
        ]
    ));
}

function lp_route_map_redirect()
{
    //$page = get_page_by_title('petition-map');
    //wp_redirect(get_permalink($page->ID));
    echo ("<script>window.location = '/west-boise/route-map'</script>");
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
