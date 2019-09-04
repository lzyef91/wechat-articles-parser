<?php

namespace Nldou\WechatArticlesParser;

use Nldou\WechatArticlesParser\Parser;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;

class ServiceProvider extends BaseServiceProvider implements DeferrableProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/config' => config_path()], 'nldou-wechat-article-parser-config');
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/wxarticles.php', 'wxarticles'
        );
        $this->app->singleton(Parser::class, function ($app) {

            $articlesAssetsDisk = conifg('wxarticles.articles_assets_disk');
            $articlesDisk = conifg('wxarticles.articles_disk');
            $ossAccessId = config('oss_access_id');
            $ossAccessSecret = config('oss_access_secret');
            $ossEndPoint = config('oss_endpoint');
            $ossInternalEndPoint = config('oss_endpointInternal');
            $isEnvProduction = $app->environment('production');
            $ossCdnDomain = config('oss_is_cname') ? config('oss_cdnDomain') : false;

            return new Parser($articlesAssetsDisk, $articlesDisk, $ossAccessId, $ossAccessSecret,
                $isEnvProduction, $ossEndPoint, $ossInternalEndPoint, $ossCdnDomain);
        });
        $this->app->alias(Parser::class, 'wxarticles');
    }

    public function provides()
    {
        return [Parser::class, 'wxarticles'];
    }
}