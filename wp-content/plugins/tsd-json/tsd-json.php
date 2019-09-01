<?php
/**
* Plugin Name: The Stanford Daily - JSON
* Description: Add related endpoint onto REST API, which can be consumed by our React app.
* Author: The Stanford Daily Tech Team
* Version: 1.0
* History:
* 1.0 (2019-09-01) - Created. (Yifei He)
*/

// Custom WP API endpoint
function tsd_json_plugin_enable_api() {
    // Ref: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/

    // Create json-api endpoint
    add_action('rest_api_init', function () {
        // Match "/json/home"
        register_rest_route('tsd/v1', '/json/home', [
            'methods' => 'GET',
            'callback' => 'tsd_json_plugin_return_home',
        ]);

        // Match "/json/post/<slug>"
        register_rest_route('tsd/v1', '/json/post/(?P<slug>[\w-]+)', [
            'methods' => 'GET',
            'callback' => 'tsd_json_plugin_return_post',
        ]);
    });

    function tsd_json_plugin_query_not_featured_posts( $query ) {
        $featured_category = get_category_by_slug('featured');
        $featured_category_id = $featured_category->term_id;

        $query[ 'category__not_in' ] = [ $featured_category_id ];
        return query_posts( $query );
    }

    // Returns an array of author.
    function tsd_json_plugin_get_author_info( $post ) {
        $author_objects = [];
        if ( function_exists( 'get_coauthors' ) ) {
            $author_objects = get_coauthors( $post->ID );
        } else {
            $author_objects[] = get_user_meta( $post->post_author );
        }

        $authors = [];
        foreach ($author_objects as $author) {
            $authors[] = [
                'id' => $author->ID,
                'display_name' => html_entity_decode( $author->display_name ),
                'user_nicename' => $author->user_nicename,
            ];
        };
        return $authors;
    }

    // Returns a single category info.
    function tsd_json_plugin_get_category_info( $post ) {
        $primary_category_id = get_post_meta( $post->ID, '_yoast_wpseo_primary_category', true );
        $primary_category = null;
        if ( empty( $primary_category_id ) ) {
            $main_categories = [ 'featured', 'NEWS', 'SPORTS', 'opinions', 'thegrind', 'arts-life' ];
            foreach ( $main_categories as $main_category ) {
                if ( in_category( $main_category, $post ) ) {
                    $primary_category = get_category_by_slug( $main_category );
                    break;
                }
            }
            if ( is_null( $primary_category ) ) {
                // If the post is not in any `$main_categories`, simply get the first post category.
                $post_categories = get_the_category( $post->ID );
                if ( count( $post_categories ) > 0 ) {
                    $primary_category = $post_categories[0];
                }
            }
        } else {
            $primary_category = get_the_category_by_ID( $primary_category_id );
        }

        if ( ! is_null( $primary_category ) ) {
            return [
                'id' => $primary_category->term_id,
                'name' => html_entity_decode( $primary_category->name ),
                'slug' => $primary_category->slug,
            ];
        } else {
            return null;
        }
    }

    function tsd_json_plugin_get_processed_posts( $query, $options = [] ) {
        $defaults_options = array(
            'include_post_content' => false,
            'exclude_featured_category' => false,
            'include_category_info_for_each_post' => false,
        );
        $options = wp_parse_args( $options, $defaults_options );

        $post_objects = null;
        if ( $options[ 'exclude_featured_category' ] ) {
            $post_objects = tsd_json_plugin_query_not_featured_posts( $query );
        } else {
            $post_objects = query_posts( $query );
        }

        $posts = [];
        foreach ( $post_objects as $post_object ) {
            $post = $post_object->to_array();
            $post[ 'post_title' ] = html_entity_decode( apply_filters( 'the_title', $post[ 'post_title' ] ) );
            $post[ 'post_excerpt' ] = html_entity_decode( $post[ 'post_excerpt' ] );
            if ( ! $options[ 'include_post_content' ] ) {
                unset( $post[ 'post_content' ] );
                unset( $post[ 'post_content_filtered' ] );
            } else {
                // https://codex.wordpress.org/Class_Reference/WP_Post#Accessing_the_WP_Post_Object
                $post[ 'post_content' ] = apply_filters( 'the_content', $post[ 'post_content' ] );
            }

            foreach( $post[ 'tags_input' ] as $key => $tag ) {
                $post[ 'tags_input' ][ $key ] = html_entity_decode( $tag );
            }

            if ( function_exists( 'get_the_subtitle' ) ) {
                $post[ 'post_subtitle' ] = html_entity_decode( get_the_subtitle( $post_object, '', '', false ) );
            }

            $post[ 'tsd_authors' ] = tsd_json_plugin_get_author_info( $post_object );

            if ( $options[ 'include_category_info_for_each_post' ] ) {
                $category_info = tsd_json_plugin_get_category_info( $post_object );
                if ( ! is_null( $category_info ) ) {
                    $post[ 'tsd_primary_category' ] = $category_info;
                }
            }

            $posts[] = $post;
        }
        return $posts;
    }

    function tsd_json_plugin_return_home() {
        $sections = [];
        $sections[ 'featured' ] = tsd_json_plugin_get_processed_posts( [ 'category_name' => 'featured', 'posts_per_page' => 3 ] );
        $sections[ 'news' ] = tsd_json_plugin_get_processed_posts( [ 'category_name' => 'NEWS', 'posts_per_page' => 4 ], [ 'exclude_featured_category' => true ] );
        $sections[ 'sports' ] = tsd_json_plugin_get_processed_posts( [ 'category_name' => 'SPORTS', 'posts_per_page' => 7 ], [ 'exclude_featured_category' => true ] );
        $sections[ 'opinions' ] = tsd_json_plugin_get_processed_posts( [ 'category_name' => 'opinions', 'posts_per_page' => 4 ], [ 'exclude_featured_category' => true ] );
        $sections[ 'the_grind' ] = tsd_json_plugin_get_processed_posts( [ 'category_name' => 'thegrind', 'posts_per_page' => 4 ], [ 'exclude_featured_category' => true ] );
        $sections[ 'arts_and_life' ] = tsd_json_plugin_get_processed_posts( [ 'category_name' => 'arts-life', 'posts_per_page' => 4 ], [ 'exclude_featured_category' => true ] );

        $main_posts_id = [];
        foreach ($sections as $section) {
            foreach ($section as $post) {
                $main_posts_id[] = $post[ 'ID' ];
            }
        }

        $sections[ 'more_from_the_daily' ] = tsd_json_plugin_get_processed_posts( [ 'post__not_in' => $main_posts_id, 'posts_per_page' => 20 ], [ 'include_category_info_for_each_post' => true ] );

        return $sections;
    }

    function tsd_json_plugin_return_post( $request ) {
        $slug = (string) $request[ "slug" ];

        $posts = tsd_json_plugin_get_processed_posts( [ 'name' => $slug ], [ 'include_post_content' => true, 'include_category_info_for_each_post' => true ] );
        if ( empty( $posts ) ) {
            return new WP_Error( 'post_not_found', 'This post slug is not associated with any post.', ['status' => 404] );
        }
        if ( count( $posts ) > 1 ) {
            return new WP_Error( 'more_than_one_post_found', 'The same slug returns more than one post!', ['status' => 500] );
        }

        return $posts[0];
    }
}
add_action('init', 'tsd_json_plugin_enable_api');