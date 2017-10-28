<?php

define('START_TIME', time());

define('BASE_PATH', dirname(__FILE__) . '/');
define('CACHE_PATH', BASE_PATH . 'cache/');
define('LOG_PATH', BASE_PATH . 'logs/');

define('CACHE_TTL', 20 * 60);
define('DOWNLOAD_FILE_LIMIT', 10 * 1024 * 1024);

require 'vendor/autoload.php';

use React\Http\Response;
use React\Socket\Server as Socket;
use React\Http\Server as HttpServer;
use Psr\Http\Message\RequestInterface;

/**
 * Reads a file with a list of IPv4 and IPv6 addresses 
 * separated by newline characters.
 *
 * @param string $filename
 * @return array
 */
function load_ip_list($filename)
{
  $result = [];
  
  if (! file_exists($filename))
    return $result;
  
  if (! is_readable($filename)) {
    writelf('Failed to load: %s (file is not readable)', $filename);
    return $result;
  }
  
  $entries = file($filename, FILE_SKIP_EMPTY_LINES);
  
  foreach ($entries as $line => $entry)
  {
    $entry = trim($entry);
    
    if (! filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6))
      continue;
    
    $result[] = $entry;
  }
  
  return $result;
}

/**
 * Check whether the IP address is blacklisted.
 * The list is automatically refreshed every 60 seconds.
 *
 * @param string $ip
 * @return bool
 */
function is_blacklisted($ip)
{
  static $list, $loaded = 0;
  
  if (is_null($list) || (time() - $loaded) > 60) {
    $list = load_ip_list(BASE_PATH . 'blacklist.txt');
    $loaded = time();
  }
  
  if (empty($list))
    return false;
  
  return in_array($ip, $list);
}

/**
 * Check whether the IP address is whitelisted.
 * If the address is in the black list will be blocked.
 * The list is automatically refreshed every 60 seconds.
 *
 * @param string $ip
 * @return bool
 */
function is_whitelisted($ip)
{
  static $list, $loaded = 0;
  
  if (is_null($list) || (time() - $loaded) > 60) {
    $list = load_ip_list(BASE_PATH . 'whitelist.txt');
    $loaded = time();
  }
  
  $blacklisted = is_blacklisted($ip);
  
  if (empty($list))
    return ! $blacklisted;
  
  return (in_array($ip, $list) && ! $blacklisted);
}

/**
 * Converts size in bytes into human readable format.
 *
 * @param int $bytes
 * @return string
 */
function human_size($bytes) 
{
  $size = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
  $factor = floor((strlen($bytes) - 1) / 3);
  
  return sprintf("%.2f %s", $bytes / pow(1024, $factor), @$size[$factor]);
}

/**
 * Saves messages to the current process log.
 *
 * @param string $format
 * @param mixed ...$args
 * @thorws InvalidArgumentException
 */
function writelog()
{
  static $handle;

  if (func_num_args() == 0)
    throw new \InvalidArgumentException('This function needs at least one argument');
 
  if (! is_resource($handle)) {
    $logfile = LOG_PATH . sprintf('output_%s__%s.log', @date('Y-m-d', START_TIME), @date('H_i_s', START_TIME));
    
    if (! is_dir($path = dirname($logfile)))
      @mkdir($path);
    
    $handle = fopen($logfile, 'a');
    flock($handle, LOCK_EX);
    
    writelf('Log file: logs/%s', basename($logfile));
    
    register_shutdown_function(function() use ($handle) {
      flock($handle, LOCK_UN);
      fclose($handle);
    });
  }
  
  $args = func_get_args();
  
  if (substr($args[0], -1) != "\n")
    $args[0] .= PHP_EOL;
  
  $message = call_user_func_array('sprintf', $args);
  
  fwrite($handle, sprintf('%s | %s', @date('r'), $message));
  fflush($handle);
}

/**
 * Prints a formatted message to the standard output 
 * of the program.
 *
 * @param string $format
 * @param mixed ...$args
 * @thorws InvalidArgumentException
 */
function writef()
{
  if (func_num_args() == 0)
    throw new \InvalidArgumentException('This function needs at least one argument');

  writelog($message = call_user_func_array('sprintf', func_get_args()));
  
  $usec = current(explode(' ', microtime()));
  $usec *= 10000;
  
  printf('%s.%04d | %s', @date('H:i:s'), $usec, $message);
  flush();
}

/**
 * Prints a formatted message to the standard output 
 * of the program. Adds a newline at the end of the 
 * string.
 *
 * @param string $format
 * @param mixed ...$args
 * @thorws InvalidArgumentException
 */
function writelf()
{
  if (func_num_args() == 0)
    throw new \InvalidArgumentException('This function needs at least one argument');
    
  $args = func_get_args();
  $args[0] .= PHP_EOL;
  
  call_user_func_array('writef', $args);
}

