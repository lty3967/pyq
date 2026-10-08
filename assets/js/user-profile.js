// 危险操作确认（解绑快捷登录等）。独立成一个 IIFE：下面头像逻辑在缺少
// 编辑器时会提前 return，这段若放在其后就会跟着一起被跳过。
(function () {
    'use strict';
    document.addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-confirm]');
        if (!el) { return; }
        if (!window.confirm(el.getAttribute('data-confirm'))) {
            ev.preventDefault();
            ev.stopPropagation();
        }
    });
}());

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

    // 用户中心位于 /user/ 下，本地相对路径需回到站点根；云存储地址原样使用
    function mediaUrl(p) {
        p = String(p || '');
        if (p === '' || /^(https?:)?\/\//i.test(p) || p.charAt(0) === '/') { return p; }
        return '../' + p;
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
                    preview.src = mediaUrl(result.data.path);
                } else {
                    var image = document.createElement('img');
                    image.id = 'avatarPreview';
                    image.alt = '当前头像';
                    image.src = mediaUrl(result.data.path);
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
