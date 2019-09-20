<?php

namespace Nldou\WechatArticlesParser;

use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\MimeTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nldou\AliyunOSS\OSS;
use OSS\OssClient;
use Illuminate\Support\Facades\URL;
use Nldou\WechatArticlesParser\Exceptions\InvalidParamsException;
use Nldou\WechatArticlesParser\Exceptions\HttpException;
use Nldou\AliyunOSS\Exceptions\Exception as OSSException;
use Nldou\WechatArticlesParser\Traits\HasAssets;

class Parser
{
    use HasAssets;

    protected $crawler;
    protected $client;
    protected $processElement;
    protected $articlesAssetsDisk;
    protected $articlesDisk;

    protected $oss;
    protected $ossAccessId;
    protected $ossAccessSecret;
    protected $isEnvProduction;
    protected $ossEndPoint;
    protected $ossInternalEndPoint;
    protected $ossCdnDomain;

    protected $convertWeappId;
    protected $weappToLinkRules;

    protected $youzanShopId;
    protected $enableYouzanSalesman;

    public function __construct($articlesAssetsDisk, $articlesDisk, $isEnvProduction,
        $ossAccessId, $ossAccessSecret, $ossEndPoint, $ossInternalEndPoint, $ossCdnDomain,
        $convertWeappId, $weappToLinkRules,
        $youzanShopId, $enableYouzanSalesman)
    {
        // 文章资源储存位置
        $this->articlesAssetsDisk = $articlesAssetsDisk;

        // 文章模板储存位置
        $this->articlesDisk = $articlesDisk;

        // oss
        $this->ossAccessId = $ossAccessId;
        $this->ossAccessSecret = $ossAccessSecret;
        $this->isEnvProduction = $isEnvProduction;
        $this->ossEndPoint = $ossEndPoint;
        $this->ossInternalEndPoint = $ossInternalEndPoint;
        $this->ossCdnDomain = $ossCdnDomain;

        // 要转化的小程序appid
        $this->convertWeappId = $convertWeappId;
        // 小程序转化规则
        $this->weappToLinkRules = $weappToLinkRules;

        // 有赞店铺ID
        $this->youzanShopId = $youzanShopId;
        // 是否开启有赞销售员链接转化
        $this->enableYouzanSalesman = $enableYouzanSalesman;

        // 请求客户端
        $this->client = new Client();
    }

    /**
     * 爬取内容
     *
     * @param string $url 文章url或本地路径
     * @return $this
     * @throws InvalidParamsException
     */
    public function crawl($url)
    {
        if (URL::isValidUrl($url)) {
            try {
                // 微信文章
                $html = $this->client->get($url)->getBody()->getContents();
            } catch (\Exception $e) {
                throw new InvalidParamsException('crawl url is invalid');
            }
        } elseif (Storage::disk($this->articlesDisk)->exists($url)) {
            // 本地文件
            $html = Storage::disk($this->articlesDisk)->get($url);
        } else {
            throw new InvalidParamsException('crawl file does not exist in articles disk');
        }

        // 文章实例
        $this->crawler = new Crawler($html);

        return $this;
    }

    /**
     * 获取OSS客户端实例
     *
     * @param string $bucket oss bucket
     * @return OSS
     */
    public function ossClient($bucket)
    {
        if ($this->oss instanceof OSS) {
            $this->oss->setBucket($bucket);
        } else {
            // 生产环境下使用内网地址
            $clientEp = $this->isEnvProduction ? $this->ossInternalEndPoint : $this->ossEndPoint;
            $client  = new OssClient($this->ossAccessId, $this->ossAccessSecret, $clientEp);
            $client->setUseSSL = true;
            $this->oss =  new OSS($client, $bucket, $this->ossEndPoint, $this->ossInternalEndPoint);
        }

        return $this->oss;
    }

    /**
     * 获取OSS URL
     *
     * @param string $object
     * @param string $bucket
     */
    public function ossUrl($object, $bucket)
    {
        if (empty($object)) {
            return '';
        }
        // cdn地址和外网地址
        if ($this->ossCdnDomain) {
            $host = rtrim($this->ossCdnDomain, '/');
            $host = $host.'/'.ltrim($object, '/');
        } else {
            $host = $this->ossEndPoint;
            $host = rtrim($host, '/');
            $host = $bucket.'.'.$host.'/'.ltrim($object, '/');
        }
        return 'https://'.$host;
    }

