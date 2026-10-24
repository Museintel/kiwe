<?php
namespace DSA\Backup;
if ( ! defined( 'ABSPATH' ) ) exit;
/** Deliberately public archive feedback; native hook/system exceptions remain private. */
final class Archive_Error extends \RuntimeException {}
