<?php

return [
    /*
    |--------------------------------------------------------
    | 文章资源保存地址
    | 本地filesystem disk
    | 是oss地址，oss:bucket:dir
    |--------------------------------------------------------
    */
    'articles_assets_disk' => env('WECHAT_ARTICLES_ASSETS_DISK', 'public'),
    /*
    |--------------------------------------------------------
    | 文章模板保存地址
    | 本地filesystem disk
    |--------------------------------------------------------
    */
    'articles_disk' => env('WECHAT_ARTICLES_DISK', 'local'),

    /*
    |--------------------------------------------------------
    | OSS 配置
    |--------------------------------------------------------
    */
    'oss_access_id'     => env('ALIYUN_APP_ACCESS_KEY'),
    'oss_access_secret'    => env('ALIYUN_APP_ACCESS_SECRET'),
    'oss_endpoint'      => env('ALIYUN_OSS_ENDPOINT', 'oss-cn-shanghai.aliyuncs.com'),
    'oss_endpointInternal' => env('ALIYUN_OSS_ENDPOINT_INTERNAL', 'oss-cn-shanghai-internal.aliyuncs.com'),
    'oss_is_cname'   => env('ALIYUN_OSS_ENABLE_CNAME', false),
    'oss_cdnDomain' => env('ALIYUN_OSS_CDN_DOMAIN', '')
];