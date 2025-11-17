<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PBSR_Mapper {

    /**
     * Normalise incoming webhook data using the field map from settings.
     */
    public static function canonicalize( array $raw, array $map ) {
        $out = [];

        foreach ( $map as $src => $dst ) {
            $out[ $dst ] = $raw[ $src ] ?? null;
        }

        // Split full name into first / last if needed
        if ( empty( $out['first_name'] ) && ! empty( $raw['name'] ) ) {
            $parts = preg_split( '/\s+/', trim( $raw['name'] ) );
            $out['first_name'] = $parts[0] ?? '';
            $out['last_name']  = isset( $parts[1] ) ? implode( ' ', array_slice( $parts, 1 ) ) : '';
        }

        return $out;
    }

    /**
     * Build Zoho Books line items from blend names by looking up matching Products CPTs.
     *
     * Each blend name must match a Product post title; its ACF field `sample_sku`
     * is then used to locate the Zoho Books item.
     */
    public static function blendsToLineItemsFromSamples( array $samples, PBSR_Zoho_Books $books ) {
    $items = [];

    foreach ( $samples as $sample ) {
        $name = trim( $sample['name'] ?? '' );
        $sku  = trim( $sample['sku'] ?? '' );
        if ( $sku === '' ) continue;

        // Try to find the matching item in Zoho Books by SKU
        $found = $books->searchItemBySKU( $sku );
        if ( ! $found || empty( $found['item_id'] ) ) {
            error_log( "PBSR Mapper: No Zoho item found for SKU {$sku} ({$name})" );
            continue;
        }

        $items[] = [
            'item_id'     => $found['item_id'],
            'rate'        => $found['rate'] ?? 0,
            'name'        => $found['name'] ?? $name,
            'quantity'    => 1,
            'sku'         => $sku,
            'description' => 'Sample: ' . $name,
        ];
    }

    return $items;
}
}
