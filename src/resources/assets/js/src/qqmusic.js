(function(){

    // 生成播放器
    $('qqmusic').each((index, el) => {
        // 隐藏
        $(el).hide()

        const url = $(el).attr('audio_local_url') || ''
        const album = $(el).attr('album_local_url') || ''
        const musicid = $(el).attr('musicid')
        const name = $(el).attr('music_name')
        const singer = $(el).attr('singer')

        // 播放器
        const html = `<div class="db qqmusic_area qqmusic_player">
                <div class="db qqmusic_wrp appmsg_card_context appmsg_card_active">
                    <div class="db qqmusic_bd">
                        <div class="play_area" musicid="${musicid}" audiourl="${url}">
                            <i class="icon_qqmusic_switch"></i>
                            <img src="/vendor/wxarticles/images/icon_qqmusic_default.png" alt="" class="pic_qqmusic_default">
                            <img src="${album}" data-musicid="36999927" class="qqmusic_thumb" alt="">
                        </div>
                        <a class="access_area">
                            <div class="qqmusic_songname">${name}</div>
                            <div class="qqmusic_singername">${singer}</div>
                        </a>
                    </div>
                </div>
            </div>`

        // 插入dom
        $(el).after(html)
    })

    // 播放器实例
    let qqmusic = {}

    $('.qqmusic_player .play_area').click(function(){

        // 播放开关
        const self = $(this)
        // 播放器
        const container = self.parent().parent()

        const musicid = self.attr('musicid')
        const url = self.attr('audiourl')

        // 首次点击
        if (!qqmusic[musicid]) {
            // 加载音频
            qqmusic[musicid] = new Howl({
                src: [url],
                onplay: function(){
                    // 开始播放
                    container.addClass('qqmusic_playing')
                },
                onpause: function(){
                    // 暂停播放
                    container.removeClass('qqmusic_playing')
                },
                onend: function(){
                    // 暂停播放
                    container.removeClass('qqmusic_playing')
                }
            })
        }

        qqmusic[musicid].playing() ? qqmusic[musicid].pause() : qqmusic[musicid].play()

    })
})();