    /**
     * 获取处理对象
     *
     * @param string $selector
     * @return $this
     */
    public function process($selector = 'body')
    {
        if (!$this->crawler) {
            throw new InvalidParamsException('crawler does not exist');
        }

        $this->processElement = $this->crawler->filter($selector);

        return $this;
    }

    /**
     * 并发请求
     *
     * @param array $urls 请求地址
     * @param string $keyPrefix 结果键值前缀
     * @return array 请求结果
     */
    public function promise($urls, $keyPrefix = '')
    {
        // 构建请求对象
        $reqs = [];
        foreach ($urls as $k => $url) {
            if ($url) {
                $reqs["{$keyPrefix}{$k}"] = new Request('GET', $url);
            }
        }

        // 没有请求对象
        if (empty($reqs)) {
            return [];
        }

        // 响应结果
        $res = [];

        // 构建请求池
        $pool = new Pool($this->client, $reqs, [
            'concurrency' => 10,
            'fulfilled' => function ($response, $index) use (&$res) {
                $res[$index] = $response;
            },
            'rejected' => function ($reason, $index) {
                Log::error($reason);
            }
        ]);

        try {
            // 等待所有请求结束
            $pool->promise()->wait();
        } catch (\Exception $e) {
            throw new HttpException($e->getMessage());
        }

        return $res;
    }

    /**
     * 保存资源
     *
     * @param string $contentType 响应Header中Content-Type值
     * @param mixed $contents 资源数据
     * @param string $dir 储存的目录，相对于articlesAssetsDisk
     * @param bool $sortByDate 资源储存是否加上时间目录
     * @return string 资源URL
     */
    public function saveFile($contentType, $contents, $dir = '', $sortByDate = true)
    {
        // 获取扩展名
        $ext = (MimeTypes::getDefault()->getExtensions($contentType))[0];

        // 文件名
        $filename = Str::random(4).uniqid().'.'.$ext;

        // 路径
        $filepath = trim($dir, '/').'/';
        if ($sortByDate) {
            $date = Carbon::today()->toDateString();
            $filepath .= $date.'/';
        }
        $filepath .= $filename;

        // oss路径
        $matches = [];
        if (preg_match('/^oss:(.+):(.+)$/', $this->articlesAssetsDisk, $matches) > 0) {
            $bucket = $matches[1];
            $object = trim($matches[2], '/').'/'.$filepath;

            try {
                $res = $this->ossClient($bucket)->put($object, $contents);
            } catch(OSSException $e) {
                $errmsg = $e->getMessage();
                throw new HttpException("{$this->articlesAssetsDisk} put content failed: {$errmsg}");
            }

            return $this->ossUrl($res['path'], $bucket);
        }

        // 保存本地
        Storage::disk($this->articlesAssetsDisk)->put($filepath, $contents);

        return Storage::disk($this->articlesAssetsDisk)->url($filepath);
    }

    /**
     * 合并处理<head>中的<style>
     *
     * @return string 合并处理后的css
     */
    public function combineCss()
    {
        if (!$this->crawler) {
            throw new InvalidParamsException('crawler does not exist');
        }

        // head标签
        $head = $this->crawler->filter('head');

        // 本地css
        $css = $head->filter('style')->each(function(Crawler $node, $i){
            return trim($node->html());
        });

        // 远程css
        $cssUrls = $head->filter('link[rel=stylesheet]')->each(function(Crawler $node, $i){
            return Str::start($node->attr('href'), 'https:');
        });

        // 并发请求
        $res = $this->promise($cssUrls, 'css');

        // 远程css资源
        foreach ($res as $r) {
            $css[] = $r->getBody()->getContents();
        }

        // 合并css
        $css = implode('', $css);

        // 处理background路径
        $css = $this->formatStyleBackgroundImage($css);

        self::style($css);

        return $css;
    }

