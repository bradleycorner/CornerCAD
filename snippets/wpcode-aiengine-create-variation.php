/**
 * CornerCAD — AI Engine tool: create a variation on a variable product.
 *
 * Registers `cornercad_create_product_variation` with AI Engine's function
 * list. Mirrors WPCode snippet 90 ("product_variation_snippit") as live on
 * cornercad.com; copied verbatim from production 2026-09-25.
 */

add_filter( 'mwai_functions', function( $tools ) {
    $tools[] = [
        'name' => 'cornercad_create_product_variation',
        'description' => 'Creates a specific variation for a parent variable product id with price and dimensions.',
        'callback' => 'cornercad_handle_variation_creation',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'parent_id'  => [ 'type' => 'integer', 'description' => 'The parent variable product ID' ],
                'price'      => [ 'type' => 'string', 'description' => 'Regular price for this variation' ],
                'attributes' => [ 'type' => 'object', 'description' => 'Key-value pairs of the attributes (e.g. {"material": "basswood", "shape": "circle"})' ]
            ],
            'required' => [ 'parent_id', 'price', 'attributes' ]
        ]
    ];
    return $tools;
} );

function cornercad_handle_variation_creation( $params ) {
    $parent_id = $params['parent_id'];
    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent_id );
    
    // Format and assign the custom attributes matrix
    $meta_attributes = [];
    foreach ( $params['attributes'] as $key => $value ) {
        $meta_attributes[ 'attribute_pa_' . wc_sanitize_taxonomy_name( $key ) ] = sanitize_title( $value );
    }
    
    $variation->set_attributes( $meta_attributes );
    $variation->set_regular_price( $params['price'] );
    $variation->set_status( 'publish' );
    $variation_id = $variation->save();
    
    // Force recalculation of the parent variable prices
    WC_Product_Variable::sync( $parent_id );
    
    return "Variation successfully created with ID: " . $variation_id;
}