/**
 * Checks whether the cache directory contains 
 * obsolete files and deletes them.
 *
 * @param bool $force
 */
function clear_cache($force = false)
{
  clearstatcache();
  
  foreach (new \DirectoryIterator(CACHE_PATH) as $fileinfo)
  {
    if ($fileinfo->isDot() || ! $fileinfo->isFile())
      continue;
    
    if ((time() - $fileinfo->getATime()) <= CACHE_TTL)
      continue;
    
    $file = CACHE_PATH . $fileinfo->getFilename();
    writelf('Expired: %s', $file);
    
    if (! unlink($file))
      writelf('Failed to delete file: %s', $file);
  }
}

/**
 * Retrieves a YouTube video in audio format.
 *
 * @param string $videoId
 * @param int $limit
 * @return string
 */
function fetch_youtube_video($videoId, $limit = DOWNLOAD_FILE_LIMIT)
{
  $cacheFile = CACHE_PATH . $videoId;
  
  if (file_exists($cacheFile))
    return file_get_contents($cacheFile);
  
  $descriptors = [
    ['pipe', 'r'],
    ['pipe', 'w']
  ];
  
  $url = 'https://www.youtube.com/watch?v=' . $videoId;
  $cmd = sprintf('youtube-dl -f 251 --quiet %s -o - 2>/dev/null', $url);
  $downloader = proc_open($cmd, $descriptors, $pipes);
  
  if (! is_resource($downloader)) {
    writelf('Failed to start youtube-dl, maybe not installed?');
    return false;
  }
  
  writelf('Downloading video id: %s', $videoId);
  
  $begin = time();
  $bytes = '';
  
  fclose($pipes[0]);
  
  if ($limit > 0) {
    $count = 0;
  
    while (! feof($pipes[1]))
    {
      if (! ($chunk = fread($pipes[1], 1024)))
        return false;
      
      if (($count += 1024) > $limit)
        return false;
      
      $bytes .= $chunk;
    }
  } else
    $bytes = stream_get_contents($pipes[1]);
  
  file_put_contents($cacheFile, $bytes);
  
  $length = strlen($bytes);
  writelf('Downloaded %s from server in %d seconds', human_size($length), (time() - $begin));
  
  return $bytes;
}

/**
 * Processes the HTTP client request and parses 
 * the video identifier from it.
 * 
 * @param RequestInterface $request
 * @return false|string
 */
function process_request(array $server, RequestInterface $request)
{
  $source = $server['REMOTE_ADDR'];
  
  if (! is_whitelisted($server['REMOTE_ADDR'])) {
    writelf('Blocking connection from disallowed source [%s]', $source);
    return false;
  }
  
  if ('GET' != $request->getMethod()) {
    writelf('Invalid request method [%s]', $source);
    return false;
  }
  
  $path = $request->getRequestTarget();
  
  if (! preg_match('/(\/|\?v\=)?([a-zA-Z0-9_-]{11})$/', $path, $matches)) {
    writelf('Failed to retrieve video identifier from request path [%s]', $source);
    return false;
  }
  
  return $matches[2];
}

/**
 * React HTTP request handler.
 *
 * @param RequestInterface $request
 * @return Response
 */
function handle_http_request(RequestInterface $request)
{
  $headers = [
    'Content-Type' => 'text/plain;charset=utf-8',
    'X-Powered-By' => 'YouTube Streamer by Adams'
  ];
  
  $server = $request->getServerParams();
  
  if (! ($videoId = process_request($server, $request)))
    return new Response(404, $headers, '404');
  
  writelf('Request from %s [%s]', $server['REMOTE_ADDR'], $videoId);
  
  $headers = array_merge($headers, [
    'Content-Type' => 'audio/mpeg',
    'Content-Disposition' => sprintf('attachment; filename="%s.mp3"', $videoId),
    'Expires' => '0',
    'Accept-Ranges' => 'bytes',
    'Connection' => 'keep-alive'
  ]);
  
  $bytes = fetch_youtube_video($videoId);
  
  if (! is_string($bytes)) {
    writelf('Failed to load video [%s]', $videoId);
    return new Response(404, $headers, '404');
  }
  
  return new Response(200, $headers, $bytes);
}

if (PHP_SAPI == 'cli') {
  /**
   * Main function.
   * 
   * @param int $argc
   * @param array $argv
   * @return int
   */
  return (function ($argc, array $argv = [])
  {
    $loop = React\EventLoop\Factory::create();

    $uri = isset($argv[1]) ? $argv[1] : '0.0.0.0:81';
    
    (new HttpServer('handle_http_request'))
      ->listen($socket = new Socket($uri, $loop));
    
    writelf('Listening on %s ...', $socket->getAddress());

    $loop->addPeriodicTimer(300, 'clear_cache');
    $loop->run();
    
    return 0;
  })($argc, $argv);
}
