<?php

class WC_Create_Products_With_Variations_And_Attributes
{
    public function create() {

        $products = [
            ['NEW YORK', 'NAVY', 992170,   'S - M - L - XL - XXL - 3XL',  'Giubbino', 'Giubbino imbottito in soft',60],
            ['NEW YORK', 'BLACK', 992172,   'S - M - L - XL - XXL - 3XL',  'Giubbino', 'Giubbino imbottito in soft',60],
            ['CERVINIA MAN', 'RED', 992173,   'S - M - L - XL - XXL - 3XL',  'Giubbino', 'Giubbino in soft shell',60],
        ];

        foreach ($products as $procuct):

            $name = $procuct[0]; // product title
            $color = $procuct[1];
            $code = $procuct[2]; // sku
            $sizes = $procuct[3];
            $category = $procuct[4];
            $desc = $procuct[5];
            $price = $procuct[6];

            $arraySizes = explode(' - ', $sizes);


            $attributes_data = array(
                'author' => '', // optional
                'title' => $name,
                'content' => $desc,
                'excerpt' => '',
                'regular_price' => $price, // product regular price
                'sale_price' => '', // product sale price (optional)
                'stock' => 9999999, // Set a minimal stock quantity
                'image_id' => '', // optional
                'gallery_ids' => array(), // optional
                'sku' => $code, // optional
                'tax_class' => '', // optional
                'weight' => '', // optional
                'attributes' => array(
                    'Size' => $arraySizes,  //size
                    'Color' => array($color), //color
                ),
            );

            /*
             * check if product exists
             */
            $wp_product = get_posts(array(
                'numberposts' => 1,
                'title' => $name,
                'post_type' => 'product',
                'post_status' => 'publish',
            ));


            if ( empty($wp_product) ) {
                $product_id = $this->create_product_attributes($attributes_data);
            } else {
                $product_id = $wp_product[0]->ID;
                $this->create_attributes($product_id, $attributes_data['attributes']);
            }

            wp_set_object_terms($product_id, $this->getProductCategoryIDByName($category), 'product_cat');
            //meta data

            foreach ($arraySizes as $key => $val):
                $inc = $key + 1;
                $sku = $code . '-' . $inc;
                // The variation data
                $variation_data = array(
                    'attributes' => array(
                        'size' => $val,   //size
                        'color' => $color, //color
                    ),
                    'sku' => $sku,
                    'regular_price' => $price,
                    'sale_price' => $price,
                    'stock_qty' => 9999999,
                );
                $this->create_product_variation($product_id, $variation_data);

            endforeach;
        endforeach;
    }

    /**
     * Create a new variable product (with new attributes if they are).
     * (Needed functions:
     *
     * @param array $data | The data to insert in the product.
     * @since 3.0.0
     */

    public function create_product_attributes($data) {
        $postname = sanitize_title($data['title']);
        $author = empty($data['author']) ? '1' : $data['author'];

        $post_data = array(
            'post_author' => $author,
            'post_name' => $postname,
            'post_title' => $data['title'],
            'post_content' => $data['content'],
            'post_excerpt' => $data['excerpt'],
            'post_status' => 'publish',
            'ping_status' => 'closed',
            'post_type' => 'product',
            'guid' => home_url('/product/' . $postname . '/'),
        );

        // Creating the product (post data)
        $product_id = wp_insert_post($post_data);

        // Get an instance of the WC_Product_Variable object and save it
        $product = new \WC_Product_Variable($product_id);
        $product->save();


        ## ---------------------- Other optional data  ---------------------- ##
        ##     (see WC_Product and WC_Product_Variable setters methods)

        // THE PRICES (No prices yet as we need to create product variations)

        // IMAGES GALLERY
        if ( !empty($data['gallery_ids']) && count($data['gallery_ids']) > 0 )
            $product->set_gallery_image_ids($data['gallery_ids']);

        // SKU
        if ( !empty($data['sku']) ) {
            try {
                $product->set_sku($data['sku']);
            } catch (\Exception $exception) {
                $new_sku = $data['sku'] . '-' . rand(1, 10000);
                $product->set_sku($new_sku);
            }
        }


        // STOCK (stock will be managed in variations)
        $product->set_stock_quantity($data['stock']); // Set a minimal stock quantity
        $product->set_manage_stock(true);
        $product->set_stock_status('instock');

        // Tax class
        if ( empty($data['tax_class']) )
            $product->set_tax_class($data['tax_class']);

        $product->validate_props(); // Check validation

        ## ---------------------- VARIATION ATTRIBUTES ---------------------- ##
        $this->create_attributes($product_id, $data['attributes'], false);

        $product->save();

        return $product_id;
    }

    public function create_attributes($product_id, $attributes = [], $merge = true) {
        $product_attributes = array();

        foreach ($attributes as $key => $terms) {
            $taxonomy = wc_attribute_taxonomy_name($key); // The taxonomy slug
            $attr_label = ucfirst($key); // attribute label name
            $attr_name = (wc_sanitize_taxonomy_name($key)); // attribute slug

            // NEW Attributes: Register and save them
            if ( !taxonomy_exists($taxonomy) )
                $this->save_product_attribute_from_name($attr_name, $attr_label);

            $product_attributes[$taxonomy] = array(
                'name' => $taxonomy,
                'value' => '',
                'position' => '',
                'is_visible' => 0,
                'is_variation' => 1,
                'is_taxonomy' => 1
            );

            foreach ($terms as $value) {
                $term_name = ucfirst($value);
                $term_slug = sanitize_title($value);

                // Check if the Term name exist and if not we create it.
                if ( !term_exists($value, $taxonomy) )
                    wp_insert_term($term_name, $taxonomy, array('slug' => $term_slug)); // Create the term

                // Set attribute values
                wp_set_post_terms($product_id, $term_name, $taxonomy, true);
            }
        }
        if ( $merge ) {
            $present_attributes = get_post_meta($product_id, '_product_attributes', true);
            update_post_meta($product_id, '_product_attributes', array_merge($product_attributes, $present_attributes));
        } else {
            update_post_meta($product_id, '_product_attributes', $product_attributes);
        }

    }

