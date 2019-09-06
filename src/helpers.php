<?php

use Illuminate\Support\Facades\URL;

if (!function_exists('wxarticles_asset')) {

    /**
     * @param $path
     *
     * @return string
     */
    function wxarticles_asset($uri)
    {
        if (URL::isValidUrl($uri)) {
            return $uri;
        }

        if (!config('wxarticles.assets_url_prefix')) {
            return config('wxarticles.https') ? secure_asset($uri) : asset($uri);
        }

        $assetsUrl = config('wxarticles.assets_url_prefix');
        $host = parse_url($assetsUrl, PHP_URL_HOST);
        $path = parse_url($assetsUrl, PHP_URL_PATH);
        $uri = rtrim($host.$path, '/').'/'.ltrim($uri, '/');
        return config('wxarticles.https') ? 'https://'.$uri : 'http://'.$uri;
    }
}