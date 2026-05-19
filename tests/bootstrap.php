<?php

require(__DIR__ .'/../lib/vendor/antecedent/patchwork/Patchwork.php');
require(__DIR__ . "/../lib/vendor/autoload.php");

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public $headers = [];

        public function __construct($data = null, $status = 200) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data()    { return $this->data; }
        public function get_status()  { return $this->status; }
        public function get_headers() { return $this->headers; }

        public function header($key, $value, $replace = true) {
            $this->headers[$key] = $value;
        }
    }
}
?>