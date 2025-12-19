<?php
/**
 * AI Provider factory (Free scaffolding).
 *
 * @package MusederRestoreOne
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_AI_Factory {
    /**
     * Get the current AI provider.
     *
     * Free version never includes Pro provider files. We only switch if:
     * - PRO is active (via museder_is_pro_active())
     * - and a Pro provider class is already available (loaded elsewhere).
     *
     * @return Museder_AI_Provider_Interface
     */
    public static function provider(): Museder_AI_Provider_Interface {
        /**
         * Allow override provider class (advanced usage).
         *
         * @param string $class_name Provider class name.
         */
        $forced = (string) apply_filters( 'museder_ai_provider_class', '' );
        if ( $forced && class_exists( $forced ) ) {
            $instance = new $forced();
            if ( $instance instanceof Museder_AI_Provider_Interface ) {
                return $instance;
            }
        }

        // Pro provider (must already be loaded by Pro code; Free never requires it).
        if ( function_exists( 'museder_is_pro_active' ) && museder_is_pro_active() && class_exists( 'Museder_AI_Provider_Pro' ) ) {
            $pro = new Museder_AI_Provider_Pro();
            if ( $pro instanceof Museder_AI_Provider_Interface ) {
                return $pro;
            }
        }

        return new Museder_AI_Provider_Free();
    }
}


