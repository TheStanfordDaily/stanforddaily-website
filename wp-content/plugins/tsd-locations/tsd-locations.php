<?php
/**
* Plugin Name: The Stanford Daily - Locations
* Description: Add locations endpoint onto REST API, which can be consumed by our mobile app.
* Author: The Stanford Daily Tech Team
* Version: 1.0
* History:
* 1.0 (2019-01-21) - Created. (Yifei He)
*/

// Custom WP API endpoint
function tsd_locations_plugin_enable_api() {
    // Ref: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/

    // Create json-api endpoint
    add_action('rest_api_init', function () {
        // Match "/locations/{name}"
        register_rest_route('tsd/v1', '/locations/(?P<name>[\w\d_.-]+)', [
            'methods' => 'GET',
            'callback' => 'tsd_locations_plugin_return_location_info',
            'permission_callback' => function (WP_REST_Request $request) {
                return true;
            }
        ]);

        // Match "/locations"
        register_rest_route('tsd/v1', '/locations', [
            'methods' => 'GET',
            'callback' => 'tsd_locations_plugin_return_locations_list',
            'permission_callback' => function (WP_REST_Request $request) {
                return true;
            }
        ]);

    });

    function tsd_locations_plugin_get_locations() {
        $locations_string = file_get_contents( __DIR__ . "/locations.json" );
        $locations = json_decode( $locations_string, true );
        //print_r($locations);
        return $locations;
    }

    // Handle the "/locations/{name}" request
    function tsd_locations_plugin_return_location_info( $request ) {
        $locations = tsd_locations_plugin_get_locations();
        $location_key = $request[ 'name' ];

        if ( ! array_key_exists( $location_key, $locations ) ) {
            return new WP_Error( 'no_location', 'Invalid location', ['status' => 404] );
        }

        $location_info = $locations[ $location_key ];
        //print_r($location_info);

        $all_tag_slugs = [];
        foreach ( $location_info[ "tag-name" ] as $each_name ) {
            $tag_info = get_term_by( 'name', $each_name, 'post_tag' );
            if ( !empty( $tag_info ) ) {
                $tag_slug = $tag_info->slug;
                $all_tag_slugs[] = $tag_slug;
            }
        }
        // TODO: Add cache?
        $all_articles = get_posts( [
            'tag' => implode( ",", $all_tag_slugs ),
            'numberposts' => -1
        ] );
        //echo $location_key.count($all_articles)."\n";
        return $all_articles;
    }

    // Handle the "/locations" request
    function tsd_locations_plugin_return_locations_list( $request ) {
        $locations = tsd_locations_plugin_get_locations();

        $location_keys = [];
        foreach ( $locations as $each_location_key => $each_location_info ) {
            $results_info = $each_location_info;

            $post_count = 0;
            foreach ( $each_location_info[ "tag-name" ] as $each_name ) {
                $tag_info = get_term_by( 'name', $each_name, 'post_tag' );
                if ( !empty( $tag_info ) ) {
                    $post_count += $tag_info->count;
                }
            }
            $results_info[ "count" ] = $post_count;

            $location_keys[ $each_location_key ] = $results_info;
        }
        return $location_keys;
    }
}
add_action('init', 'tsd_locations_plugin_enable_api');
