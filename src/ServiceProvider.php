<?php

namespace Nldou\WechatArticlesParser;

use Nldou\WechatArticlesParser\Parser;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;

class ServiceProvider extends BaseServiceProvider implements DeferrableProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'wxarticles');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/config' => config_path()], 'wxarticles-config');
            $this->publishes([
                __DIR__.'/resources/assets/images' => public_path('vendor/wxarticles/images'),
                __DIR__.'/resources/assets/js/dist' => public_path('vendor/wxarticles/js'),
                __DIR__.'/resources/assets/css' => public_path('vendor/wxarticles/css')
            ], 'wxarticles-assets');
            $this->publishes([__DIR__.'/resources/views' => resource_path('views/vendor/wxarticles')], 'wxarticles-views');
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/wxarticles.php', 'wxarticles'
        );

        $this->app->singleton(Parser::class, function ($app) {

            $articlesAssetsDisk = config('wxarticles.articles_assets_disk');
            $articlesDisk = config('wxarticles.articles_disk');

            $isEnvProduction = $app->environment('production');

            $ossAccessId = config('wxarticles.oss_access_id');
            $ossAccessSecret = config('wxarticles.oss_access_secret');
            $ossEndPoint = config('wxarticles.oss_endpoint');
            $ossInternalEndPoint = config('wxarticles.oss_endpointInternal');
            $ossCdnDomain = config('wxarticles.oss_is_cname') ? config('wxarticles.oss_cdnDomain') : false;

            $convertWeappId = config('wxarticles.convert_weapp_id');
            $weappToLinkRules = config('wxarticles.weapp_to_link_rules');

            $youzanShopId = config('wxarticles.youzan_shop_id');
            $enableYouzanSalesman = config('wxarticles.enable_youzan_salesman_link_convert');

            return new Parser($articlesAssetsDisk, $articlesDisk, $isEnvProduction, $ossAccessId, $ossAccessSecret,
                $ossEndPoint, $ossInternalEndPoint, $ossCdnDomain, $convertWeappId, $weappToLinkRules, $youzanShopId, $enableYouzanSalesman);
        });

        $this->app->alias(Parser::class, 'wxarticles');
    }

    public function provides()
    {
        return [Parser::class, 'wxarticles'];
    }
}