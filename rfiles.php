<?php

/*!
 * RFiles Bot
 * Version 0.1
 *
 * Copyright 2016, Radya (telegram @error_log)
 * Released under the GNU GPLv2.0
 */

if ($_SERVER['REQUEST_METHOD'] != 'POST') die('REQUEST NOT ALLOWED');

// Load bot config
require('config.php');

// decode json to array
$json = json_decode(file_get_contents('php://input'), true);

// if not valid json
if (!is_array($json))
  throw new Exception('Invalid JSON');

$db = new SQLite3($config['db']);

$post = $json['message'];

if ($post['chat']['type'] == 'private') {

  if (isset($post['text']) && substr($post['text'], 0, 1) == '/') {

    // explode text
    $ex = explode(' ', $post['text']);

    switch ($ex[0]) {

      case '/start':
      case '/help':
      default:
        if (empty($ex[1])) {
          $req = array(
            'action' => 'sendMessage',
            'data' => array(
              'text' => "Share your file with everyone.\n\n
                - /list - Show a list of your shared files.\n
                - /delete <b>&lt;FILE ID&gt;</b> - Delete a file.\n
                - /about - Show info about bot.\n
                - /help - Show this help.\n\n

                Send me a document to start uploading. Make sure you attach it as file, not as an image or video."
              )
            );
        } else {

          $query = $db->querySingle('SELECT `file_id` FROM `rfiles_bot` WHERE `id` = "'.SQLite3::escapeString($ex[1]).'" LIMIT 1');

          if (!empty($query)) {
            $req = array(
              'action' => 'sendDocument',
              'data' => array('document' => $query)
              );
          } else {
            $req = array(
              'action' => 'sendMessage',
              'data' => array('text' => 'Sorry, we can\'t find a file with that ID. Either a typo was made or it was deleted already.')
              );
          }
        }
        break;
      
      // list command
      case '/list':

        $count = $db->querySingle('SELECT COUNT(`id`) FROM `rfiles_bot` WHERE `uploader` = "'.$post['from']['id'].'"');

        if ($count == 0) {
          $req['data']['text'] = 'You haven\'t shared any files yet.';
        } else {
          $files = $db->query('SELECT `id`,`file_name`,`file_size` FROM `rfiles_bot` WHERE `uploader` = "'.$post['from']['id'].'"');

          $req['data']['text'] = 'Your files : '."\n";

          $i = 1;
          while ($file = $files->fetchArray()) {

            $req['data']['text'] .= $i.'. <a href="https://telegram.me/'.$config['bot_username'].'?start='.$file['id'].'">'.htmlspecialchars($file['file_name']).'</a> ('.humanFileSize($file['file_size']).') - <b>ID: '.$file['id'].'</b>'."\n";
            $i++;
          }
        }

        $req['action'] = 'sendMessage';
        $req['data']['parse_mode'] = 'html';

        break;

      // delete command
      case '/delete':
        if (empty($ex[1])) {
          $req['data']['text'] = "/delete <b>&lt;FILE ID&gt;</b>\nSee FILE ID: /list";
        } else {

          $count = $db->querySingle('SELECT COUNT(`id`) FROM `rfiles_bot` WHERE `uploader` = "'.$post['from']['id'].'" AND `id` = "'.SQLite3::escapeString($ex[1]).'"');

          if ($count == 0) {
            $req['data']['text'] = 'Sorry, you haven\'t a file with that <b>ID</b>';
          } else {
            $db->exec('DELETE FROM `rfiles_bot` WHERE `id` = "'.SQLite3::escapeString($ex[1]).'"');
            
            $req['data']['text'] = 'File deleted successfully';
          }
        }
        $req['action'] = 'sendMessage';
        $req['data']['parse_mode'] = 'html';
      break;

      case '/about':
        $req = array(
          'action' => 'sendMessage',
          'data' => array(
            'text' => "RFiles Bot by @error_log\n
            Released under the GNU GPLv2.0.\n
            Source code: https://github.com/radyakaze/rfiles"
            )
          );
      break;
    }
  } elseif (empty($post['document'])) {
    
    $req = array(
      'action' => 'sendMessage',
      'data' => array('text' => 'Please send me a file you want to share. Make sure you attach it as file, not as an image or video.')
      );
  } else {

    $file_id = uniqid();

    if ( $db->exec('INSERT INTO `rfiles_bot` VALUES (
      "'.$file_id.'",
      "'.$post['document']['file_id'].'",
      "'.SQLite3::escapeString($post['document']['file_name']).'",
      "'.$post['from']['id'].'",
      "'.$post['document']['file_size'].'"
      )') ) {

        $req = array(
          'action' => 'sendMessage',
          'data' => array('text' => 'Your file have been uploaded. Send this link to anyone you want and they will able to download the file:'."\n\n".'https://telegram.me/'.$config['bot_username'].'?start='.$file_id)
          );
    } else {
      $req = array(
        'action' => 'sendMessage',
        'data' => array('text' => 'Error')
        );
    }
  }

  $req['data']['chat_id'] = $post['chat']['id'];
  $req['data']['reply_to_message_id'] = $post['message_id'];
  sendRequest($req['action'], $req['data']);
}

function sendRequest($action = 'sendMessage', $data = array()) {
  // get config
  global $config;

  // init curl
  $ch = curl_init();
  $config = array(
    CURLOPT_URL => 'https://api.telegram.org/bot'.$config['token'].'/'.$action,
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $data
  );

  curl_setopt_array($ch, $config);
  $result = curl_exec($ch);
  curl_close($ch);

  // return and decode json
  return (!empty($result) ? json_decode($result, true) : false);
}

function humanFileSize($size) { 
  $units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
  $power = $size > 0 ? floor(log($size, 1024)) : 0;
  return number_format($size / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
} 
