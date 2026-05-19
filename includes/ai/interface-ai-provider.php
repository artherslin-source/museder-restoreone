<?php
/**
 * AI Provider interface (Free scaffolding).
 *
 * @package MusederRestoreOne
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Museder_AI_Provider_Interface {
    /**
     * Run a site scan and return a report structure.
     *
     * @param array $payload  Input payload (sanitized).
     * @param array $context  Optional context (user/site).
     * @return array<string,mixed>
     */
    public function scan( array $payload, array $context = [] ): array;

    /**
     * Return provider capabilities.
     *
     * @return array<string,mixed>
     */
    public function get_capabilities(): array;

    /**
     * Return optional provider metadata (hosted build: no quota fields).
     *
     * @return array<string,mixed>
     */
    public function get_limits(): array;
}


