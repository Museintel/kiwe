<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

/** A known created native identity, never a claim that the remaining edit saved. */
final class Unconfirmed_Write extends \RuntimeException {
 public function __construct(public readonly string $type,public readonly int $id,?\Throwable $previous=null){
  if(!in_array($type,['page','attachment'],true)||$id<1)throw new \InvalidArgumentException('Choose a known native Page draft or Media attachment.');
  $message=$type==='page'
   ? 'Page draft '.$id.' was created, but its remaining save was not confirmed. Read this Page before another action.'
   : 'Media attachment '.$id.' was created, but its image generation record was not confirmed. Review it in Media before requesting another image.';
  parent::__construct($message,0,$previous);
 }
 public function receipt(): array{return['resultJson'=>(string)wp_json_encode(['type'=>$this->type,'id'=>$this->id,'confirmed'=>false])];}
}
