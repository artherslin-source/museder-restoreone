<?php
/**
 * WPRESS Engine Exceptions
 *
 * This plugin implements a local WPRESS parser/extractor inspired by the
 * All-in-One WP Migration (AI1WM) archive format.
 *
 * License: GPLv2 or later (this project)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Museder_Restoreone_Wpress_Exception extends Exception {}

class Museder_Restoreone_Wpress_Not_Accessible_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Not_Seekable_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Not_Tellable_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Not_Writable_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Not_Readable_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Not_Closable_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Not_Directory_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Not_Truncatable_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Quota_Exceeded_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Path_Traversal_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Not_Encryptable_Exception extends Museder_Restoreone_Wpress_Exception {}
class Museder_Restoreone_Wpress_Not_Decryptable_Exception extends Museder_Restoreone_Wpress_Exception {}


