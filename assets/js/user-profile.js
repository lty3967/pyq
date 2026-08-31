(function () {
    'use strict';

    var editor = document.querySelector('[data-avatar-editor]');
    if (!editor || !window.WM_USER) {
        return;
    }

    var input = editor.querySelector('[data-avatar-input]');
    var selectButton = editor.querySelector('[data-avatar-select]');
    var preview = editor.querySelector('#avatarPreview');

    if (!input || !selectButton || !preview) {
        return;
    }

    selectButton.addEventListener('click', function () {
        input.click();
    });

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) {
            return;
        }

        var formData = new FormData();
        formData.append('_token', window.WM_USER.token);
        formData.append('avatar', file);
        selectButton.disabled = true;
        selectButton.textContent = '上传中…';

        fetch('avatar_upload.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                if (!result.ok) {
                    throw new Error(result.msg || '头像上传失败');
                }

                if (preview.tagName.toLowerCase() === 'img') {
                    preview.src = '../' + result.data.path;
                } else {
                    var image = document.createElement('img');
                    image.id = 'avatarPreview';
                    image.alt = '当前头像';
                    image.src = '../' + result.data.path;
                    preview.replaceWith(image);
                    preview = image;
                }
            })
            .catch(function (error) {
                window.alert(error.message);
            })
            .finally(function () {
                selectButton.disabled = false;
                selectButton.textContent = '选择图片';
                input.value = '';
            });
    });
}());
