#!/usr/bin/env php
<?php

/**
 * FileAssistantBot
 * Simple Telegram bot to generate download link of files and upload files from URL
 * Based on MadelineProto
 * https://github.com/danog/MadelineProto
 * By NimaH79
 * http://nimah79.ir.
 */
define('FILES_PATH', __DIR__.'/files');
define('WEBSERVER_BASE_URL', 'http://yourdomainaddress.com');
define('FILES_EXPIRE_TIME', 24 * 3600); // in seconds

set_time_limit(0);

if (!function_exists('readline')) {
    function readline($prompt = null)
    {
        if ($prompt) {
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
    public function __construct($MadelineProto)
    {
        parent::__construct($MadelineProto);
    }

    public function onAny($update)
    {
        foreach (glob(__DIR__.'/files/*') as $file) {
            if (time() - filectime($file) > FILES_EXPIRE_TIME) {
                unlink($file);
            }
        }
    }

    public function onUpdateNewChannelMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
    }

    public function onUpdateNewMessage($update)
    {
        if (isset($update['message']['out']) && $update['message']['out']) {
            return;
        }

        try {
            if (isset($update['message']['media']) && ($update['message']['media']['_'] == 'messageMediaPhoto' || $update['message']['media']['_'] == 'messageMediaDocument')) {
                $message_id = $update['message']['id'];
                $sent_message = yield $this->messages->sendMessage(['peer' => $update, 'message' => 'Generating download link… 0%', 'reply_to_msg_id' => $message_id]);
                $time = time();
                $last_progress = 0;
                $output_file_name = yield $this->download_to_dir($update, new \danog\MadelineProto\FileCallback(
                    FILES_PATH,
                    function ($progress) use ($update, $sent_message, $last_progress) {
                        $progress = round($progress);
                        if ($progress > $last_progress) {
                            try {
                                yield $this->messages->editMessage(['id' => $sent_message['id'], 'peer' => $update, 'message' => 'Generating download link… '.$progress.'%']);
                            } catch (Exception $e) {
                            }
                            $last_progress = $progress;
                        }
                    }
                ));
                yield $this->messages->editMessage(['id' => $sent_message['id'], 'peer' => $update, 'message' => 'Download link Generated in '.(time() - $time)." seconds!\n\n💾 ".basename($output_file_name)."\n\n📥 ".rtrim(WEBSERVER_BASE_URL, '/').'/'.str_replace(__DIR__.'/', '', str_replace(' ', '%20', $output_file_name))."\n\nThis link will be expired in 24 hours.", 'reply_to_msg_id' => $message_id]);
            } elseif (isset($update['message']['message'])) {
                $message_id = $update['message']['id'];
                $text = $update['message']['message'];
                if ($text == '/start') {
                    yield $this->messages->sendMessage(['peer' => $update, 'message' => 'Hi! please send me any file url or file uploaded in Telegram and I will upload to Telegram as file or generate download link of that file.', 'reply_to_msg_id' => $message_id]);

                    return;
                }
                $url = filter_var($text, FILTER_VALIDATE_URL);
                if ($url === false) {
                    yield $this->messages->sendMessage(['peer' => $update, 'message' => 'URL format is incorrect. make sure your URL starts with either http:// or https://.', 'reply_to_msg_id' => $message_id]);

                    return;
                }
                $filename = explode('|', $text);
                if (!empty($filename[1])) {
                    $filename = $filename[1];
                } else {
                    $filename = basename($url);
                }
                if (empty($filename)) {
                    yield $this->messages->sendMessage(['peer' => $update, 'message' => 'Can you check your URL? I\'m unable to detect filename from the URL.', 'reply_to_msg_id' => $message_id]);

                    return;
                }
                $filename_length = $filename;
                if ($filename_length > 60) {
                    yield $this->messages->sendMessage(['peer' => $update, 'message' => 'Your filename contains '.$filename_length.' characters. Maximum limit allowed in Telegram is 60 characters. Please shorten your filename and try again.', 'reply_to_msg_id' => $message_id]);

                    return;
                }
                $client = new Amp\Artax\DefaultClient();
                $promise = $client->request($url, [Amp\Artax\Client::OP_MAX_BODY_BYTES => 120 * 1024 * 1024]);
                $response = yield $promise;
                $headers = $response->getHeaders();
                if (empty($headers['content-length'][0])) {
                    yield $this->messages->sendMessage(['peer' => $update, 'message' => 'Unable to obtain file size.', 'reply_to_msg_id' => $message_id]);

                    return;
                }
                $filesize = $headers['content-length'][0];
                if ($filesize > 1024 ** 3) {
                    yield $this->messages->sendMessage(['peer' => $update, 'message' => 'Your file should be snakker than 1 GB.', 'reply_to_msg_id' => $message_id]);

                    return;
                }
                $sent_message = yield $this->messages->sendMessage(['peer' => $update, 'message' => 'Downloading file from URL…', 'reply_to_msg_id' => $message_id]);
                $filepath = FILES_PATH.'/'.time().rand().'_'.$filename;
                $file = yield Amp\File\open($filepath, 'w');
                yield Amp\ByteStream\pipe($response->getBody(), $file);
                yield $file->close();
                Amp\File\StatCache::clear($filepath);
                yield $this->messages->editMessage(['id' => $sent_message['id'], 'peer' => $update, 'message' => "📤 Your request is in the queue. Do not send another request. Please be patient…\n🗂 File: ".$filename."\n🔗 URL: ".$url."\n💿 File Size: ".$this->formatBytes($filesize)."\n\n⌛ Upload progress: 0%"]);
                $time = time();
                $last_progress = 0;
                yield $this->messages->sendMedia([
                    'peer'  => $update,
                    'media' => [
                        '_'    => 'inputMediaUploadedDocument',
                        'file' => new \danog\MadelineProto\FileCallback($filepath, function ($progress) use ($update, $sent_message, $last_progress, $filename, $filesize, $url) {
                            $progress = round($progress);
                            if ($progress > $last_progress) {
                                try {
                                    yield $this->messages->editMessage(['peer' => $update, 'id' => $sent_message['id'], 'message' => "📤 Your request is in the queue. Do not send another request. Please be patient…\n🗂 File: ".$filename."\n🔗 URL: ".$url."\n💿 File Size: ".$this->formatBytes($filesize)."\n\n⌛ Upload progress: ".$progress.'%']);
                                } catch (Exception $e) {
                                }
                                $last_progress = $progress;
                            }
                        }),
                        'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => $filename]],
                    ],
                    'reply_to_msg_id' => $message_id,
                ]);
                $time = explode(':', gmdate('H:i:s', time() - $time));
                foreach ($time as &$value) {
                    $value = ltrim($value, '0');
                }
                $text = 'Uploaded… 100% in';
                if (!empty($time[0])) {
                    $text .= ' '.$time[0].'h';
                }
                if (!empty($time[1])) {
                    $text .= ' '.$time[1].'m';
                }
                if (!empty($time[2])) {
                    $text .= ' '.$time[2].'s';
                }
                yield $this->messages->editMessage(['id' => $sent_message['id'], 'peer' => $update, 'message' => $text]);
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
            }
        } catch (Exception $e) {
        }
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision).' '.$units[$pow];
    }
}

$MadelineProto = new \danog\MadelineProto\API('filer.madeline');
$MadelineProto->async(true);
$MadelineProto->loop(function () use ($MadelineProto) {
    yield $MadelineProto->start();
    yield $MadelineProto->setEventHandler('\EventHandler');
});
$MadelineProto->loop();
