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

class Parser
{
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

    public function __construct($articlesAssetsDisk, $articlesDisk,
        $ossAccessId, $ossAccessSecret, $isEnvProduction, $ossEndPoint, $ossInternalEndPoint, $ossCdnDomain = false)
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
        if (!$this->crawler instanceof Crawler) {
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
        if (!$this->crawler instanceof Crawler) {
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
        return $this->formatStyleBackgroundImage($css);
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
            // 提取vid
            $src = $node->attr('data-src');
            $pattern = '/(&|\?)vid=(.+)&?/';
            $matches = [];
            $times = preg_match($pattern, $src, $matches);

            // width,height,allowfullscreen,
            if ($times > 0) {
                $src = "https://v.qq.com/txp/iframe/player.html?origin=https%3A%2F%2Fmp.weixin.qq.com&vid={$matches[2]}&autoplay=false&full=true&show1080p=false&isDebugIframe=false";
                $node->getNode(0)->setAttribute('src', $src);
            }

            return urldecode($node->attr('data-cover'));
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
     * @return string $url 推广链接或redirect_uri参数
     */
    public static function processYouzanUrl($url, $sl = false)
    {
        $isYouzanUrl = preg_match('/\.youzan\.com/', $url);

        if ($url && $isYouzanUrl > 0) {
            $link = $sl ? "https://h5.youzan.com/v2/trade/directsellerJump/jump?kdt_id=18168297&sl={$sl}" : '';
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

        return $url;
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
            $url = $node->attr('href');
            $isYouzanUrl = preg_match('/\.youzan\.com/', $url);
            if ($isYouzanUrl > 0) {
                $url = static::processYouzanUrl($url);
                $class = $node->attr('class').' nldou_salesman_link';
                $dom = $node->getNode(0);
                $dom->setAttribute('href', 'javascript:void(0);');
                $dom->setAttribute('class', $class);
                $dom->setAttribute('nldou-salesman-redirect-uri', $url);
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

        return <<<BODY
        <body id="$bodyId" class="$bodyClass">$bodyChildren</id>
BODY;
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
        $css = $this->combineCss();

        $body = $this->crawl($url)->process('body')->formatImages()->formatMpVoice()
            ->formatQQMusic()->formatTencentVideo()->formatLink()
            ->renderBody();

        $body = $this->formatStyleBackgroundImage($body);

        $script = '<script type="text/javascript">
            // 文章题目
            $(\'#activity_name\').html(\'{{$title}}\');
            $(\'title\').html(\'{{$title}}\');
            // 发布时间
            $(\'#publish_time\').html(\'{{$publishTime}}\');
            // 阅读人数
            $(\'#js_read_area3\').show();
            $(\'#readNum3\').html(\'{{$readNum}}\')
            // 阅读原文
            if ($(\'#js_view_source\').length > 0) {
                $(\'#js_view_source\').html(\'{{$viewSourceText}}\');
                $(\'#js_view_source\').attr(\'href\', \'{!!$viewSourceUrl!!}\');
            } else {
                var html = \'<a class="media_tool_meta meta_primary" id="js_view_source" href="{!!$viewSourceUrl!!}">{{$viewSourceText}}</a>\';
                $(\'#js_read_area3\').before(html);
            }
            // 跳转有赞链接
            var sls = \'{{$sls}}\';
            $(\'.nldou_salesman_link\').click(function(){
                var redirectUri = $(this).attr(\'nldou-salesman-redirect-uri\');
                var href = \'https://h5.youzan.com/v2/trade/directsellerJump/jump?kdt_id=18168297&sl=\'+sls+\'&redirect_uri=\'+redirectUri+sls;
                window.open(href);
            });
        </script>';

        return view('salesman.article' , compact(['body', 'title', 'description', 'css', 'script']));
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
        $filename = Str::random(4).uniqid().'.blade.php';

        // 保存文章模板
        Storage::disk($this->articlesDisk)->put($filename, $view->render());

        return $filename;
    }
}
