<style>
    #s3-importer-progress {
        padding: 24px;
        background: #ddd;
        border-radius: 8px;
    }

    #s3-importer-progress > button {
        margin-top: 20px;
    }

    .s3-importer-progress-container {
        position: relative;
        width: 100%;
        height: 32px;
        background: #AAA;
        border-radius: 16px;
        overflow: hidden;
    }

    #s3-importer-progress-bar {
        background-color: #3a84e6;
        height: 100%;
    width: {{$progress}}%;
    }

    .tool-disabled {
        padding: 10px 15px;
        border: 1px solid #df8403;
    }

    .force-cancel-help {
        margin-top: 20px;
    }

    .wp-cli-callout {
        padding: 10px;
        background: #ddd;
        margin-top: 20px;
        border-radius: 8px;
    }

    .wp-cli-callout > h3 {
        margin: 0; padding: 0;
        font-size: 14px;
    }

    .wp-cli-callout > code {
        background-color: #bbb;
        padding: 5px;
    }

    #s3-timing-stats {
        display: none;
    }

    #s3-importer-status-text {
        position: absolute;
        left: 16px; top:0px; bottom: 0px; right: 16px;
        display: flex;
        align-items: center;
        color: white;
        font-weight: bold;
    }

    #s3-importer-thumbnails {
        position: relative;
        width: 100%;
        height: 150px;
        margin-bottom: 15px;
    }

    #s3-importer-thumbnails-container {
        display: flex;
        position: absolute;
        left: 0px; top:0px; right: 0px; bottom:0px;
        overflow: hidden;
    }

    #s3-importer-thumbnails-container > img {
        margin-right: 10px;
    }

    #s3-importer-thumbnails-fade {
        background: -moz-linear-gradient(left, rgba(221,221,221,0) 0%, rgba(221,221,221,1) 95%, rgba(221,221,221,1) 100%); /* FF3.6-15 */
        background: -webkit-linear-gradient(left, rgba(221,221,221,0) 0%,rgba(221,221,221,1) 95%,rgba(221,221,221,1) 100%); /* Chrome10-25,Safari5.1-6 */
        background: linear-gradient(to right, rgba(221,221,221,0) 0%,rgba(221,221,221,1) 95%,rgba(221,221,221,1) 100%); /* W3C, IE10+, FF16+, Chrome26+, Opera12+, Safari7+ */

        position: absolute;
        left: 150px; top:0px; right: 0px; bottom:0px;
    }
</style>
<div class="settings-container">
    <header>
        <img src="{{ILAB_PUB_IMG_URL}}/icon-cloud.svg">
        <h1>{{$title}}</h1>
    </header>
    <div class="settings-body">
        <div id="s3-importer-manual-warning" style="display:none">
            <p><strong>IMPORTANT:</strong> You are running the import process in the web browser.  <strong>Do not navigate away from this page or the import may not finish.</strong></p>
        </div>
        <div id="s3-importer-instructions" {{($status=="running") ? 'style="display:none"':''}}>
            {{$instructions}}
            <div class="wp-cli-callout">
                <h3>Using WP-CLI</h3>
                <p>You can run this importer process from the command line using WP-CLI:</p>
                <code>
                    {{$commandLine}}
                </code>
            </div>
            <div style="margin-top: 2em;">
                <?php if($enabled): ?>
                    <a id="s3-importer-start-import" href="#" class="ilab-ajax button button-primary">{{$commandTitle}}</a>
                <?php else: ?>
                    <strong class="tool-disabled">Please <a href="admin.php?page=media-tools-top">{{$disabledText}}</a> before using this tool.</strong>
                <?php endif ?>
            </div>
        </div>
        <div id="s3-importer-progress" {{($status!="running") ? 'style="display:none"':''}}>
            <div id="s3-importer-progress-text">
                <p id="s3-importer-cancelling-text" style="display:{{($shouldCancel) ? 'block':'none'}}">Cancelling ... This may take a minute ...</p>
            </div>
            <div id="s3-importer-thumbnails">
                <div id="s3-importer-thumbnails-container">
                </div>
                <div id="s3-importer-thumbnails-fade"></div>
            </div>
            <div class="s3-importer-progress-container">
                <div id="s3-importer-progress-bar"></div>
                <div id="s3-importer-status-text" style="visibility:{{($shouldCancel) ? 'hidden':'visible'}}">
                    <div>Processing '<span id="s3-importer-current-file">{{$currentFile}}</span>' (<span id="s3-importer-current">{{$current}}</span> of <span id="s3-importer-total">{{$total}}</span>).  <span id="s3-timing-stats"><span id="s3-timing-ppm">{{number_format($postsPerMinute, 1)}}</span> posts per minute, ETA: <span id="s3-timing-eta">{{number_format($eta, 2)}}</span>.</span></div>
                </div>
            </div>
            <button id="s3-importer-cancel-import" class="button button-warning" title="Cancel">{{$cancelCommandTitle}}</button>
        </div>
    </div>
