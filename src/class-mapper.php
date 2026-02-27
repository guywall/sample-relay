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
     * Parse admin-managed hidden sample values (SKU or name).
     */
    public static function parseHiddenSamples( $hidden_raw ) {
        if ( ! is_string( $hidden_raw ) || trim( $hidden_raw ) === '' ) {
            return [];
        }

        $parts = preg_split( '/[\r\n,;]+/', strtolower( $hidden_raw ) );
        return array_values( array_unique( array_filter( array_map( 'trim', $parts ) ) ) );
    }

    /**
     * Determine if a sample should be hidden by name or SKU.
     */
    public static function isSampleHidden( $name, $sku, array $hidden_samples ) {
        if ( empty( $hidden_samples ) ) {
            return false;
        }

        $name_raw = strtolower( trim( (string) $name ) );
        $sku_raw  = strtolower( trim( (string) $sku ) );

        if ( in_array( $name_raw, $hidden_samples, true ) || in_array( $sku_raw, $hidden_samples, true ) ) {
            return true;
        }

        $name_norm = preg_replace( '/[^a-z0-9]/', '', $name_raw );
        $sku_norm  = preg_replace( '/[^a-z0-9]/', '', $sku_raw );

        foreach ( $hidden_samples as $hidden ) {
            $hidden_norm = preg_replace( '/[^a-z0-9]/', '', (string) $hidden );
            if ( $hidden_norm === '' ) {
                continue;
            }

            if ( $name_norm !== '' && ($name_norm === $hidden_norm || strpos( $name_norm, $hidden_norm ) !== false) ) {
                return true;
            }

            if ( $sku_norm !== '' && $sku_norm === $hidden_norm ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove samples that are hidden/unavailable.
     */
    public static function filterAvailableSamples( array $samples, array $hidden_samples ) {
        if ( empty( $hidden_samples ) ) {
            return $samples;
        }

        $filtered = [];
        foreach ( $samples as $sample ) {
            $name = $sample['name'] ?? '';
            $sku  = $sample['sku'] ?? '';

            if ( self::isSampleHidden( $name, $sku, $hidden_samples ) ) {
                continue;
            }

            $filtered[] = $sample;
        }

        return $filtered;
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
