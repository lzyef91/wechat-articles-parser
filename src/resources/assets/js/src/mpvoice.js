(function(){

    let sec2timestr = function (sec) {
        let min = Math.floor(sec / 60).toString()
        if (min.length < 2) {
            min = '0' + min
        }
        sec = (sec % 60).toString()
        if (sec.length < 2) {
            sec = '0' + sec
        }
        return `${min}:${sec}`
    }

    // 生成播放器
    $('mpvoice').each((index, el) => {
        // 隐藏
        $(el).hide()

        const url = $(el).attr('voice_local_url')
        const name = $(el).attr('name')
        const fileid = $(el).attr('voice_encode_fileid')

        // 计算音频时长
        const ms = parseInt($(el).attr('play_length'))
        const durSec = Math.round(ms / 1000)
        const durStr = sec2timestr(durSec)

        // 播放器
        const html = `<div class="js_audio_frame db mpvoice-player">
            <div aria-labelledby="语音" class="share_audio_context flex_context pages_reset">
                <div aria-labelledby="播放开关" class="db share_audio_switch" voice_encode_fileid="${fileid}" voice_local_url="${url}" voice_duration="${durSec}">
                    <em class="icon_share_audio_switch" role="button"></em>
                </div>
                <div class="share_audio_info flex_bd db">
                    <strong class="share_audio_title" aria-describedby="语音标题" role="link">${name}</strong>
                    <div class="share_audio_tips db">来自能量逗</div>
                    <div class="db share_audio_progress_wrp">
                        <div class="db share_audio_progress">
                            <div style="width:0%;" class="share_audio_progress_inner"></div>
                            <div class="share_audio_progress_buffer" style="width:0%;"></div>
                            <div class="share_audio_progress_loading" style="display:none;">
                                <div class="share_audio_progress_loading_inner"></div>
                            </div>
                        </div>
                        <div class="share_audio_progress_handle" style="display:none;left:0%;"></div>
                    </div>
                    <div class="share_audio_desc db" aria-labelledby="时长">
                        <em class="share_audio_length_current" aria-hidden="true">00:00</em>
                        <em class="share_audio_length_total">${durStr}</em>
                    </div>
                </div>
            </div>
        </div>`

        // 插入dom
        $(el).after(html)
    })

    // 播放器实例
    let mpvoice = {}
    // 计时器实例
    let timer = {}
    // 计时标记
    let time = {}

    let startTimer = function (fileid, dur, progress, cursor, curlabel) {
        // 首次开始
        if (!time[fileid]) time[fileid] = 0
        // 设置timer
        timer[fileid] = setInterval(function () {
            time[fileid]++
            // 当前时间
            const cur = sec2timestr(time[fileid])
            curlabel.html(cur)
            // 播放进度
            const per = (time[fileid] / dur * 100).toString() + '%'
            progress.css('width', per)
            cursor.css('left', per)
        }, 1000)
    }

    let pauseTimer = function (fileid) {
        clearInterval(timer[fileid])
        timer[fileid] = null
    }

    let endTimer = function (fileid) {
        clearInterval(timer[fileid])
        timer[fileid] = null
        time[fileid] = 0
    }

    $('.mpvoice-player .share_audio_switch').click(function(){

        // 播放开关
        const self = $(this)
        // 播放器
        const container = self.parent().parent()
        // 缓冲条
        const buffer = container.find('.share_audio_progress_buffer')
        // 缓冲加载
        const loading = container.find('.share_audio_progress_loading')
        // 进度条
        const progress = container.find('.share_audio_progress_inner')
        // 进度游标
        const cursor = container.find('.share_audio_progress_handle')
        // 当前播放时间
        const curlabel = container.find('.share_audio_length_current')

        const fileid = self.attr('voice_encode_fileid')
        const url = self.attr('voice_local_url')
        const duration = self.attr('voice_duration')

        // 首次点击
        if (!mpvoice[fileid]) {
            // 显示加载条
            loading.show()
            // 加载音频
            mpvoice[fileid] = new Howl({
                src: [url],
                onload: function(){
                    // console.log('load')
                    // 隐藏加载条
                    loading.hide()
                    // 缓冲条
                    buffer.css('width', '100%')
                    // 显示进度游标
                    cursor.show()
                },
                onplay: function(){
                    // console.log('play')
                    startTimer(fileid, duration, progress, cursor, curlabel)
                    // 开始播放
                    container.addClass('share_audio_playing')
                },
                onpause: function(){
                    // console.log('pause')
                    pauseTimer(fileid)
                    // 暂停播放
                    container.removeClass('share_audio_playing')
                },
                onend: function(){
                    // console.log('end')
                    endTimer(fileid)
                    // 重置进度
                    progress.css('width', '0%')
                    cursor.css('left', '0%')
                    // 重置当前时间
                    curlabel.html('00:00')
                    // 暂停播放
                    container.removeClass('share_audio_playing')
                }
            })
        }

        mpvoice[fileid].playing() ? mpvoice[fileid].pause() : mpvoice[fileid].play()

    })
})();