    /**
     * 将<img>替换为本地路径
     *
     * @return $this
     */
    public function formatImages()
    {
        if (!$this->processElement) {
            throw new InvalidParamsException('process element does not exist');
        }

        // 图片Url
        $bodyImagesUrls = $this->processElement->filter('img')->each(function(Crawler $node, $i){
            $src1 = $node->attr('data-src');
            $src2 = $node->attr('src');
            return $src2 ?: ($src1 ?: NULL);
        });

        // 并发请求
        $keyPrefix = 'image';
        $res = $this->promise($bodyImagesUrls, $keyPrefix);

        // 图片资源
        $bodyImagesUrls = [];
        foreach ($res as $k => $r) {
            // 保存到本地
            $contentType = ($r->getHeader('Content-Type'))[0];
            $contents = $r->getBody()->getContents();
            $bodyImagesUrls[$k] = $this->saveFile($contentType, $contents, 'images');
        }

        // 替换路径
        $this->processElement->filter('img')->each(function(Crawler $node, $i) use ($bodyImagesUrls, $keyPrefix){
            $key = "{$keyPrefix}{$i}";
            if (array_key_exists($key, $bodyImagesUrls)) {
                $img = $node->getNode(0);
                $img->removeAttribute('data-src');
                $img->setAttribute('src', $bodyImagesUrls[$key]);
            }
        });

        return $this;
    }

    /**
     * 将css中background或background-image替换为本地路径
     *
     * @param string $subject 要处理的html
     * @return string 处理后的html
     */
    public function formatStyleBackgroundImage($subject)
    {
        // 匹配背景图片
        $pattern = '/(background|background-image):\s*url\("?((https:|http:)?\/\/.+)"?\)/U';
        $matches = [];
        preg_match_all($pattern, $subject, $matches);

        // 并发请求
        $res = $this->promise($matches[2], 'image');

        // 图片资源
        $imagesUrls = [];
        foreach ($res as $k => $r) {
            // 保存到本地
            $contentType = ($r->getHeader('Content-Type'))[0];
            $contents = $r->getBody()->getContents();
            $imagesUrls[$k] = $this->saveFile($contentType, $contents, 'images');
        }

        // 路径替换正则表达
        $rpattern = [];
        // 替换路径
        $replacement = [];
        foreach ($matches[2] as $k => $image) {
            $key = "image{$k}";
            $prefix = $matches[1][$k].':url("';

            // 转译特殊字符
            $rawPattern = $matches[0][$k];
            $pattern = preg_replace(
                ['/\//U','/\?/U', '/\./U', '/\*/U', '/\+/U', '/\(/U', '/\)/U'],
                ['\/', '\?', '\.', '\*', '\+', '\(', '\)'],
                $rawPattern);

            $rpattern[] = '/'.$pattern.'/U';
            $replacement[] = array_key_exists($key, $imagesUrls) ?
                $prefix.$imagesUrls[$key].'")' : '';
        }

        return preg_replace($rpattern, $replacement, $subject);

    }

    /**
     * 处理文章插入的语音
     *
     * @return $this
     */
    public function formatMpVoice()
    {
        if (!$this->processElement) {
            throw new InvalidParamsException('process element does not exist');
        }

        // 获取音频信息
        $voices = $this->processElement->filter('mpvoice')->each(function($node,$i){
            $fileid = $node->attr('voice_encode_fileid');
            return $fileid;
        });

        // 提取url
        $voiceurls = [];
        $voiceKeyPrefix = 'voice';
        foreach ($voices as $k => $voice) {
            $voiceurls[] = 'https://res.wx.qq.com/voice/getvoice?mediaid='.$voice;
        }

        // 下载音频
        $res = $this->promise($voiceurls, $voiceKeyPrefix);
        $voiceLocalUrls = [];
        foreach ($res as $k => $r) {
            // 保存到本地
            $contentType = ($r->getHeader('Content-Type'))[0];
            $contents = $r->getBody()->getContents();
            $voiceLocalUrls[$k] = $this->saveFile($contentType, $contents, 'audio');
        }

        // 插入本地路径
        $this->processElement->filter('mpvoice')->each(function(Crawler $node,$i) use ($voiceKeyPrefix, $voiceLocalUrls){
            $key = "{$voiceKeyPrefix}{$i}";
            if (array_key_exists($key, $voiceLocalUrls)) {
                $node->getNode(0)->setAttribute('voice_local_url', $voiceLocalUrls[$key]);
            }
        });

        return $this;
    }