    /**
     * Save a new product attribute from his name (slug).
     *
     * @param string $name | The product attribute name (slug).
     * @param string $label | The product attribute label (name).
     * @since 3.0.0
     */
    public function save_product_attribute_from_name($name, $label = '', $set = true) {

        global $wpdb;

        $label = $label == '' ? ucfirst($name) : $label;
        $attribute_id = $this->get_attribute_id_from_name($name);

        if ( empty($attribute_id) ) {
            $attribute_id = NULL;
        } else {
            $set = false;
        }
        $args = array(
            'attribute_id' => $attribute_id,
            'attribute_name' => $name,
            'attribute_label' => $label,
            'attribute_type' => 'select',
            'attribute_orderby' => 'menu_order',
            'attribute_public' => 0,
        );


        if ( empty($attribute_id) ) {
            $wpdb->insert("{$wpdb->prefix}woocommerce_attribute_taxonomies", $args);
            set_transient('wc_attribute_taxonomies', false);
        }

        if ( $set ) {
            $attributes = wc_get_attribute_taxonomies();
            $args['attribute_id'] = $this->get_attribute_id_from_name($name);
            $attributes[] = (object)$args;
            set_transient('wc_attribute_taxonomies', $attributes);
        } else {
            return;
        }
    }

    /**
     * Get the product attribute ID from the name.
     *
     * @param string $name | The name (slug).
     * @since 3.0.0
     */
    public function get_attribute_id_from_name($name) {
        global $wpdb;
        $attribute_id = $wpdb->get_col("SELECT attribute_id
        FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
        WHERE attribute_name LIKE '$name'");
        return reset($attribute_id);
    }

    public function getProductCategoryIDByName($prod_cat) {

        if ( !term_exists($prod_cat, 'product_cat') ) {

            $term = wp_insert_term($prod_cat, 'product_cat');

            if ( is_wp_error($term) ) {
                return $term->error_data['term_exists'] ?? null;
            }
            return $term['term_id'];

        }

        $term_s = get_term_by('name', $prod_cat, 'product_cat');

        return $term_s->term_id ?? null;


    }

    /**
     * Create a product variation for a defined variable product ID.
     *
     * @param int $product_id | Post ID of the product parent variable product.
     * @param array $variation_data | The data to insert in the product.
     * @since 3.0.0
     */

    public function create_product_variation($product_id, $variation_data) {
        // Get the Variable product object (parent)
        $product = wc_get_product($product_id);

        $variation_post = array(
            'post_title' => $product->get_name(),
            'post_name' => 'product-' . $product_id . '-variation',
            'post_status' => 'publish',
            'post_parent' => $product_id,
            'post_type' => 'product_variation',
            'guid' => $product->get_permalink()
        );

        // Creating the product variation
        $variation_id = wp_insert_post($variation_post);

        // Get an instance of the WC_Product_Variation object
        $variation = new \WC_Product_Variation($variation_id);

        // Iterating through the variations attributes
        foreach ($variation_data['attributes'] as $attribute => $term_name) {
            $taxonomy = 'pa_' . $attribute; // The attribute taxonomy

            // If taxonomy doesn't exists we create it
            if ( !taxonomy_exists($taxonomy) ) {
                register_taxonomy(
                    $taxonomy,
                    'product_variation',
                    array(
                        'hierarchical' => false,
                        'label' => ucfirst($attribute),
                        'query_var' => true,
                        'rewrite' => array('slug' => sanitize_title($attribute)), // The base slug
                    )
                );
            }

            // Check if the Term name exist and if not we create it.
            if ( !term_exists($term_name, $taxonomy) )
                wp_insert_term($term_name, $taxonomy); // Create the term

            $term_slug = get_term_by('name', $term_name, $taxonomy)->slug; // Get the term slug

            // Get the post Terms names from the parent variable product.
            $post_term_names = wp_get_post_terms($product_id, $taxonomy, array('fields' => 'names'));

            // Check if the post term exist and if not we set it in the parent variable product.
            if ( !in_array($term_name, $post_term_names) )
                wp_set_post_terms($product_id, $term_name, $taxonomy, true);

            // Set/save the attribute data in the product variation
            update_post_meta($variation_id, 'attribute_' . $taxonomy, $term_slug);
        }

        ## Set/save all other data

        // SKU
        if ( !empty($variation_data['sku']) ) {
            try {
                $variation->set_sku($variation_data['sku']);
            } catch (\Exception $exception) {
                $new_sku = $variation_data['sku'] . '-' . rand(1, 10000);
                $variation->set_sku($new_sku);
            }

        }


        // Prices
        if ( empty($variation_data['sale_price']) ) {
            $variation->set_price($variation_data['regular_price']);
        } else {
            $variation->set_price($variation_data['sale_price']);
            $variation->set_sale_price($variation_data['sale_price']);
        }
        $variation->set_regular_price($variation_data['regular_price']);

        // Stock
        if ( !empty($variation_data['stock_qty']) ) {
            $variation->set_stock_quantity($variation_data['stock_qty']);
            $variation->set_manage_stock(true);
            $variation->set_stock_status('');
        } else {
            $variation->set_manage_stock(false);
        }

        $variation->set_weight(''); // weight (reseting)

        $variation->save(); // Save the data
    }
}

(new WC_Create_Products_With_Variations_And_Attributes())->create();
