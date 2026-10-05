<?php
/** Loaded by the guarded integration runner: real WP hooks, menu renderer and access checks. */
function publion_test_admin_menu( $early_child = false ) {
    foreach ( array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_menu_nopriv', '_wp_submenu_nopriv' ) as $name ) { $GLOBALS[ $name ] = array(); }
    $GLOBALS['pagenow'] = 'admin.php';
    $GLOBALS['plugin_page'] = 'publion';
    $GLOBALS['parent_file'] = '';
    $GLOBALS['submenu_file'] = '';
    $GLOBALS['typenow'] = '';
    $_SERVER['PHP_SELF'] = '/wp-admin/admin.php';
    if ( $early_child ) { add_submenu_page( 'publion', 'Other extension', 'Other extension', 'manage_options', 'other-extension', '__return_null' ); }
    do_action( 'admin_menu', '' );
    $old_directory = getcwd(); chdir( ABSPATH . 'wp-admin' );
    ob_start();
    if ( ! function_exists( '_wp_menu_output' ) ) { require ABSPATH . 'wp-admin/menu-header.php'; }
    else { _wp_menu_output( $GLOBALS['menu'], $GLOBALS['submenu'] ); }
    $output = ob_get_clean(); chdir( $old_directory );
    if ( current_user_can( 'manage_options' ) ) { file_put_contents( sys_get_temp_dir() . '/publion-menu.html', $output ); }
    return $output;
}
test( 'admin_menu registers dashboard first and correct WordPress-generated navigation', function () {
    reset_case();
    $html = publion_test_admin_menu();
    check( 'publion' === $GLOBALS['submenu']['publion'][0][2], 'Dashboard missing/first child hijacked parent' );
    check( false !== strpos( $html, "href='admin.php?page=publion'" ), 'Main/dashboard link is not canonical WordPress admin route' );
    check( false !== strpos( $html, "href='admin.php?page=publion-diagnostics'" ), 'Diagnose link is not canonical WordPress admin route' );
    check( false === strpos( $html, "href='publion-diagnostics'" ), 'Raw submenu slug leaked as a file path' );
    check( has_action( 'toplevel_page_publion' ) && has_action( 'publion_page_publion-diagnostics', 'publion_render_diagnostics' ), 'Correct page callbacks not registered' );
    check( ! has_action( 'admin_page_publion-diagnostics', 'publion_render_diagnostics' ), 'Premature orphan diagnose callback remains' );
    check( isset( $GLOBALS['_registered_pages']['toplevel_page_publion'], $GLOBALS['_registered_pages']['publion_page_publion-diagnostics'] ), 'Direct plugin pages not registered' );
} );
test( 'admin_menu withstands earlier third-party child and repeated registration', function () {
    reset_case();
    $html = publion_test_admin_menu( true );
    check( 'publion' === $GLOBALS['submenu']['publion'][0][2], 'Early child redirects main menu' );
    check( false !== strpos( $html, "href='admin.php?page=publion-diagnostics'" ), 'Parent-derived diagnose hook failed' );
    $html = publion_test_admin_menu();
    check( 2 === count( $GLOBALS['submenu']['publion'] ), 'Repeated admin request lost/duplicated menus' );
} );
test( 'direct registered admin URLs render dashboard settings generation and diagnose', function () {
    reset_case(); publion_test_admin_menu();
    foreach ( array( 'publion', 'publion-diagnostics' ) as $slug ) {
        $GLOBALS['parent_file'] = ''; $GLOBALS['plugin_page'] = $slug;
        check( user_can_access_admin_page(), 'Admin cannot access direct page ' . $slug );
        $hook = get_plugin_page_hookname( $slug, 'publion' );
        ob_start(); do_action( $hook ); $html = ob_get_clean();
        check( strlen( $html ) > 300, 'Registered callback did not render ' . $slug );
        if ( 'publion' === $slug ) {
            check( false !== strpos( $html, 'publion-api-key-status' ) && false !== strpos( $html, 'publion-generate' ), 'Dashboard settings/generation tabs disappeared' );
        } else { check( false !== strpos( $html, 'publion_safety_action' ), 'Diagnose review controls disappeared' ); }
    }
} );
test( 'unauthorized role cannot navigate or call either admin page', function () {
    reset_case();
    $user = wp_insert_user( array( 'user_login' => 'publion_menu_subscriber', 'user_pass' => 'isolated-only', 'role' => 'subscriber' ) );
    if ( is_wp_error( $user ) ) { $user = get_user_by( 'login', 'publion_menu_subscriber' )->ID; }
    wp_set_current_user( $user ); publion_test_admin_menu();
    foreach ( array( 'publion', 'publion-diagnostics' ) as $slug ) {
        $GLOBALS['plugin_page'] = $slug; $GLOBALS['parent_file'] = '';
        check( ! user_can_access_admin_page(), 'Subscriber can access ' . $slug );
    }
    $denied = 0;
    foreach ( array( array( new Publion_Admin(), 'render_admin_page' ), 'publion_render_diagnostics' ) as $callback ) {
        try { call_user_func( $callback ); } catch ( Publion_Test_Die $e ) { $denied++; }
    }
    check( 2 === $denied, 'Direct callback bypassed capability check' );
    wp_set_current_user( get_user_by( 'login', 'publion_test_admin' )->ID );
} );