    /**
     * 处理文章插入的音乐
     *
     * @return $this
     */
    public function formatQQMusic()
    {
        if (!$this->processElement) {
            throw new InvalidParamsException('process element does not exist');
        }

        // 获取音频信息
        $musics = $this->processElement->filter('qqmusic')->each(function($node,$i){
            $albumurl = $node->attr('albumurl');
            $audiourl = $node->attr('audiourl');
            return compact(['albumurl','audiourl']);
        });

        // 提取url
        $albumurls = [];
        $albumKeyPrefix = 'album';
        $audiourls = [];
        $audioKeyPrefix = 'audio';
        foreach ($musics as $k => $music) {
            $albumurls[] = $music['albumurl'];
            $audiourls[] = $music['audiourl'];
        }

        // 下载音频
        $res = $this->promise($audiourls, $audioKeyPrefix);
        $audioLocalUrls = [];
        foreach ($res as $k => $r) {
            // 保存到本地
            $contentType = ($r->getHeader('Content-Type'))[0];
            $contents = $r->getBody()->getContents();
            $audioLocalUrls[$k] = $this->saveFile($contentType, $contents, 'audio');
        }

        // 下载封面
        $res = $this->promise($albumurls, $albumKeyPrefix);
        $albumLocalUrls = [];
        foreach ($res as $k => $r) {
            // 保存到本地
            $contentType = ($r->getHeader('Content-Type'))[0];
            $contents = $r->getBody()->getContents();
            $albumLocalUrls[$k] = $this->saveFile($contentType, $contents, 'images');
        }

        // 插入本地路径
        $this->processElement->filter('qqmusic')->each(function($node, $i)
            use ($albumLocalUrls, $albumKeyPrefix, $audioLocalUrls, $audioKeyPrefix) {
            $audioKey = "{$audioKeyPrefix}{$i}";
            $albumKey = "{$albumKeyPrefix}{$i}";
            // 音频路径
            if (array_key_exists($audioKey, $audioLocalUrls)) {
                $node->getNode(0)->setAttribute('audio_local_url', $audioLocalUrls[$audioKey]);
            }
            // 封面路径
            if (array_key_exists($albumKey, $albumLocalUrls)) {
                $node->getNode(0)->setAttribute('album_local_url', $albumLocalUrls[$albumKey]);
            }
        });

        return $this;
    }

    /**
     * 处理文章插入的腾讯视频
     *
     * @return $this
     */
    public function formatTencentVideo()
    {
        if (!$this->processElement) {
            throw new InvalidParamsException('process element does not exist');
        }

        $coverurls = $this->processElement->filter('iframe.video_iframe')->each(function($node,$i){
            if ($node->attr('data-mpvid')) {
                // 隐藏微信原生视频
                $node->getNode(0)->setAttribute('style', 'display:none;');
                return '';
            } else {
                // 提取vid
                $src = $node->attr('data-src');
                $pattern = '/(&|\?)vid=(.+)&?/';
                $matches = [];

                // width,height,allowfullscreen,
                if (preg_match($pattern, $src, $matches) > 0) {
                    $src = "https://v.qq.com/txp/iframe/player.html?origin=https%3A%2F%2Fmp.weixin.qq.com&vid={$matches[2]}&autoplay=false&full=true&show1080p=false&isDebugIframe=false";
                    $node->getNode(0)->setAttribute('src', $src);
                }
                return urldecode($node->attr('data-cover'));
            }
        });

        // 下载封面
        $res = $this->promise($coverurls, 'cover');
        $coverLocalUrls = [];
        foreach ($res as $k => $r) {
            // 保存到本地
            $contentType = ($r->getHeader('Content-Type'))[0];
            $contents = $r->getBody()->getContents();
            $coverLocalUrls[$k] = $this->saveFile($contentType, $contents, 'images');
        }

        // 替换路径
        $this->processElement->filter('iframe.video_iframe')->each(function($node,$i) use ($coverLocalUrls){
            $key = "cover{$i}";
            if (array_key_exists($key, $coverLocalUrls)) {
                $node->getNode(0)->setAttribute('cover_local_url', $coverLocalUrls[$key]);
            }
        });

        return $this;
    }

