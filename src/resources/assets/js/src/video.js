(function(){
    // 页面宽度
    const pageWidth = $('.rich_media_area_primary_inner').innerWidth()
    // 调整视频iframe大小
    $('iframe.video_iframe').each(function(index, el) {
        const ratio = parseFloat($(el).attr('data-ratio'))
        const height = Math.round(pageWidth / ratio)
        $(el).attr('width', pageWidth)
        $(el).attr('height', height)
    })
})();
