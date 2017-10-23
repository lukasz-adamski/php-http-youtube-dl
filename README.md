# About
HTTP server based on [ReactPHP][react_git] which allows you to download and stream YouTube videos as MPEG audio files.

## Requirements
1. PHP 5.6+ with [composer][composer_website]
2. [youtube-dl][youtubedl_git]

## Usage
1. Download content from this repository `git clone https://github.com/lukasz-adamski/php-http-youtube-dl.git`
2. Enter installation directory and run `composer update`
3. Run program: `php main.php`

## Allow connections
This web-service is using `whitelist.txt` and `blacklist.txt` files to allow and block connections. If you want to make it public without using these files you can use http-proxy eg. [nginx][nginx_website]. In these files you can write [IPv4][ipv4_wiki] or [IPv6][ipv6_wiki] formatted addresses.

[react_git]: https://github.com/reactphp
[composer_website]: https://getcomposer.org/
[nginx_website]: https://nginx.org/en/
[youtubedl_git]: https://github.com/rg3/youtube-dl
[ipv4_wiki]: https://en.wikipedia.org/wiki/IPv4
[ipv6_wiki]: https://en.wikipedia.org/wiki/IPv6
