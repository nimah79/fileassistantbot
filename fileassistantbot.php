#!/usr/bin/env php
<?php

define('FILES_PATH', 'files');
define('WEBSERVER_URL', 'http://yourdomainaddress.com/');

if(!function_exists('readline')) {
    function readline($prompt = null) {
        if($prompt) {
            echo $prompt;
        }
        $fp = fopen('php://stdin', 'r');
        $line = rtrim(fgets($fp, 1024));
        return $line;
    }
}

if (!file_exists(__DIR__.'/madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', __DIR__.'/madeline.php');
}
require __DIR__.'/madeline.php';

class EventHandler extends \danog\MadelineProto\EventHandler
{
    public function onUpdateNewChannelMessage($update)
    {
        $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage($update)
    {
        if(isset($update['message']['out']) && $update['message']['out']) {
            return;
        }
        try {
            if(isset($update['message']['media']) && ($update['message']['media']['_'] == 'messageMediaPhoto' || $update['message']['media']['_'] == 'messageMediaDocument')) {
                $sent_message = $this->messages->sendMessage(['peer' => $update, 'message' => 'Generating download link… 0%', 'reply_to_msg_id' => $update['message']['id']]);
                $last_progress = 0;
                $time = time();
                $output_file_name = $this->download_to_dir($update, new \danog\MadelineProto\FileCallback(FILES_PATH,
                    function($progress) use($update, $sent_message, $last_progress) {
                        $progress = round($progress);
                        if($progress > $last_progress) {
                            $this->messages->editMessage(['id' => $sent_message['id'], 'peer' => $update, 'message' => 'Generating download link… '.$progress.'%']);
                            $last_progress = $progress;
                        }
                    }));
                $this->messages->editMessage(['id' => $sent_message['id'], 'peer' => $update, 'message' => 'Download link Generated in '.(time() - $time).' seconds!'.PHP_EOL.PHP_EOL.'💾 '.basename($output_file_name).PHP_EOL.PHP_EOL.'📥 '.WEBSERVER_URL.str_replace(__DIR__.'/', '', str_replace(' ', '%20', $output_file_name)), 'reply_to_msg_id' => $update['message']['id']]);
            }
            elseif(isset($update['message']['message'])) {
                $text = $update['message']['message'];
                if($text == '/start') {
                    $this->messages->sendMessage(['peer' => $update, 'message' => 'Hi! please send me any file url or file uploaded in Telegram and I will upload to Telegram as file or generate download link of that file.', 'reply_to_msg_id' => $update['message']['id']]);
                }
                elseif(filter_var($text, FILTER_VALIDATE_URL)) {
                    $filename = $this->curl_get_filename($text);
                    if($filename !== false) {
                        $sent_message = $this->messages->sendMessage(['peer' => $update, 'message' => 'Downloading file from URL…', 'reply_to_msg_id' => $update['message']['id']]);
                        $filepath = __DIR__.'/'.FILES_PATH.'/'.time().'_'.$filename;
                        $file = fopen($filepath, 'w');
                        $ch = curl_init($text);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_FILE, $file);
                        curl_exec($ch);
                        curl_close($ch);
                        fclose($file);
                        $this->messages->editMessage(['id' => $sent_message['id'], 'peer' => $update, 'message' => 'Uploading file to Telegram…']);
                        $this->messages->sendMedia(['peer' => $update, 'media' => ['_' => 'inputMediaUploadedDocument', 'file' => $filepath, 'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => $filename]]], 'reply_to_msg_id' => $update['message']['id']]);
                        $this->messages->deleteMessages(['revoke' => true, 'id' => [$sent_message['id']]]);
                        unlink($filepath);
                    }
                    else {
                        $this->messages->sendMessage(['peer' => $update, 'message' => 'Can you check your URL? I\'m unable to detect filename from the URL.', 'reply_to_msg_id' => $update['message']['id']]);
                    }
                }
                else {
                    $this->messages->sendMessage(['peer' => $update, 'message' => 'URL format is incorrect. make sure your URL starts with either http:// or https://.', 'reply_to_msg_id' => $update['message']['id']]);
                }
            }
        } catch (\danog\MadelineProto\RPCErrorException $e) { }
    }
    private function curl_get_filename($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
        $response = curl_exec($ch);
        if(curl_getinfo($ch, CURLINFO_HTTP_CODE) == '200') {
            $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            if($url != $effective_url) {
                return $this->curl_get_filename($effective_url);
            }
            if(!preg_match('/text\/html/', $response)) {
              if(preg_match('/^Content-Disposition: .*?filename=(?<f>[^\s]+|\x22[^\x22]+\x22)\x3B?.*$/m', $response, $filename)) {
                $filename = trim($filename['f'],' ";');
                return $filename;
              }
              return basename($url);
            }
            return false;
        }
        curl_close($ch);
        return false;
    }
}
$MadelineProto = new \danog\MadelineProto\API('filer.madeline');
$MadelineProto->start();
$MadelineProto->setEventHandler('\EventHandler');
$MadelineProto->loop(-1);
