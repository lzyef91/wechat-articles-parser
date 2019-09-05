<?php

namespace Nldou\WechatArticlesParser\Traits;

trait HasAssets
{
    public static $headerCss = [
        'vendor/wxarticles/css/sweetalert2.min.css',
        'vendor/wxarticles/css/articles.min.css',
    ];

    public static $style = [];

    public static $headerJs = [
        'vendor/wxarticles/js/base/jquery.min.js',
        'vendor/wxarticles/js/base/howler.min.js',
        'vendor/wxarticles/js/base/sweetalert2.min.js',
        'vendor/wxarticles/js/base/jweixin-1.4.0.min.js'
    ];

    public static $footerJs = [
        'vendor/wxarticles/js/dist/video.min.js',
        'vendor/wxarticles/js/dist/mpvoice.min.js',
        'vendor/wxarticles/js/dist/qqmusic.min.js',
        'vendor/wxarticles/js/dist/followOfficialAccount.min.js',
    ];


    public static $script = [];

    public static $body = '';

    public static function favicon ()
    {
        return wxarticles_asset('favicon.ico');
    }

    public static function headerCss($css = null)
    {
        if (!is_null($css)) {
            return self::$headerCss = array_merge(self::$headerCss, (array) $css);
        }

        return view('wxarticles::partials.css', ['css' => array_unique(static::$headerCss)]);
    }

    public static function style($style = '')
    {
        if (!empty($style)) {
            return self::$style = array_merge(self::$style, (array) $style);
        }

        return view('wxarticles::partials.style', ['style' => array_unique(self::$style)]);
    }

    public static function headerJs($js = null)
    {
        if (!is_null($js)) {
            return self::$headerJs = array_merge(self::$headerJs, (array) $js);
        }

        return view('wxarticles::partials.js', ['js' => array_unique(static::$headerJs)]);
    }

    public static function footerJs($js = null)
    {
        if (!is_null($js)) {
            return self::$footerJs = array_merge(self::$footerJs, (array) $js);
        }

        return view('wxarticles::partials.js', ['js' => array_unique(static::$footerJs)]);
    }

    public static function script($script = '')
    {
        if (!empty($script)) {
            return self::$script = array_merge(self::$script, (array) $script);
        }

        return view('wxarticles::partials.script', ['script' => array_unique(static::$script)]);
    }

    public static function body($body = '')
    {
        if (!empty($body)) {
            return self::$body = $body;
        }

        return static::$body;
    }
}