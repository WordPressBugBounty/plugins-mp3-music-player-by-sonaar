<?php

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

class SR_post_Search_Ajax {

    private static $instance = null;

    /**
     * Singleton pattern to prevent multiple instances.
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('cmb2_render_sr_post_search_ajax', [$this, 'render_field'], 10, 5);
        add_action('wp_ajax_sr_post_search', [$this, 'ajax_search']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    
    }

    /**
     * Enqueue necessary scripts and styles.
     */
    public function enqueue_scripts($hook) {
        // Check if we're editing a post of type 'sr_advanced_trigger'
        global $post;
    
        if ($hook === 'post.php' || $hook === 'post-new.php') {

            // Ensure the post is loaded
            /*$post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
            $post = $post_id ? get_post($post_id) : null;*/
    
            if ($post->post_type === 'sr_advanced_triggers') {

                wp_enqueue_script( 'select2',  plugin_dir_url( __DIR__ ) . '../js/select2.min.js', array( 'jquery' ), '4.1.0', true);
                wp_enqueue_style( 'select2', plugin_dir_url( __DIR__ ) . '../css/select2.min.css' );
                wp_enqueue_script('sr-post-search-ajax-field', plugin_dir_url(__FILE__) . 'sr-post-search-ajax.js', ['jquery', 'select2'], SRMP3_VERSION, true);
    
                wp_localize_script('sr-post-search-ajax-field', 'SR_Select2_Ajax', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('sr_post_search'),
                ]);
            }
        }
    }
    

    /**
     * Render the custom CMB2 field.
     */
    public function render_field($field, $escaped_value, $object_id, $object_type, $field_type) {
        $post_type = $field->args('post_type');
        $select_behavior = $field->args('select_behavior'); // 'replace' or 'add'
        $multiple = $select_behavior === 'add' ? 'multiple' : '';
        $required = '';
        if (isset($field->args['attributes']['required']) && $field->args['attributes']['required'] === 
        'required') {
            $required = 'required';
        }
        // Retrieve the meta query, if available
        $meta_query = $field->args('meta_query') ?? [];
    
        $conditional_attrs = '';
        if (isset($field->args['attributes']['data-conditional'])) {
            $conditional_attrs .= ' data-conditional="' . esc_attr($field->args['attributes']['data-conditional']) . '"';
        } else {
            // Support for older conditional attributes
            if (isset($field->args['attributes']['data-conditional-id'])) {
                $conditional_attrs .= ' data-conditional-id="' . esc_attr($field->args['attributes']['data-conditional-id']) . '"';
            }
            if (isset($field->args['attributes']['data-conditional-value'])) {
                $conditional_attrs .= ' data-conditional-value="' . esc_attr($field->args['attributes']['data-conditional-value']) . '"';
            }
        }
        
        echo '<select 
                class="sr-post-search-ajax" 
                name="' . esc_attr($field_type->_name()) . ($multiple ? '[]' : '') . '" 
                ' . $multiple . ' 
                style="width:100%;" 
                data-post-type="' . esc_attr(json_encode($post_type)) . '" 
                data-select-behavior="' . esc_attr($select_behavior) . '" 
                data-meta-query="' . esc_attr(json_encode($meta_query)) . '" 
                ' . $conditional_attrs . ' 
                ' . $required . '>';
        
        // Pre-populate the selected value(s)
        if (!empty($escaped_value)) {
            if (is_array($escaped_value)) {
                foreach ($escaped_value as $value) {
                    $post_title = get_the_title($value);
                    echo '<option value="' . esc_attr($value) . '" selected>' . esc_html($post_title) . '</option>';
                }
            } else {
                $post_title = get_the_title($escaped_value);
                echo '<option value="' . esc_attr($escaped_value) . '" selected>' . esc_html($post_title) . '</option>';
            }
        }
    
        echo '</select>';
        $field_type->_desc(true); // Display field description if available
    }
    
    
    
    
    

    /**
     * Handle the AJAX request to search for posts.
     */
    public function ajax_search() {
        check_ajax_referer('sr_post_search', 'nonce');
    
        $post_types = isset($_GET['post_type']) ? (array) $_GET['post_type'] : ['post'];
        $post_types = array_map('sanitize_text_field', $post_types);
    
        $search_term = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        $meta_query = isset($_GET['meta_query']) ? json_decode(stripslashes($_GET['meta_query']), true) : [];
    
        $results = [];
    
        // If search term is numeric, try finding by exact post ID first
        if (is_numeric($search_term)) {
            $id_query = new WP_Query([
                'post_type'      => $post_types,
                'posts_per_page' => 10,
                'post__in'       => [(int) $search_term], // Directly match by post ID
                'meta_query'     => $meta_query,
                'post_status'    => 'publish',
            ]);
    
            if ($id_query->have_posts()) {
                while ($id_query->have_posts()) {
                    $id_query->the_post();
                    $results[] = [
                        'id'   => get_the_ID(),
                        'text' => get_the_title(),
                    ];
                }
            }
            wp_reset_postdata();
        }
    
        // Second query to search by title for partial matches
        $title_query = new WP_Query([
            'post_type'      => $post_types,
            'posts_per_page' => 10,
            's'              => $search_term, // Search within title/content
            'meta_query'     => $meta_query,
            'post_status'    => 'publish',
        ]);
    
        if ($title_query->have_posts()) {
            while ($title_query->have_posts()) {
                $title_query->the_post();
                // Avoid duplicates if already added by ID
                if (!array_search(get_the_ID(), array_column($results, 'id'))) {
                    $results[] = [
                        'id'   => get_the_ID(),
                        'text' => get_the_title(),
                    ];
                }
            }
        }
    
        wp_reset_postdata();
        wp_send_json($results);
    }
    
    
    
    
    
}

// Initialize the class.
SR_post_Search_Ajax::instance();
