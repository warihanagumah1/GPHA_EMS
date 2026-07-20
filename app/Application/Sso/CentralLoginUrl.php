<?php
namespace App\Application\Sso;
class CentralLoginUrl { public function returnUrl():string{return (string)(config('gpha_sso.return_url')?:url('/sso/login'));} public function loginUrl(array $query=[]):string{$url=trim((string)config('gpha_sso.central_login_url'));if($url==='')return route('login');$query=['returnUrl'=>$this->returnUrl()]+$query;return $url.(str_contains($url,'?')?'&':'?').http_build_query($query);} }
