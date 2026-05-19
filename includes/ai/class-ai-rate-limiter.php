<?php
/**
 * AI rate limiter (Free build).
 *
 * The WordPress.org-hosted build must not enforce daily quotas or expose "remaining" scan counts,
 * which reviewers may treat as trialware-style limits. This class is a no-op allow stub.
 *
 * @package MusederRestoreOne
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_AI_Rate_Limiter {
    /**
     * Assert the user is allowed to perform a scan (always allowed in the hosted build).
     *
     * @param int $user_id User ID.
     * @return true
     */
    public function assert_allowed_and_increment( int $user_id ) {
        return true;
    }
}
