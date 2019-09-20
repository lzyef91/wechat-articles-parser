<?php

return [
    /*
    |--------------------------------------------------------
    | 本地资源url前缀
    | 定义文章资源本地保存的filesystem disk url参数
    | 用于helper wxarticles_asset(), 生成文章模板的js，css等静态资源url
    |--------------------------------------------------------
    */
    'https' => env('WECHAT_ARTICLES_ASSETS_HTTPS', false),
    'assets_url_prefix' => env('WECHAT_ARTICLES_ASSETS_URL_PREFIX', NULL),
    /*
    |--------------------------------------------------------
    | 文章资源保存地址
    | 本地保存，filesystem disk
    | oss保存，oss:bucket:dir
    |--------------------------------------------------------
    */
    'articles_assets_disk' => env('WECHAT_ARTICLES_ASSETS_DISK', 'public'),
    /*
    |--------------------------------------------------------
    | 文章模板保存地址
    | 本地保存，filesystem disk
    |--------------------------------------------------------
    */
    'articles_disk' => env('WECHAT_ARTICLES_DISK', 'local'),
    /*
    |--------------------------------------------------------
    | 有赞店铺ID
    |--------------------------------------------------------
    */
    'youzan_shop_id' => env('WECHAT_ARTICLES_YOUZAN_SHOP_ID'),
    /*
    |--------------------------------------------------------
    | 是否开启有赞销售员链接转化
    |--------------------------------------------------------
    */
    'enable_youzan_salesman_link_convert' => env('WECHAT_ARTICLES_ENABLE_YOUZAN_SALESMAN_LINK_CONVERT', false),
    /*
    |--------------------------------------------------------
    | 被转换小程序ID
    |--------------------------------------------------------
    */
    'convert_weapp_id' => env('WECHAT_ARTICLES_CONVERT_WEAPP_ID'),
    /*
    |--------------------------------------------------------
    | 小程序转化规则
    | pattern 小程序路径的匹配规则，例/^path\?(param1)=(\w*)$/
    | link 替换链接，参数用{}包裹，参数名与小程序路径的参数名保持一致，例https://path/{param1}?param2={param2}
    |--------------------------------------------------------
    */
    'weapp_to_link_rules' => [
        // [
        //     'pattern' => '',
        //     'link' => '',
        // ],
    ],
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