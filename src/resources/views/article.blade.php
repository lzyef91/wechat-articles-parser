<!DOCTYPE html>
<html lang="zh-CN">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=0,viewport-fit=cover">
        <link rel="shortcut icon" type="image/x-icon" href="{{Parser::favicon()}}">
        <link rel="mask-icon" href="//res.wx.qq.com/a/wx_fed/assets/res/MjliNWVm.svg" color="#4C4C4C">
        <link rel="apple-touch-icon-precomposed" href="{{Parser::favicon()}}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black">
        <meta name="format-detection" content="telephone=no">

        <meta name="description" content="{{$description}}">
        <meta name="author" content="能量逗">

        <title>{{$title}}</title>

        {!! Parser::headerCss() !!}

        {!! Parser::style() !!}

        {!! Parser::headerJs() !!}
    </head>

    {!! Parser::body() !!}

    {!! Parser::footerJs() !!}

    {!! Parser::script() !!}
</html>
