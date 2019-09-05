<?php

namespace Nldou\WechatArticlesParser\Facades;

use Illuminate\Support\Facades\Facade;

class Parser extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Nldou\WechatArticlesParser\Parser::class;
    }
}
