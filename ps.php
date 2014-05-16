<?php
/*
* Plugin Name: Publishing Stats
* Plugin URI: http://github.com/hacklabr/wp-publishing-stats
* Description: User publishing statistics
* Version: 0.01
* Author: Leo Germani, Vinicius Massuchetto
* Author URI: http://github.com/hacklabr/wp-publishing-stats
*/
class Publishing_Stats {

    var $dir;

    function __construct() {
        load_plugin_textdomain( 'ps', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );

        $this->dir = dirname( __FILE__ );
        $this->url = plugins_url( false, __FILE__ );

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
    }

    function admin_enqueue_scripts() {
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'jquery-ui' );
        wp_enqueue_script( 'jquery-flot', $this->url . '/js/jquery.flot.min.js' );
        wp_enqueue_script( 'jquery-flot-time', $this->url . '/js/jquery.flot.time.min.js' );
        wp_enqueue_script( 'publishing-stats', $this->url . '/js/ps.js', array( 'jquery-flot', 'jquery-flot-time' ), false, true );
    }

    function admin_menu() {
        $page_hook_suffix = add_submenu_page( 'options-general.php', __( 'Publishing Stats', 'ps' ), __( 'Publishing Stats', 'ps' ),
            'manage_options', 'ps', array( $this, 'submenu_page' ) );

        add_action('admin_print_scripts-' . $page_hook_suffix, array( $this, 'admin_enqueue_scripts' ) );
    }

    function submenu_page() {
        
        global $wpdb;

        // Fetching

        $time_ini = !empty( $_GET['time_end'] )
            ? date( 'Y-m-d', strtotime( $_GET['time_ini'] ) )
            : date( 'Y-m-d', current_time( 'timestamp' ) - 3600 * 24 * 30 );
        $time_end = !empty( $_GET['time_ini'] )
            ? date( 'Y-m-d', strtotime( $_GET['time_end'] ) )
            : date( 'Y-m-d', current_time( 'timestamp' ) );
        $time_end_query = date( 'Y-m-d', strtotime( $time_end ) + 24 * 3600 );

        $where = $wpdb->prepare(" WHERE 1=1
            AND post_status = 'publish'
            AND post_date >= '%s'
            AND post_date <= '%s'
        ", $time_ini, $time_end_query );

        $post_types = apply_filters( 'ps_post_types', array( 'post' ) );
        $where .= " AND post_type IN ('" . implode( ',', $post_types ) . "') ";
        
        $sql = "SELECT ID, post_date, post_title, post_author FROM {$wpdb->posts} $where";
        $posts = $wpdb->get_results( $sql );

        // Formating

        $_plotdata = array();
        $userdata = array();
        $totals = array(
            'users' => 0,
            'days' => array(),
            'posts' => 0
        );
        foreach( $posts as $p ) {
            
            $k = strtotime( date( 'Y-m-d', strtotime( $p->post_date . ' GMT' ) ) );
            $_plotdata[ $k ] = !empty( $_plotdata[ $k ] ) ? $_plotdata[ $k ] + 1 : 1;

            // Consider this author in the final statistic or not
            if ( !$author_id = apply_filters( 'ps_author', $p->post_author ) )
                continue;

            if ( empty( $userdata[ $author_id ] ) )
                $userdata[ $author_id ] = array(
                    'display_name' => get_the_author_meta( 'display_name', $author_id ),
                    'posts' => array(),
                    'post_count' => 0
                );
            
            $userdata[ $author_id ]['posts'][] = $p->ID;
            $userdata[ $author_id ]['post_count']++;

            $totals['days'][ $k ] = 1;
            $totals['posts']++;

        }

        $totals['users'] = count( $userdata );
        $totals['days'] = count( $totals['days'] );
        $totals['posts_per_day'] = number_format( $totals['posts'] / $totals['days'], 2 );
        foreach( $userdata as &$u ) {
            $u['posts_per_day'] = number_format( $u['post_count'] / $totals['days'], 2 );
        }
        
        // Sorting

        ksort( $_plotdata );
        usort( $userdata, array( $this, 'userdata_sort' ) );
        foreach( $userdata as $k => $v ) {
            ksort( $v['posts'] );
        }

        // JavaScript conversion

        $plotdata = array();
        foreach( $_plotdata as $k => $v ) {
            $plotdata[] = array($k * 1000, $v);
        }
        $plotdata = json_encode($plotdata);
        
        wp_localize_script( 'publishing-stats', 'ps', array( 'plotdata' => $plotdata ) );

        // Load view

        include( $this->dir . '/ps-stats.php' );
    }

    function userdata_sort( $a, $b ) {
        return $a['display_name'] > $b['display_name'] ? 1 : -1;
    }
    
    function get_user_posts() {
        $ids = explode(',', filter_input(INPUT_GET, 'ids', FILTER_SANITIZE_STRING));
        $html = '';
        
        foreach ($ids as $id) {
            $p = get_post($id);
            $html .= '<li><a href="' . get_permalink( $p->ID ) . '">' . $p->post_title . '</a> (' . $p->post_date . ')</li>';
        }
        
        echo $html;
        die();
    }
}

function ps_init() {
    new Publishing_Stats();
}
add_action( 'plugins_loaded', 'ps_init' );
add_action( 'wp_ajax_ps_get_user_posts', array('Publishing_Stats', 'get_user_posts'));
