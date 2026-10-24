<?php
namespace DSA\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Only controlled conversion errors may become client-facing detail. */
final class Service_Conversion_Exception extends \RuntimeException {}