    /**
     * 转换有赞相关链接为有赞分销员推广链接
     *
     * @param string $url 有赞相关的链接 在域名youzan.com下
     * @param bool|string $sl 是否在链接中加入分销员id，值为字符串时返回完整推广链接，为false时返回redirect_uri参数
     * @return string|bool $url 推广链接或redirect_uri参数，非有赞链接返回false
     */
    public function convertLinkToSalesman($url, $sl = false)
    {
        if (preg_match('/\.youzan\.com/', $url) > 0) {
            $link = $sl ? "https://h5.youzan.com/v2/trade/directsellerJump/jump?kdt_id={$this->youzanShopId}&sl={$sl}" : '';
            $schema = parse_url($url, PHP_URL_SCHEME) ?? 'http';
            $host = parse_url($url, PHP_URL_HOST) ?? '';
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $query = parse_url($url, PHP_URL_QUERY);
            if ($query) {
                $query .= $sl ? "&sls={$sl}" : "&sls=";
            } else {
                $query = $sl ? "sls={$sl}" : "sls=";
            }
            $url = "{$schema}://{$host}{$path}?{$query}";
            $url = urlencode($url);
            $link .= $sl ? "&redirect_uri={$url}" : $url;
            return $link;
        }

        return false;
    }

    /**
     * 转化小程序路径为普通链接
     *
     * @param string $path 小程序路径
     * @return string|bool $url 普通链接，不满足替换规则时返回false
     */
    public function convertWeappPathToLink($path)
    {
        foreach ($this->weappToLinkRules as $rule) {
            // 小程序path匹配规则
            $pattern = $rule['pattern'];
            // 替换链接
            $link = $rule['link'];

            $matches = [];
            if (preg_match($pattern, $path, $matches) > 0) {
                $len = count($matches);
                if ($len > 1) {
                    /* 提取小程序path中的参数 */
                    $rpattern = [];
                    $replacement = [];
                    // 参数数量
                    $pairs = floor(($len - 1) / 2);
                    for ($pair = 0;$pair < $pairs;$pair++) {
                        // 参数名
                        $keyIndex = ($pair * 2) + 1;
                        $key = $matches[$keyIndex];
                        // 参数值
                        $valueIndex = $keyIndex + 1;
                        $value = $matches[$valueIndex];
                        // 替换匹配
                        $rpattern[] = '/\{'.$key.'\}/';
                        // 替换值
                        $replacement[] = $value;
                    }
                    // 提换链接中的参数表达{...}
                    $url = preg_replace($rpattern, $replacement, $link);
                } else {
                    /* 无需提取小程序path中的参数 */
                    $url = $link;
                }
                return $url;
            }
        }

        return false;
    }

    /**
     * 处理文章中的链接
     *
     * @return $this
     */
    public function formatLink()
    {
        if (!$this->processElement) {
            throw new InvalidParamsException('process element does not exist');
        }

        $this->processElement->filter('a')->each(function($node, $i){
            $url = trim($node->attr('href'));
            $class = trim($node->attr('class'));

            if ($this->enableYouzanSalesman && preg_match('/\.youzan\.com/', $url) > 0) {
                /* 转化普通链接为分销员链接 */
                $url = $this->convertLinkToSalesman($url);
                $class .= ' nldou_salesman_link';
                // dom处理
                $dom = $node->getNode(0);
                $dom->setAttribute('target', '');
                $dom->setAttribute('href', 'javascript:void(0);');
                $dom->setAttribute('class', $class);
                $dom->setAttribute('nldou-salesman-redirect-uri', $url);
            } elseif (in_array($class, ['weapp_image_link', 'weapp_text_link'])) {
                /* 转化小程序 */

                // 小程序appid
                $appid = trim($node->attr('data-miniprogram-appid'));

                if ($appid === $this->convertWeappId) {
                    // 小程序路径
                    $path = trim($node->attr('data-miniprogram-path'));
                    // 转化为普通链接
                    $url = $this->convertWeappPathToLink($path);

                    if ($url) {
                        $dom = $node->getNode(0);
                        // 转化为分销员链接
                        if ($this->enableYouzanSalesman && $target = $this->convertLinkToSalesman($url)) {
                            $class .= ' nldou_weapp_salesman_link';
                            // dom处理
                            $dom->setAttribute('href', 'javascript:void(0);');
                            $dom->setAttribute('class', $class);
                            $dom->setAttribute('nldou-salesman-redirect-uri', $target);
                        } else {
                            $class .= ' nldou_weapp_link';
                            // dom处理
                            $dom->setAttribute('href', $url);
                            $dom->setAttribute('class', $class);
                        }
                    }
                }
            }
        });

        return $this;
    }