</div>
<script>
    (function($){
        $(document).ready(function(){
            var importing={{($status == 'running') ? 'true' : 'false'}};
            var totalIndex = -1;
            var currentIndex = -1;
            var currentPage = 1;
            var totalPages = {{$pages}};
            var totalItems = {{$total}};
            var manualStart = 0;

            var displayedThumbs = [];

            const backgroundImport = {{ ($background) ? 'true' : 'false' }};
            var postsToImport = {{ json_encode($posts, JSON_PRETTY_PRINT) }};

            const displayNextThumbnail = function(thumbUrl) {
                if (displayedThumbs.length > 0) {
                    if (displayedThumbs[displayedThumbs.length - 1].attr('src') == thumbUrl) {
                        return;
                    }
                }

                const image = $('<img src="'+thumbUrl+'">');
                image.hide().prependTo('#s3-importer-thumbnails-container').fadeIn();
                displayedThumbs.push(image);
                if (displayedThumbs.length >= 20) {
                    var firstImage = displayedThumbs.shift();
                    firstImage.remove();
                    console.log(displayedThumbs.length);
                }
                // $('#s3-importer-thumbnails-container').prepend(image);
            }

            const nextBatch = function(callback) {
                if (!importing) {
                    return;
                }

                currentPage++;
                if (currentPage > totalPages) {
                    callback(false);
                    return;
                }

                const data={
                    action: '{{$nextBatchAction}}',
                    page: currentPage
                };

                $.post(ajaxurl,data,function(response){
                    postsToImport = response.posts;
                    currentIndex = 0;

                    callback((postsToImport.length >  0));
                });

            };

            const importNextManual = function () {
                if (!importing) {
                    return;
                }

                currentIndex++;
                if (currentIndex == postsToImport.length) {
                    nextBatch(function(success){
                        if (success) {
                            importNextManual();
                        } else {
                            importing = false;
                            $('#s3-importer-instructions').css({display: 'block'});
                            $('#s3-importer-progress').css({display: 'none'});
                            $('#s3-importer-manual-warning').css('display', 'none');
                        }
                    });

                    return;
                }

                totalIndex++;

                displayNextThumbnail(postsToImport[currentIndex].thumb);

                $('#s3-importer-status-text').css({'visibility':'visible'});
                $('#s3-importer-current').text((totalIndex + 1));
                $('#s3-importer-current-file').text(postsToImport[currentIndex].title);
                $('#s3-importer-total').text(totalItems);

                const progress = Math.min(100, ((totalIndex + 1) / totalItems) * 100);
                $('#s3-importer-progress-bar').css({width: progress+'%'});

                const data={
                    action: '{{$manualAction}}',
                    post_id: postsToImport[currentIndex].id
                };

                $.post(ajaxurl,data,function(response){
                    if (response.status == 'error') {
                        document.location.reload();
                    }

                    const totalTime = (performance.now() - manualStart) / 1000.0;
                    const postsPerSecond = totalTime / (totalIndex + 1);
                    const postsPerMinute = 60 / postsPerSecond;
                    const eta = (totalItems - (totalIndex + 1)) / postsPerMinute;

                    $('#s3-timing-stats').css({display: 'inline-block'});
                    $('#s3-timing-ppm').text(postsPerMinute.toFixed(1));

                    const date = new Date();
                    date.setSeconds(date.getSeconds() + (eta * 60.0));

                    $('#s3-timing-eta').text(date.toLocaleTimeString());

                    importNextManual();
                });

            };

            const startImport = function() {
                if (importing) {
                    return false;
                }

                if (currentPage > 1) {
                    currentPage = 0;
                    nextBatch(function(success){
                        if (success) {
                            startImport();
                        } else {
                            return;
                        }
                    });

                    return;
                }

                currentIndex = -1;
                totalIndex = -1;
                importing=true;
                displayedThumbs = [];
                $('#s3-importer-thumbnails-container').empty();

                if (backgroundImport) {
                    const data={
                        action: '{{$startAction}}'
                    };

                    $.post(ajaxurl,data,function(response){
                        if (response.status == 'error') {
                            document.location.reload();
                        }

                        if (response.status == 'running') {
                            $('#s3-importer-cancel-import').attr('disabled', false);
                            $('#s3-importer-cancelling-text').css({'display':'none'});
                            $('#s3-importer-status-text').css({'visibility':'visible'});

                            $('#s3-importer-instructions').css({display: 'none'});
                            $('#s3-importer-progress').css({display: 'block'});

                            displayNextThumbnail(response.first.thumb);

                            totalItems = response.total;
                            $('#s3-importer-status-text').css({'visibility':'visible'});
                            $('#s3-importer-current').text(1);
                            $('#s3-importer-current-file').text(response.first.title);
                            $('#s3-importer-total').text(totalItems);

                            setTimeout(checkStatus, 3000);
                        }
                    });
                } else {
                    manualStart = performance.now();

                    $('#s3-importer-manual-warning').css('display', 'block');
                    $('#s3-importer-progress-bar').css({width: '0%'});
                    $('#s3-importer-status-text').css({'visibility':'hidden'});

                    $('#s3-importer-cancel-import').attr('disabled', false);
                    $('#s3-importer-cancelling-text').css({'display':'none'});

                    $('#s3-importer-instructions').css({display: 'none'});
                    $('#s3-importer-progress').css({display: 'block'});

                    importNextManual();
                }
            };

            const cancelImport = function () {
                if (backgroundImport) {
                    var data={
                        action: '{{$cancelAction}}'
                    };

                    $.post(ajaxurl,data,function(response){
                        $('#s3-importer-cancelling-text').css({'display':'block'});
                        $('#s3-importer-status-text').css({'visibility':'hidden'});
                        $('#s3-importer-cancel-import').attr('disabled', true);
                        console.log(response);
                    });
                } else {
                    importing = false;
                    $('#s3-importer-manual-warning').css('display', 'none');
                    $('#s3-importer-instructions').css({display: 'block'});
                    $('#s3-importer-progress').css({display: 'none'});
                }
            };

            const checkStatus = function() {
                if (importing) {
                    const data={
                        action: '{{$progressAction}}'
                    };

                    $.post(ajaxurl,data,function(response){
                        if (response.shouldCancel) {
                            $('#s3-importer-cancelling-text').css({'display':'block'});
                            $('#s3-importer-status-text').css({'visibility':'hidden'});
                        } else {
                            $('#s3-importer-cancelling-text').css({'display':'none'});
                            $('#s3-importer-status-text').css({'visibility':'visible'});
                        }

                        if (response.status != 'running') {
                            importing = false;
                            $('#s3-importer-instructions').css({display: 'block'});
                            $('#s3-importer-progress').css({display: 'none'});
                        } else {
                            if (response.total > 0) {
                                var progress = (response.current / response.total) * 100;
                                $('#s3-importer-progress-bar').css({width: progress+'%'});
                            }

                            if (response.thumb != null) {
                                displayNextThumbnail(response.thumb);
                            }

                            $('#s3-timing-stats').css({display: 'inline-block'});

                            $('#s3-importer-current').text(response.current);
                            $('#s3-importer-current-file').text(response.currentFile);
                            $('#s3-importer-total').text(response.total);
                            $('#s3-timing-ppm').text(parseFloat(response.postsPerMinute).toFixed(1));

                            var date = new Date();
                            date.setSeconds(date.getSeconds() + (parseFloat(response.eta) * 60.0));

                            $('#s3-timing-eta').text(date.toLocaleTimeString());
                        }
                    });
                }

                setTimeout(checkStatus, 3000);
            };

            $('#s3-importer-start-import').on('click',function(e){
                e.preventDefault();

                startImport();

                return false;
            });


            $('#s3-importer-cancel-import').on('click', function(e){
                e.preventDefault();

                if (confirm("Are you sure you want to cancel?")) {
                    cancelImport();
                }

                return false;
            });

            if (importing) {
                checkStatus();
            }
        });
    })(jQuery);
</script>