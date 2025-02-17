<div id="mcloud-simple-modal-{{$modalId}}" class="mcloud-simple-modal mcloud-replace-file-modal">
    <div class="mcloud-simple-modal-container">
        <div class="mcloud-simple-modal-title">
            <h1>Replace Image</h1>
            <a title="{{__('Close')}}" class="mcloud-simple-modal-close">
                <span class="ilabm-modal-icon"></span>
            </a>
        </div>
        <div class="mcloud-simple-modal-contents">
            <div class="mcloud-simple-modal-interior">
                <div class="mcloud-file-preview" style="display:none">
                </div>
                <div class="mcloud-form-row mcloud-file-picker">
                    <label for="mcloud-selected-file">
                        <span class="selected-file-text">Select file ...</span>
                        <span class="button button-primary select-button">Select File</span>
                    </label>
                    <input id="mcloud-selected-file" type="file" accept="image/png, image/jpeg, image/tiff, image/gif">
                </div>
                <div class="mcloud-form-row mcloud-upload-progress" style="display:none">
                    <div class="progress-text">Uploading ...</div>
                    <div class="progress-bar">
                        <div class="progress-bar-interior" style="width: 60%"></div>
                    </div>
                </div>
            </div>
            <div class="mcloud-form-button-row">
                <button disabled="disabled" type="button" class="button button-primary mcloud-start-upload">Start Upload</button>
            </div>
        </div>
    </div>
</div>
