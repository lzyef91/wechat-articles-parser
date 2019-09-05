<?php

if (!function_exists('wxarticles_asset')) {

    /**
     * @param $path
     *
     * @return string
     */
    function wxarticles_asset($path)
    {
        return config('wxarticles.https') ? secure_asset($path) : asset($path);
    }
}