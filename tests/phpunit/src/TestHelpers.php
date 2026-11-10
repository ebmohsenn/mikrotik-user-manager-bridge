<?php
// Shared test helper classes to avoid duplicate definitions across test files

if ( ! class_exists( 'WP_User' ) ) {
    class WP_User {
        public $ID;
        public $user_login;
        public function __construct( $id = 0, $login = '' ) {
            $this->ID = $id;
            $this->user_login = $login;
        }
    }
}

if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        private $id;
        private $user_id;
        private $items;
        public function __construct( $id, $user_id, array $items ) {
            $this->id = $id;
            $this->user_id = $user_id;
            $this->items = $items;
        }
        public function get_id() { return $this->id; }
        public function get_user_id() { return $this->user_id; }
        public function get_items() { return $this->items; }
    }
}

if ( ! class_exists( 'WC_Product' ) ) {
    class WC_Product {
        private $id;
        public function __construct( $id ) {
            $this->id = $id;
        }
        public function get_id() { return $this->id; }
    }
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
    class WC_Order_Item_Product {
        private $product_id;
        private $variation_id;
        public function __construct( $product_id, $variation_id = 0 ) {
            $this->product_id = $product_id;
            $this->variation_id = $variation_id;
        }
        public function get_product_id() { return $this->product_id; }
        public function get_variation_id() { return $this->variation_id; }
    }
}

if ( ! class_exists( 'WooCommerce' ) ) {
    class WooCommerce {}
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        public function __construct( $code, $message ) {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}

if ( ! class_exists( 'MUMB_Branding' ) ) {
    class MUMB_Branding {
        public static function name() { return 'Test Brand'; }
        public static function logo_url() { return ''; }
    }
}