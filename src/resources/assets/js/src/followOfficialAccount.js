(function(){
    $('#js_name').click(function(){
        const ua = navigator.userAgent.toLowerCase()
        const reg = ua.match(/MicroMessenger/i)
        if ( reg && reg[0] == 'micromessenger' ) {
            // 微信环境
            window.location.href = 'https://mp.weixin.qq.com/mp/profile_ext?action=home&__biz=MzA3MTQ5ODAxNA==#wechat_redirect'
        } else {
            // 其他环境
            Swal.fire({
                title: '关注能量逗公众号',
                html: `<div><img src="/vendor/wxarticles/images/nldou_official_account_qrcode_258.jpg"></div>`,
                showCloseButton: true,
                showConfirmButton: false
            })
        }
    })
})()
