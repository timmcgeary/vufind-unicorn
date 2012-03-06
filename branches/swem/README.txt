This driver is in rough condition. The authors don't even use it anymore,
and you probably shouldn't either. It it submitted merely to demonstrate the
handling of bound-withs in a CGI-script-based driver.

Your holdings template would then need code like this:

{if $row.id != $row.unicorn_boundwith}
   {getRecord id=$row.unicorn_boundwith var=record}
   bound with <a href="{$url}/Record/{$row.unicorn_boundwith|escape:"url"}">{$record.title_full|truncate:60:"..."}</a>
{/if}

which uses a Smarty function defined thus:

<?php
function smarty_function_getRecord($params, &$smarty){
  global $configArray;
  $engine = $configArray['Index']['engine'];
  $url = $configArray['Index']['url'];

  $db = new $engine($url);
  $db->raw = false;

  if(empty($params['id'])){
          $smarty->trigger_error("getRecord: missing ID parameter");
          return;
  } else {
          $record = $db->getRecord($params['id']);
          $smarty->assign($params['var'], $record);
  }
}
?>