    /**
     * 渲染<body>
     *
     * @return string <body>
     */
    public function renderBody()
    {
        if ($this->processElement->nodeName() !== 'body') {
            throw new InvalidParamsException('process element is not <body>');
        }

        $bodyClass = $this->processElement->attr('class');
        $bodyId = $this->processElement->attr('id');

        // 过滤body
        $bodyChildren = $this->processElement->children()->each(function(Crawler $node, $i){
            $dom = $node->getNode(0);
            return $node->attr('class') === 'rich_media' ? $dom->ownerDocument->saveHTML($dom) : '';
        });
        $bodyChildren = implode('', $bodyChildren);

        $body = <<<BODY
        <body id="$bodyId" class="$bodyClass">$bodyChildren</body>
BODY;

        $body = $this->formatStyleBackgroundImage($body);

        self::body($body);

        return $body;
    }

    /**
     * 渲染文章模板
     *
     * @param string $title 文章标题
     * @param string $description 文章摘要
     * @return \Illuminate\View\View 文章模板
     */
    public function render($url, $title, $description)
    {
        $this->crawl($url);

        $this->combineCss();

        $this->process('body')->formatImages()->formatMpVoice()
            ->formatQQMusic()->formatTencentVideo()->formatLink()
            ->renderBody();

        $script = '
            /* 文章题目 */
            $(\'#activity_name\').html(\'{{$title}}\');
            $(\'title\').html(\'{{$title}}\');
            /* 发布时间 */
            $(\'#publish_time\').html(\'{{$publishTime}}\');
            /* 阅读人数 */
            $(\'#js_read_area3\').show();
            $(\'#readNum3\').html(\'{{$readNum}}\')
            /* 阅读原文 */
            if ($(\'#js_view_source\').length > 0) {
                $(\'#js_view_source\').html(\'{{$viewSourceText}}\');
                $(\'#js_view_source\').attr(\'href\', \'{!!$viewSourceUrl!!}\');
            } else {
                var html = \'<a class="media_tool_meta meta_primary" id="js_view_source" href="{!!$viewSourceUrl!!}">{{$viewSourceText}}</a>\';
                $(\'#js_read_area3\').before(html);
            }
            /* 跳转有赞链接 */
            var sls = \'{{$sls}}\';
            $(\'.nldou_salesman_link, .nldou_weapp_salesman_link\').click(function(){
                var redirectUri = $(this).attr(\'nldou-salesman-redirect-uri\');
                window.location.href = \'https://h5.youzan.com/v2/trade/directsellerJump/jump?kdt_id='.$this->youzanShopId.'&sl=\'+sls+\'&redirect_uri=\'+redirectUri+sls;
            });
            /* 微信配置 */
            wx.config(\'{!!$jssdk!!}\')
            wx.ready(function(){
                wx.updateAppMessageShareData({
                    title: \'{{$title}}\',
                    desc: \'{{$description}}\',
                    link: \'{{$shareUrl}}\',
                    imgUrl: \'{{$shareImageUrl}}\'
                });
                wx.updateTimelineShareData({
                    title: \'{{$title}}\',
                    link: \'{{$shareUrl}}\',
                    imgUrl: \'{{$shareImageUrl}}\'
                });
                wx.hideMenuItems({
                    menulist: [\'menuItem:share:qq\',\'menuItem:share:weiboApp\',\'menuItem:share:facebook\',\'menuItem:share:QZone\',
                        \'menuItem:copyUrl\', \'menuItem:openWithQQBrowser\', \'menuItem:openWithSafari\', \'menuItem:share:email\']
                })
            })';

        self::script($script);

        return view('wxarticles::article' , compact(['title', 'description']));
    }

    /**
     * 保存文章模板
     *
     * @param string $title 文章标题
     * @param string $description 文章摘要
     * @return string 文章模板路径
     */
    public function save($url, $title, $description)
    {
        // 文章模板
        $view = $this->render($url, $title, $description);

        // 文件名
        $alias = Str::random(8);
        $filename = $alias.'.blade.php';

        // 保存文章模板
        Storage::disk($this->articlesDisk)->put($filename, $view->render());

        return $alias;
    }
}
